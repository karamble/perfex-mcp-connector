<?php

namespace PerfexMcp\Auth;

use PerfexMcp\Support\AuditLogger;
use PerfexMcp\Support\McpContext;

/**
 * Bearer-token authentication for the MCP endpoint.
 *
 * Tokens are shown to the operator once at creation; only their SHA-256 hash is
 * stored (the `key` column). On success we flip Perfex into "API mode" so core's
 * get_staff_user_id() resolves the token's staff member for every downstream
 * permission check and activity-log entry (see config/rest.php).
 */
final class KeyService
{
    private $CI;

    private string $table;

    public function __construct()
    {
        $this->CI    = &get_instance();
        $this->table = db_prefix() . 'mcp_connector_keys';
    }

    /**
     * Validate the incoming request's bearer token.
     *
     * @throws AuthException on missing/invalid/revoked/expired key or rate limit
     *
     * @return object the key row
     */
    public function authenticate(): object
    {
        $token = $this->extractToken();
        if ($token === null || $token === '') {
            throw new AuthException('Missing bearer token', 401);
        }

        $hash = hash('sha256', $token);

        $row = $this->CI->db->where('key', $hash)->get($this->table)->row();

        // Constant-time comparison even though the lookup is indexed by hash.
        if (! $row || ! hash_equals((string) $row->key, $hash)) {
            throw new AuthException('Invalid API key', 401);
        }

        if (! empty($row->revoked_at)) {
            throw new AuthException('API key has been revoked', 401);
        }

        if (! empty($row->expires_at) && strtotime($row->expires_at) < time()) {
            throw new AuthException('API key has expired', 401);
        }

        // Populate request context now so any denial (IP/rate) is auditable.
        McpContext::set((int) $row->id, (int) $row->user_id, $this->clientIp(), (bool) $row->allow_destructive);

        $this->enforceIpWhitelist($row);
        $this->enforceRateLimit($row);

        $this->CI->db->where('id', $row->id)->update($this->table, [
            'last_used_at'   => date('Y-m-d H:i:s'),
            'requests_count' => (int) $row->requests_count + 1,
        ]);

        return $row;
    }

    /**
     * Turn on core impersonation for the key's staff member and populate the
     * request context used by the audit logger.
     */
    public function impersonate(object $row): void
    {
        if (! defined('API')) {
            define('API', true);
        }

        // core get_staff_user_id() reads this header (name from config/rest.php)
        // and resolves user_id from the keys table by the stored hash.
        $_SERVER['HTTP_X_MCP_KEY'] = $row->key;

        // McpContext was already populated in authenticate().
    }

    /**
     * Enforce the key's optional IP whitelist. An empty whitelist means the key
     * works from any address (backward compatible). Entries may be exact IPv4/
     * IPv6 addresses or CIDR ranges, separated by commas/newlines/spaces.
     *
     * @throws AuthException 403 when the source IP is not allowed
     */
    private function enforceIpWhitelist(object $row): void
    {
        $raw = trim((string) ($row->ip_whitelist ?? ''));
        if ($raw === '') {
            return;
        }

        $entries = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ip      = $this->clientIp();

        if ($ip === null || ! self::ipMatchesList($ip, $entries)) {
            AuditLogger::log('(auth)', ['ip' => $ip], 'denied', 'Source IP not in key whitelist', null);
            throw new AuthException('Source IP is not permitted for this API key', 403);
        }
    }

    /**
     * @param string[] $entries exact IPs or CIDR ranges
     */
    private static function ipMatchesList(string $ip, array $entries): bool
    {
        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (strpos($entry, '/') !== false) {
                if (self::cidrMatch($ip, $entry)) {
                    return true;
                }
            } elseif (self::ipEquals($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    private static function ipEquals(string $a, string $b): bool
    {
        $pa = @inet_pton($a);
        $pb = @inet_pton($b);

        return $pa !== false && $pb !== false && $pa === $pb;
    }

    /**
     * CIDR match for IPv4 and IPv6 via binary comparison.
     */
    private static function cidrMatch(string $ip, string $cidr): bool
    {
        [$subnet, $bitsRaw] = array_pad(explode('/', $cidr, 2), 2, '');
        if ($bitsRaw === '' || ! ctype_digit($bitsRaw)) {
            return false;
        }
        $bits      = (int) $bitsRaw;
        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        if ($bits < 0 || $bits > strlen($ipBin) * 8) {
            return false;
        }

        $whole = intdiv($bits, 8);
        $rem   = $bits % 8;

        if ($whole > 0 && strncmp($ipBin, $subnetBin, $whole) !== 0) {
            return false;
        }
        if ($rem > 0) {
            $mask = chr((0xFF << (8 - $rem)) & 0xFF);

            return (ord($ipBin[$whole]) & ord($mask)) === (ord($subnetBin[$whole]) & ord($mask));
        }

        return true;
    }

    /**
     * Sliding 60-second window counted from the audit table (no extra table).
     *
     * @throws AuthException
     */
    private function enforceRateLimit(object $row): void
    {
        $limit = (int) $row->rate_limit_per_minute;
        if ($limit <= 0) {
            return;
        }

        $since = date('Y-m-d H:i:s', time() - 60);
        $count = $this->CI->db
            ->where('key_id', $row->id)
            ->where('created_at >=', $since)
            ->count_all_results(db_prefix() . 'mcp_connector_audit');

        if ($count >= $limit) {
            throw new AuthException('Rate limit exceeded (' . $limit . '/min)', 429);
        }
    }

    /**
     * Pull the bearer token from the request, tolerating Apache/FastCGI setups
     * that drop the Authorization header. Also accepts an X-MCP-Token header as
     * a fallback for clients or hosts where Authorization is unavailable.
     */
    private function extractToken(): ?string
    {
        $candidates = [];

        $auth = $this->CI->input->get_request_header('Authorization', true);
        if ($auth) {
            $candidates[] = $auth;
        }
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $server_key) {
            if (! empty($_SERVER[$server_key])) {
                $candidates[] = $_SERVER[$server_key];
            }
        }
        if (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $candidates[] = $value;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (preg_match('/^\s*Bearer\s+(.+)$/i', $candidate, $m)) {
                return trim($m[1]);
            }
        }

        // Direct token header fallback (name distinct from the internal
        // impersonation header, which carries a hash, not the raw token).
        $direct = $this->CI->input->get_request_header('X-MCP-Token', true);
        if ($direct) {
            return trim($direct);
        }

        return null;
    }

    private function clientIp(): ?string
    {
        $ip = $this->CI->input->ip_address();

        return $ip !== '' ? $ip : null;
    }
}

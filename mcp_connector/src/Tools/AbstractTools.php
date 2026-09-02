<?php

namespace PerfexMcp\Tools;

use Mcp\Exception\ToolCallException;
use PerfexMcp\Auth\PermissionDenied;
use PerfexMcp\Auth\ReadScope;
use PerfexMcp\Auth\Visibility;
use PerfexMcp\Support\AuditLogger;

/**
 * Base class for every tool group. Provides the uniform guard() wrapper that
 * enforces permissions, runs the operation, normalizes errors into clean MCP
 * tool errors, and writes an audit row for every call.
 *
 * Reads use the tuple ['read', <feature>]: guard() resolves the staff
 * member's row-visibility scope from the Visibility table (gate + predicate)
 * and hands it to the closure, which passes it to paginate() and
 * assertVisible(). Writes keep the capability tuples.
 *
 * Tool classes are instantiated by the SDK with no constructor arguments, so
 * all context (CI instance, impersonated staff) is pulled from globals.
 */
abstract class AbstractTools
{
    protected $CI;

    public function __construct()
    {
        $this->CI = &get_instance();
    }

    /**
     * Run a tool body under permission + audit control.
     *
     * @param string   $tool       tool name (for audit)
     * @param array    $permission [$capability] or [$capability, $feature].
     *                             A single-element array is treated as a
     *                             callable capability (e.g. is_staff_member).
     *                             ['read', $feature] resolves a ReadScope from
     *                             the Visibility table and passes it to $fn.
     * @param array    $args       raw arguments (for audit)
     * @param callable $fn         the operation; returns the tool result
     *
     * @return mixed
     */
    protected function guard(string $tool, array $permission, array $args, callable $fn)
    {
        $start = microtime(true);

        try {
            $scope = null;
            if (($permission[0] ?? null) === 'read') {
                $scope = Visibility::scope((string) ($permission[1] ?? ''));
            } else {
                $this->requirePermission($permission);
            }
            $result = $fn($scope);
            AuditLogger::log($tool, $args, 'success', null, self::elapsedMs($start));

            return $result;
        } catch (PermissionDenied $e) {
            AuditLogger::log($tool, $args, 'denied', $e->getMessage(), self::elapsedMs($start));
            throw new ToolCallException($e->getMessage());
        } catch (ToolCallException $e) {
            AuditLogger::log($tool, $args, 'error', $e->getMessage(), self::elapsedMs($start));
            throw $e;
        } catch (\Throwable $e) {
            log_message('error', '[mcp_connector] tool ' . $tool . ' failed: ' . $e->getMessage());
            AuditLogger::log($tool, $args, 'error', $e->getMessage(), self::elapsedMs($start));
            throw new ToolCallException('Internal error while executing ' . $tool . '.');
        }
    }

    /**
     * @param array $permission [$capability] or [$capability, $feature]
     *
     * @throws PermissionDenied
     */
    protected function requirePermission(array $permission): void
    {
        $capability = $permission[0];
        $feature    = $permission[1] ?? null;

        // staff_can() resolves the impersonated staff via get_staff_user_id(),
        // handles callable capability names (is_admin, is_staff_member) when no
        // feature is passed, and grants admins everything.
        $allowed = $feature === null
            ? staff_can($capability)
            : staff_can($capability, $feature);

        if (! $allowed) {
            throw new PermissionDenied(
                'Permission denied: requires "' . $capability . '"'
                . ($feature !== null ? ' on ' . $feature : '') . '.'
            );
        }
    }

    /**
     * Fail a tool with a client-visible message.
     *
     * @throws ToolCallException
     */
    protected function fail(string $message): void
    {
        throw new ToolCallException($message);
    }

    /**
     * Guard a destructive (delete) operation: the global destructive
     * kill-switch must be on, the key must carry allow_destructive, and the
     * caller must pass confirm=true.
     *
     * @throws PermissionDenied
     */
    protected function requireDestructive(bool $confirm): void
    {
        if (! get_option('mcp_connector_enable_destructive_tools')) {
            throw new PermissionDenied('Destructive tools are disabled on this server.');
        }
        if (! \PerfexMcp\Support\McpContext::allowDestructive()) {
            throw new PermissionDenied('This API key is not permitted to run destructive tools.');
        }
        if (! $confirm) {
            throw new PermissionDenied('Refusing to delete without confirm=true.');
        }
    }

    /**
     * Reduce an arguments array to a whitelist of allowed keys, dropping nulls.
     * Keeps model inputs tight and prevents mass-assignment of unexpected columns.
     */
    protected function pickAllowed(array $args, array $allowed): array
    {
        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $args) && $args[$key] !== null) {
                $out[$key] = $args[$key];
            }
        }

        return $out;
    }

    /**
     * Require that a set of keys is present and non-empty in the input.
     *
     * @throws ToolCallException
     */
    protected function requireFields(array $args, array $required): void
    {
        foreach ($required as $field) {
            if (! isset($args[$field]) || $args[$field] === '' || $args[$field] === null) {
                throw new ToolCallException('Missing required field: ' . $field . '.');
            }
        }
    }

    /**
     * Clamp a requested page size into a sane range.
     */
    protected function clampLimit($limit, int $default = 25, int $max = 100): int
    {
        $limit = (int) $limit;
        if ($limit <= 0) {
            return $default;
        }

        return min($limit, $max);
    }

    /**
     * Run a paginated read against a single table. Centralizes the CI3 idiom
     * (from() once, count on the empty alias, no-arg get()) that otherwise
     * double-aliases the table.
     *
     * The scope is mandatory: no list can run without naming a feature in the
     * Visibility table, which is what makes "not in the table" fail closed.
     *
     * @param ReadScope     $scope   the staff member's visibility for this feature
     * @param string        $table   full table name (with prefix)
     * @param string        $select  column list for the page rows
     * @param callable|null $filter  fn(CI_DB_query_builder $db): void to add WHERE
     * @param string|null   $orderBy column to order by
     *
     * @return array{total:int,limit:int,offset:int,data:array}
     */
    protected function paginate(
        ReadScope $scope,
        string $table,
        string $select,
        ?callable $filter,
        ?string $orderBy,
        string $orderDir,
        $limit,
        $offset
    ): array {
        $limit  = $this->clampLimit($limit);
        $offset = max(0, (int) $offset);

        $this->CI->db->from($table);
        $scope->apply($this->CI->db);
        if ($filter !== null) {
            $filter($this->CI->db);
        }

        $total = $this->CI->db->count_all_results('', false);

        $this->CI->db->select($select);
        if ($orderBy !== null) {
            $this->CI->db->order_by($orderBy, $orderDir);
        }
        $rows = $this->CI->db->limit($limit, $offset)->get()->result_array();

        return [
            'total'  => (int) $total,
            'limit'  => $limit,
            'offset' => $offset,
            'data'   => $rows,
        ];
    }

    /**
     * Strip sensitive keys from a record (or list of records) before returning
     * it to the client. Used for staff/contact rows that carry password hashes,
     * 2FA secrets and reset keys.
     */
    protected function stripSecrets($data, array $extra = [])
    {
        $secret = array_merge([
            'password', 'new_pass_key', 'new_pass_key_requested', 'google_auth_secret',
            'two_factor_auth_code', 'two_factor_auth_code_requested', 'email_verification_key',
            'last_ip', 'token', 'hash', 'ticketkey', 'short_link',
        ], $extra);

        if (is_object($data)) {
            foreach ($secret as $k) {
                unset($data->$k);
            }

            return $data;
        }
        if (is_array($data)) {
            if ($data !== [] && (is_array(reset($data)) || is_object(reset($data)))) {
                return array_map(fn ($row) => $this->stripSecrets($row, $extra), $data);
            }
            foreach ($secret as $k) {
                unset($data[$k]);
            }
        }

        return $data;
    }

    /**
     * Convert a simple items payload into the sales-document "newitems" shape and
     * the subtotal. Each input item: {description, qty, rate, long_description?,
     * unit?}. Per-item tax is out of scope in this version (documented).
     *
     * @return array{0: array, 1: float}
     */
    protected function buildSalesItems(array $items): array
    {
        if ($items === []) {
            throw new ToolCallException('At least one line item is required.');
        }

        $newitems = [];
        $subtotal = 0.0;
        $order    = 1;

        foreach ($items as $item) {
            if (! isset($item['description']) || $item['description'] === '') {
                throw new ToolCallException('Each item requires a description.');
            }
            $qty  = isset($item['qty']) ? (float) $item['qty'] : 1.0;
            $rate = isset($item['rate']) ? (float) $item['rate'] : 0.0;
            $subtotal += $qty * $rate;

            $newitems[$order] = [
                'description'      => $item['description'],
                'long_description' => $item['long_description'] ?? '',
                'qty'              => $qty,
                'unit'             => $item['unit'] ?? '',
                'rate'             => $rate,
                'order'            => $order,
                'taxname'          => [],
            ];
            $order++;
        }

        return [$newitems, round($subtotal, 2)];
    }

    // ------------------------------------------------- shared validation

    /**
     * Refuse a single-record read the staff member's scope does not cover.
     * Re-runs the SAME predicate the list uses, evaluated by the database
     * (SELECT COUNT(*) ... WHERE pk = id AND <predicate>) - never a PHP
     * re-implementation that could drift from it. No-op for a global scope.
     * $table is given without the prefix.
     */
    protected function assertVisible(ReadScope $scope, string $table, int $id, string $label, string $pk = 'id'): void
    {
        if ($scope->isGlobal()) {
            return;
        }
        $this->CI->db->where($pk, $id);
        $scope->apply($this->CI->db);
        if ((int) $this->CI->db->count_all_results(db_prefix() . $table) === 0) {
            throw new PermissionDenied('Permission denied: ' . $label . ' ' . $id . ' is not visible to this staff member.');
        }
    }

    /** requireRow() (not-found first, as before) followed by the visibility check. */
    protected function requireVisibleRow(ReadScope $scope, string $table, int $id, string $label, string $pk = 'id'): array
    {
        $row = $this->requireRow($table, $id, $label, $pk);
        $this->assertVisible($scope, $table, $id, $label, $pk);

        return $row;
    }

    /**
     * Fetch one row by primary key or fail with a clean not-found error.
     * $table is given without the prefix. $pk exists because Perfex is not
     * uniform: knowledge_base uses articleid, its groups groupid,
     * announcements announcementid, clients userid.
     */
    protected function requireRow(string $table, int $id, string $label, string $pk = 'id'): array
    {
        $this->CI->db->where($pk, $id);
        $row = $this->CI->db->get(db_prefix() . $table)->row_array();
        if (! $row) {
            throw new ToolCallException($label . ' ' . $id . ' not found.');
        }

        return $row;
    }

    /** The customer row, or a clean error. */
    protected function requireCustomer(int $id): array
    {
        if ($id <= 0) {
            throw new ToolCallException('customer_id is required.');
        }

        return $this->requireRow('clients', $id, 'Customer', 'userid');
    }

    /**
     * CHECKBOX PRESENCE SEMANTICS, made greppable. Several Perfex model add()
     * methods turn the mere existence of a key into 1 ('flag' => 0 or false
     * still becomes 1). Model payloads therefore carry such a key only when
     * the flag is actually true - never build them with pickAllowed(), which
     * keeps false.
     */
    protected function setCheckboxFlag(array $data, string $key, bool $on): array
    {
        if ($on) {
            $data[$key] = 1;
        }

        return $data;
    }

    protected function isoDate(string $date, string $field): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $date;
        }
        throw new ToolCallException($field . ' must be an ISO date (Y-m-d), got "' . $date . '".');
    }

    /**
     * Accepts a currency id or an ISO code ('EUR'); tblcurrencies.name IS the
     * ISO code, so string matching is exact. Callers keep the parameter
     * untyped so the SDK's named-argument mapping accepts the union schema.
     */
    protected function normalizeCurrency($value, bool $defaultToBase = false): int
    {
        if (($value === null || $value === '') && $defaultToBase) {
            $this->CI->db->where('isdefault', 1);
            $base = $this->CI->db->get(db_prefix() . 'currencies')->row_array();
            if (! $base) {
                throw new ToolCallException('No base currency configured in Perfex.');
            }

            return (int) $base['id'];
        }

        if (is_numeric($value)) {
            $this->CI->db->where('id', (int) $value);
        } else {
            $this->CI->db->where('LOWER(name)', mb_strtolower(trim((string) $value)));
        }
        $row = $this->CI->db->get(db_prefix() . 'currencies')->row_array();
        if (! $row) {
            $available = array_map(
                static fn ($c) => $c['id'] . '=' . $c['name'],
                $this->CI->db->get(db_prefix() . 'currencies')->result_array()
            );
            throw new ToolCallException('Unknown currency "' . $value . '". Available: ' . implode(', ', array_slice($available, 0, 20)) . '.');
        }

        return (int) $row['id'];
    }

    /**
     * Validates against tblpayment_modes rows only - the offline modes. A
     * payment GATEWAY id like "stripe" is an invoice-only concept (see
     * InvoiceTools::normalizePaymentModes) and is never accepted here.
     */
    protected function requirePaymentModeId(string $mode): string
    {
        // Digits only, canonical id returned: MySQL's loose comparison would
        // let '2x' or '2.9' match id 2 and then store the raw garbage string,
        // which the Perfex UI renders as a blank payment mode.
        $mode = trim($mode);
        if (ctype_digit($mode)) {
            $this->CI->db->where('id', (int) $mode);
            $row = $this->CI->db->get(db_prefix() . 'payment_modes')->row_array();
            if ($row) {
                return (string) (int) $row['id'];
            }
        }
        $rows = $this->CI->db->get(db_prefix() . 'payment_modes')->result_array();
        $list = array_map(static fn ($m) => $m['id'] . '=' . $m['name'], array_slice($rows, 0, 20));
        throw new ToolCallException('Unknown payment mode "' . $mode . '". Available: ' . implode(', ', $list) . '.');
    }

    /**
     * Perfex stores textarea columns nl2br()'d. nl2br() PRESERVES the newline
     * next to each inserted <br />, so the tag AND its trailing newline must
     * collapse to one \n - otherwise every read gains a blank line and
     * read->write cycles double the breaks.
     */
    protected function decodeBreaks(?string $html): string
    {
        return (string) preg_replace('/<br\s*\/?\s*>\r?\n?/i', "\n", (string) $html);
    }

    /** Normalize any <br> variant to \n first, then encode exactly once. */
    protected function encodeBreaks(string $text): string
    {
        return nl2br($this->decodeBreaks($text));
    }

    /**
     * Null-safe lookup decoration for list rows: adds $outKey with the
     * $nameColumn of the $table row whose $pk equals the row's $fkColumn.
     * Lookup tables may be empty on a greenfield install.
     */
    protected function decorateLookup(array $rows, string $table, string $fkColumn, string $nameColumn, string $outKey, string $pk = 'id'): array
    {
        if ($rows === []) {
            return $rows;
        }
        $names = [];
        foreach ($this->CI->db->get(db_prefix() . $table)->result_array() as $r) {
            $names[(int) $r[$pk]] = $r[$nameColumn];
        }
        foreach ($rows as &$row) {
            $row[$outKey] = $names[(int) ($row[$fkColumn] ?? 0)] ?? null;
        }

        return $rows;
    }

    private static function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}

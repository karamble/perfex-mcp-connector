<?php

namespace PerfexMcp\Support;

use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * MCP session store backed by the module's own DB table.
 *
 * Preferred over the SDK's FileSessionStore on shared hosting: no web-readable
 * session files, no orphaned temp dirs, and it rides the CRM's existing DB
 * connection. Each write refreshes the TTL; gc() removes expired rows.
 */
final class DbSessionStore implements SessionStoreInterface
{
    private $CI;

    private string $table;

    private int $ttl;

    public function __construct(int $ttl = 3600)
    {
        $this->CI    = &get_instance();
        $this->table = db_prefix() . 'mcp_connector_sessions';
        $this->ttl   = $ttl > 0 ? $ttl : 3600;
    }

    public function exists(Uuid $id): bool
    {
        return $this->CI->db
            ->where('id', (string) $id)
            ->where('expires_at >=', time())
            ->count_all_results($this->table) > 0;
    }

    public function read(Uuid $id): string|false
    {
        $row = $this->CI->db
            ->where('id', (string) $id)
            ->where('expires_at >=', time())
            ->get($this->table)
            ->row();

        if (! $row) {
            return false;
        }

        return (string) $row->data;
    }

    public function write(Uuid $id, string $data): bool
    {
        $sid     = (string) $id;
        $payload = [
            'data'       => $data,
            'expires_at' => time() + $this->ttl,
        ];

        $exists = $this->CI->db->where('id', $sid)->count_all_results($this->table) > 0;

        if ($exists) {
            $this->CI->db->where('id', $sid)->update($this->table, $payload);
        } else {
            $payload['id'] = $sid;
            $this->CI->db->insert($this->table, $payload);
        }

        return true;
    }

    public function destroy(Uuid $id): bool
    {
        $this->CI->db->where('id', (string) $id)->delete($this->table);

        return true;
    }

    /**
     * @return Uuid[]
     */
    public function gc(): array
    {
        $now     = time();
        $expired = $this->CI->db
            ->select('id')
            ->where('expires_at <', $now)
            ->get($this->table)
            ->result();

        if (empty($expired)) {
            return [];
        }

        $ids = [];
        foreach ($expired as $row) {
            try {
                $ids[] = Uuid::fromString($row->id);
            } catch (\Throwable $e) {
                // Skip malformed ids; they will still be deleted below.
            }
        }

        $this->CI->db->where('expires_at <', $now)->delete($this->table);

        return $ids;
    }
}

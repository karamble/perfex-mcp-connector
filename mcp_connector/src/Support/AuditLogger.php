<?php

namespace PerfexMcp\Support;

/**
 * Writes one row per tools/call into the audit table. Also the data source for
 * per-key sliding-window rate limiting (see KeyService).
 */
final class AuditLogger
{
    private const MAX_ARGS_BYTES = 8192;

    /**
     * @param string      $tool     tool name
     * @param array       $args     raw tool arguments (secrets are redacted)
     * @param string      $status   success|error|denied
     * @param string|null $error    error message when status is not success
     * @param int|null    $duration duration in milliseconds
     */
    public static function log(string $tool, array $args, string $status, ?string $error, ?int $duration): void
    {
        $CI = &get_instance();

        $argsJson = json_encode(
            self::redact($args),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($argsJson === false) {
            $argsJson = '{}';
        }

        if (strlen($argsJson) > self::MAX_ARGS_BYTES) {
            $argsJson = substr($argsJson, 0, self::MAX_ARGS_BYTES) . '...[truncated]';
        }

        $CI->db->insert(db_prefix() . 'mcp_connector_audit', [
            'key_id'        => McpContext::keyId(),
            'staff_id'      => McpContext::staffId(),
            'tool'          => $tool,
            'arguments'     => $argsJson,
            'result_status' => $status,
            'error_message' => $error,
            'ip'            => McpContext::ip(),
            'duration_ms'   => $duration,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Redact values whose key name looks like a secret.
     */
    private static function redact(array $args): array
    {
        foreach ($args as $key => $value) {
            // 'base64' keeps raw file payloads out of audit rows (the attach
            // tool passes only metadata, but this is the backstop). 'content'
            // must NOT be added: tasks_comment_add has a legitimate content key.
            if (is_string($key) && preg_match('/pass|secret|token|api[_-]?key|authorization|base64/i', $key)) {
                $args[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $args[$key] = self::redact($value);
            }
        }

        return $args;
    }
}

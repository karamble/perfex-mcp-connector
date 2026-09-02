<?php

namespace PerfexMcp\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Emits a PSR-7 response directly, bypassing CodeIgniter's output class so the
 * MCP SDK's status code, Mcp-Session-Id header and JSON-RPC body pass through
 * unmodified. The caller should exit immediately afterwards.
 */
final class ResponseEmitter
{
    public static function emit(ResponseInterface $response): void
    {
        // Discard any output CI may have buffered so far.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (! headers_sent()) {
            http_response_code($response->getStatusCode());

            foreach ($response->getHeaders() as $name => $values) {
                $first = true;
                foreach ($values as $value) {
                    header($name . ': ' . $value, $first);
                    $first = false;
                }
            }
        }

        echo (string) $response->getBody();
    }
}

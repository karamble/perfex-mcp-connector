<?php

namespace PerfexMcp\Support;

use Psr\Log\AbstractLogger;

/**
 * Minimal PSR-3 logger that forwards MCP SDK log output into CodeIgniter's log
 * files (application/logs). AbstractLogger implements the eight level helpers;
 * we only need to provide log().
 */
final class CiLogger extends AbstractLogger
{
    /**
     * @param mixed             $level
     * @param string|\Stringable $message
     * @param array             $context
     */
    public function log($level, $message, array $context = []): void
    {
        $ciLevel = in_array($level, ['emergency', 'alert', 'critical', 'error'], true)
            ? 'error'
            : 'debug';

        $suffix = empty($context) ? '' : ' ' . @json_encode($context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        log_message($ciLevel, '[mcp_connector] ' . $level . ': ' . $message . $suffix);
    }
}

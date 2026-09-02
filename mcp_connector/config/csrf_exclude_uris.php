<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
 * The MCP endpoint speaks JSON-RPC over HTTP with bearer-token auth. It has no
 * session cookie and no CSRF token, so it must be excluded from CSRF checks.
 * This file is merged into the global csrf_exclude_uris filter pre-system by
 * core's InitModules hook (for all valid modules, even inactive), which is the
 * officially documented mechanism for module API/webhook endpoints.
 */

return [
    'mcp_connector/mcp',
];

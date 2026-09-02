<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
 * Runs on module uninstall (after deactivation). Drops all module tables and
 * options. Note: uninstall physically deletes the module directory afterwards,
 * so nothing here should assume the files persist.
 *
 * App_modules::uninstall() include_once's this file in its own method scope, so
 * we obtain the CI instance ourselves.
 */

$CI = &get_instance();

$mcp_connector_tables = [
    db_prefix() . 'mcp_connector_keys',
    db_prefix() . 'mcp_connector_sessions',
    db_prefix() . 'mcp_connector_audit',
];

foreach ($mcp_connector_tables as $mcp_connector_table) {
    if ($CI->db->table_exists($mcp_connector_table)) {
        $CI->db->query('DROP TABLE `' . $mcp_connector_table . '`;');
    }
}

$mcp_connector_options = [
    'mcp_connector_enabled',
    'mcp_connector_enable_write_tools',
    'mcp_connector_enable_destructive_tools',
    'mcp_connector_default_rate_limit',
    'mcp_connector_session_ttl',
];

foreach ($mcp_connector_options as $mcp_connector_option) {
    delete_option($mcp_connector_option);
}

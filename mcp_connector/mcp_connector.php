<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: MCP Connector
Description: Turns Perfex CRM into an MCP (Model Context Protocol) server so AI clients like Claude can securely read and manage your CRM through per-key, permission-scoped access.
Version: 1.0.0
Requires at least: 3.0.*
Author: UrbanDigital
*/

define('MCP_CONNECTOR_MODULE_NAME', 'mcp_connector');
define('MCP_CONNECTOR_VERSION', '1.0.0');

/*
 * The module entry file is required on EVERY request (admin and client area),
 * so top-level work must stay cheap and side-effect-free. Anything that touches
 * the DB or renders UI is deferred into the admin_init / activation hooks below.
 */

// Composer autoloader for the bundled MCP SDK + PSR-7 stack. Guarded so a broken
// or missing vendor dir shows an admin notice instead of a fatal white screen.
$mcpConnectorAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($mcpConnectorAutoload)) {
    require_once $mcpConnectorAutoload;
}

/**
 * Whether the module's runtime prerequisites are met (PHP version + vendor dir).
 *
 * @return bool
 */
function mcp_connector_prerequisites_met()
{
    return PHP_VERSION_ID >= 80100 && class_exists(\Mcp\Server::class);
}

register_language_files(MCP_CONNECTOR_MODULE_NAME, [MCP_CONNECTOR_MODULE_NAME]);

register_activation_hook(MCP_CONNECTOR_MODULE_NAME, 'mcp_connector_activation_hook');
register_deactivation_hook(MCP_CONNECTOR_MODULE_NAME, 'mcp_connector_deactivation_hook');

/**
 * Create tables and seed options on activation.
 */
function mcp_connector_activation_hook()
{
    $CI = &get_instance();
    require_once __DIR__ . '/install.php';
}

/**
 * Nothing destructive on deactivation: keys and audit history are retained so a
 * re-activation restores the previous state. The endpoint goes dark because the
 * public controller checks is_active() (see controllers/Mcp.php).
 */
function mcp_connector_deactivation_hook()
{
    // Intentionally empty.
}

hooks()->add_action('admin_init', 'mcp_connector_module_init');
hooks()->add_filter('module_mcp_connector_action_links', 'mcp_connector_action_links');

/**
 * Register admin-area integration: staff capabilities, the Setup menu entry and
 * the settings section. Runs only inside the admin area.
 */
function mcp_connector_module_init()
{
    $CI = &get_instance();

    // Fail loudly-but-safely in the admin area if prerequisites are missing.
    if (! mcp_connector_prerequisites_met()) {
        if (is_admin()) {
            set_alert('warning', 'MCP Connector: PHP 8.1+ and the bundled vendor/ directory are required. The connector endpoint is disabled until this is resolved.');
        }

        return;
    }

    register_staff_capabilities(
        MCP_CONNECTOR_MODULE_NAME,
        [
            'capabilities' => [
                'view'   => _l('mcp_connector_permission_view'),
                'create' => _l('mcp_connector_permission_create'),
                'delete' => _l('mcp_connector_permission_delete'),
            ],
        ],
        _l('mcp_connector')
    );

    if (staff_can('view', MCP_CONNECTOR_MODULE_NAME)) {
        $CI->app_menu->add_setup_menu_item('mcp-connector', [
            'name'     => _l('mcp_connector'),
            'href'     => admin_url('mcp_connector'),
            'position' => 40,
            'icon'     => 'fa-solid fa-plug',
        ]);
    }
}

/**
 * Add quick links on the Setup -> Modules row for this module.
 *
 * @param array $actions
 *
 * @return array
 */
function mcp_connector_action_links($actions)
{
    if (get_instance()->app_modules->is_active(MCP_CONNECTOR_MODULE_NAME)) {
        $actions[] = '<a href="' . admin_url('mcp_connector') . '">' . _l('mcp_connector_manage_keys') . '</a>';
    }

    return $actions;
}

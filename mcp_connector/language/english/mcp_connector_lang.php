<?php

defined('BASEPATH') or exit('No direct script access allowed');

# Module + permissions
$lang['mcp_connector']                    = 'MCP Connector';
$lang['mcp_connector_permission_view']    = 'View';
$lang['mcp_connector_permission_create']  = 'Create keys';
$lang['mcp_connector_permission_delete']  = 'Revoke keys';
$lang['mcp_connector_manage_keys']        = 'Manage API keys';

# Intro / onboarding
$lang['mcp_connector_intro']              = 'Expose this CRM to AI clients (such as Claude) over the Model Context Protocol. Create an API key below, then connect your client to the endpoint. Each key acts as a specific staff member and is limited by that member\'s permissions.';
$lang['mcp_connector_endpoint']           = 'MCP endpoint';
$lang['mcp_connector_endpoint_help']      = 'Point your MCP client at this URL using the streamable HTTP transport and a Bearer token.';
$lang['mcp_connector_new_token_notice']   = 'Copy this API key now. For security it is shown only once and cannot be retrieved later.';
$lang['mcp_connector_connect_with']       = 'Connect with Claude Code:';
$lang['mcp_connector_connect_claude_code'] = 'Connect from Claude Code';
$lang['mcp_connector_connect_claude_ai']  = 'Connect from claude.ai (custom connector)';
$lang['mcp_connector_step_settings']      = 'Open Settings > Connectors > Add custom connector.';
$lang['mcp_connector_step_url']           = 'Set the remote MCP server URL to %s';
$lang['mcp_connector_step_header']        = 'Add an authentication header:';
$lang['mcp_connector_auth_header_note']   = 'The key acts as its assigned staff member and is limited by that member\'s permissions. If authentication fails on your server, ensure Apache passes the Authorization header to PHP (CGIPassAuth On, or the equivalent for your host).';

# Keys table
$lang['mcp_connector_keys']               = 'API keys';
$lang['mcp_connector_label']              = 'Label';
$lang['mcp_connector_token_prefix']       = 'Token';
$lang['mcp_connector_acts_as']            = 'Acts as';
$lang['mcp_connector_destructive']        = 'Destructive';
$lang['mcp_connector_rate_limit']         = 'Rate limit';
$lang['mcp_connector_last_used']          = 'Last used';
$lang['mcp_connector_requests']           = 'Requests';
$lang['mcp_connector_status']             = 'Status';
$lang['mcp_connector_active']             = 'Active';
$lang['mcp_connector_revoked']            = 'Revoked';
$lang['mcp_connector_never']              = 'Never';
$lang['mcp_connector_yes']                = 'Yes';
$lang['mcp_connector_no']                 = 'No';
$lang['mcp_connector_no_keys']            = 'No API keys yet. Create one to get started.';
$lang['mcp_connector_revoke']             = 'Revoke';
$lang['mcp_connector_confirm_revoke']     = 'Revoke this API key? Clients using it will immediately lose access.';

# IP whitelist
$lang['mcp_connector_ip_whitelist']       = 'IP whitelist';
$lang['mcp_connector_ip_whitelist_help']  = 'Optional. One IP or CIDR range per line (IPv4/IPv6). If set, the key only works from these addresses. Leave empty to allow any IP.';
$lang['mcp_connector_any_ip']             = 'Any IP';
$lang['mcp_connector_invalid_ip']         = 'The IP whitelist contains an invalid IP or CIDR range.';
$lang['mcp_connector_whitelist_updated']  = 'IP whitelist updated.';

# Create key form
$lang['mcp_connector_create_key']         = 'Create API key';
$lang['mcp_connector_acts_as_help']       = 'The key can only do what this staff member is permitted to do.';
$lang['mcp_connector_acts_as_self']       = 'This key will act as you.';
$lang['mcp_connector_allow_destructive']  = 'Allow destructive tools (delete)';

# Settings
$lang['mcp_connector_settings']           = 'Settings';
$lang['mcp_connector_opt_enabled']        = 'Enable MCP endpoint';
$lang['mcp_connector_opt_writes']         = 'Enable write tools (create / update)';
$lang['mcp_connector_opt_destructive']    = 'Enable destructive tools (delete)';
$lang['mcp_connector_opt_default_rate']   = 'Default rate limit (requests / minute)';

# Audit log
$lang['mcp_connector_audit_log']          = 'Audit log';
$lang['mcp_connector_audit_intro']        = 'Every tool call is recorded here, including denied and failed calls. Showing the 200 most recent.';
$lang['mcp_connector_back_to_keys']       = 'Back to keys';
$lang['mcp_connector_view_audit_log']     = 'View audit log';
$lang['mcp_connector_filter_tool']        = 'Filter by tool...';
$lang['mcp_connector_any_status']         = 'Any status';
$lang['mcp_connector_filter']             = 'Filter';
$lang['mcp_connector_when']               = 'When';
$lang['mcp_connector_tool']               = 'Tool';
$lang['mcp_connector_arguments']          = 'Arguments / error';
$lang['mcp_connector_no_audit']           = 'No calls recorded yet.';

# Flash messages
$lang['mcp_connector_key_created']        = 'API key created.';
$lang['mcp_connector_key_revoked']        = 'API key revoked.';
$lang['mcp_connector_settings_saved']     = 'Settings saved.';
$lang['mcp_connector_label_required']     = 'A label is required.';
$lang['mcp_connector_invalid_staff']      = 'The selected staff member does not exist.';

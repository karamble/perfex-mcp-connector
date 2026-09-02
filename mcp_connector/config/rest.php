<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
 * This module-local config is what makes Perfex core attribute API requests to a
 * staff member. When the module controller defines the API constant, core's
 * get_staff_user_id() (application/helpers/general_helper.php) loads THIS file,
 * reads the request header named by 'rest_key_name' from $_SERVER, and looks up
 * the matching row in 'rest_keys_table' by its `key` column to resolve user_id.
 *
 * The controller validates the real bearer token itself, then injects the
 * token's SHA-256 hash into $_SERVER[HTTP_X_MCP_KEY] so only hashes are stored.
 *
 * MX resolves $CI->load->config('rest') from the routed module's own config
 * directory, so no core file is touched.
 */

$config['rest_key_name']   = 'X-MCP-KEY';
$config['rest_keys_table'] = db_prefix() . 'mcp_connector_keys';

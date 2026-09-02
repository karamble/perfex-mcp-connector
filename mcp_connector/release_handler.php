<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
 * Optional self-update hook. Perfex calls this to show an in-admin "update
 * available" notice for the module. Return false when there is nothing to
 * offer, or an array: ['version' => '1.1.0', 'changelog' => '...',
 * 'update_handler' => callable] wired to the module_mcp_connector_update_handler
 * action.
 *
 * This build ships without a live update feed, so this returns false. To enable
 * self-updates later, query your own version endpoint here and, when a newer
 * version exists, return the array above. Network calls must fail closed
 * (return false) so a slow or unreachable feed never blocks the admin UI.
 */

return false;

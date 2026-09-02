<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
 * Runs once on module activation. $CI is in scope (provided by the activation
 * hook wrapper). Holds the FINAL schema; incremental changes go in migrations/.
 * All statements are guarded by table_exists so re-activation is idempotent.
 */

// -- Global options (kill-switches + defaults) --------------------------------
add_option('mcp_connector_enabled', 1);
add_option('mcp_connector_enable_write_tools', 1);
add_option('mcp_connector_enable_destructive_tools', 0);
add_option('mcp_connector_default_rate_limit', 120);
add_option('mcp_connector_session_ttl', 3600);

// -- API keys -----------------------------------------------------------------
// Column names `key` and `user_id` are mandated by core's get_staff_user_id()
// scaffolding (see config/rest.php). `key` stores the SHA-256 hash of the token.
if (! $CI->db->table_exists(db_prefix() . 'mcp_connector_keys')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "mcp_connector_keys` (
  `id` int(11) NOT NULL,
  `label` varchar(191) NOT NULL,
  `key` varchar(64) NOT NULL,
  `token_prefix` varchar(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `allow_destructive` tinyint(1) NOT NULL DEFAULT '0',
  `rate_limit_per_minute` int(11) NOT NULL DEFAULT '120',
  `ip_whitelist` text,
  `requests_count` bigint(20) NOT NULL DEFAULT '0',
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'mcp_connector_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key` (`key`),
  ADD KEY `user_id` (`user_id`);');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'mcp_connector_keys`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;');
}

// -- MCP protocol sessions (Mcp-Session-Id store) -----------------------------
if (! $CI->db->table_exists(db_prefix() . 'mcp_connector_sessions')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . 'mcp_connector_sessions` (
  `id` varchar(64) NOT NULL,
  `data` mediumblob,
  `expires_at` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=' . $CI->db->char_set . ';');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'mcp_connector_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `expires_at` (`expires_at`);');
}

// -- Audit log (one row per tools/call; also the rate-limit window source) ----
if (! $CI->db->table_exists(db_prefix() . 'mcp_connector_audit')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "mcp_connector_audit` (
  `id` bigint(20) NOT NULL,
  `key_id` int(11) DEFAULT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `tool` varchar(100) NOT NULL,
  `arguments` text,
  `result_status` varchar(10) NOT NULL DEFAULT 'success',
  `error_message` text,
  `ip` varchar(45) DEFAULT NULL,
  `duration_ms` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'mcp_connector_audit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `key_id` (`key_id`,`created_at`),
  ADD KEY `tool` (`tool`);');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'mcp_connector_audit`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;');
}

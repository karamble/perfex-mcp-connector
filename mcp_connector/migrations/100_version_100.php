<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Cumulative schema migration for module version 1.0.0.
 *
 * ONE migration file ships at a time, numbered for the CURRENT header version
 * (1.0.0 -> 100), and it carries every delta since the first install schema.
 * This is forced by Perfex's App_module_migration::version(), which refuses
 * to run when two pending migrations are more than 1 apart:
 *
 *     if (isset($previous) && abs($number - $previous) > 1) -> "sequence gap"
 *
 * Module versions become migration numbers with the dots stripped, so a
 * minor bump jumps 100 -> 110 -> 120 and any two of them in the same pending
 * range abort the upgrade. Keeping a single file guarantees the range holds
 * exactly one migration, whatever version the install is coming from.
 *
 * Every statement must therefore be idempotent. install.php holds the full
 * fresh-install schema; this only patches existing installs.
 *
 * On release: delete this file, add <new>_version_<new>.php, and carry these
 * guarded statements forward.
 */
class Migration_Version_100 extends App_module_migration
{
    public function up()
    {
        $CI    = &get_instance();
        $table = db_prefix() . 'mcp_connector_keys';

        // Per-key IP whitelist (added after the first private builds; a fresh
        // install.php already creates the column).
        if ($CI->db->table_exists($table) && ! $CI->db->field_exists('ip_whitelist', $table)) {
            $CI->db->query('ALTER TABLE `' . $table . '` ADD `ip_whitelist` TEXT NULL AFTER `rate_limit_per_minute`;');
        }
    }
}

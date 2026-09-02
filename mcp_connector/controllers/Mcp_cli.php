<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Command-line helper for headless installs (no browser access to the admin
 * Modules screen). Guarded to CLI only.
 *
 *   php index.php mcp_connector mcp_cli activate
 *   php index.php mcp_connector mcp_cli status
 *   php index.php mcp_connector mcp_cli deactivate
 *   php index.php mcp_connector mcp_cli upgrade
 *   php index.php mcp_connector mcp_cli create_key <staff_id> <label>
 */
class Mcp_cli extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();

        if (! is_cli()) {
            show_404();
        }

        $this->load->library('app_modules');
    }

    public function activate()
    {
        $ok = $this->app_modules->activate('mcp_connector');
        echo $ok ? "MCP Connector activated.\n" : "Activation failed (module not found).\n";
    }

    public function deactivate()
    {
        $this->app_modules->deactivate('mcp_connector');
        echo "MCP Connector deactivated.\n";
    }

    public function status()
    {
        $active = $this->app_modules->is_active('mcp_connector');
        echo 'MCP Connector: ' . ($active ? 'ACTIVE' : 'inactive') . "\n";
        if ($active) {
            echo 'Endpoint: ' . site_url('mcp_connector/mcp') . "\n";
        }
    }

    public function upgrade()
    {
        // The migration runner lives in a library that is normally loaded by the
        // pre-controller hook (which our plain CI_Controller CLI entry bypasses).
        $this->load->library('app_module_migration');

        if (! $this->app_modules->is_database_upgrade_required('mcp_connector')) {
            echo "No database upgrade required.\n";

            return;
        }

        $result = $this->app_modules->upgrade_database('mcp_connector');
        // upgrade_database() returns TRUE on success or an error string on failure.
        echo $result === true ? "Database upgraded.\n" : ('Database upgrade FAILED: ' . $result . "\n");
    }

    /**
     * Mint an API key acting as a staff member, the same way the admin page
     * does (Mcp_connector::create_key): a pcm_ token whose SHA-256 is stored,
     * shown exactly once. No destructive flag, default rate limit, no IP
     * whitelist - tighten those on the admin page afterwards if wanted.
     * Useful for headless installs and for testing what a restricted staff
     * member sees over MCP.
     */
    public function create_key($staffId = '', $label = '')
    {
        $staffId = (int) $staffId;
        $label   = trim((string) $label);
        if ($staffId <= 0 || $label === '') {
            echo "Usage: php index.php mcp_connector mcp_cli create_key <staff_id> <label>\n";

            return;
        }

        // Direct read, not Staff_model::get(): the model consults
        // is_staff_logged_in(), which needs the session library the CLI
        // context never loads, and Perfex's exception handler then exits 1
        // without a word in production.
        $staff = $this->db->select('staffid, firstname, lastname, active')
            ->where('staffid', $staffId)->get(db_prefix() . 'staff')->row();
        if (! $staff) {
            echo "No staff member with id {$staffId}.\n";

            return;
        }

        $token  = 'pcm_' . bin2hex(random_bytes(20));
        $this->db->insert(db_prefix() . 'mcp_connector_keys', [
            'label'                 => $label,
            'key'                   => hash('sha256', $token),
            'token_prefix'          => substr($token, 0, 12),
            'user_id'               => $staffId,
            'allow_destructive'     => 0,
            'rate_limit_per_minute' => (int) (get_option('mcp_connector_default_rate_limit') ?: 120),
            'ip_whitelist'          => null,
            'created_by'            => $staffId,
            'created_at'            => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->insert_id();
        if ($id <= 0) {
            echo "Failed to store the key.\n";

            return;
        }

        echo "Key #{$id} created for staff {$staffId} ({$staff->firstname} {$staff->lastname}).\n";
        echo "Token (shown once, store it now):\n{$token}\n";
    }
}

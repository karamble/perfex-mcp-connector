<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Admin UI: /admin/mcp_connector
 *
 * Manage API keys (create / revoke), view the endpoint URL and onboarding
 * instructions, and toggle the module's kill-switches. Audit-log viewing is
 * added in a later milestone.
 */
class Mcp_connector extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('staff_model');
    }

    public function index()
    {
        if (staff_cant('view', 'mcp_connector')) {
            access_denied('mcp_connector');
        }

        $data['title']       = _l('mcp_connector');
        $data['keys']        = $this->db
            ->select('k.*, CONCAT(s.firstname, " ", s.lastname) as staff_name')
            ->from(db_prefix() . 'mcp_connector_keys k')
            ->join(db_prefix() . 'staff s', 's.staffid = k.user_id', 'left')
            ->order_by('k.id', 'desc')
            ->get()
            ->result();
        $data['staff']       = $this->staff_model->get('', ['active' => 1]);
        $data['is_admin']    = is_admin();
        $data['endpoint']    = site_url('mcp_connector/mcp');
        $data['new_token']   = $this->session->flashdata('mcp_new_token');
        $data['options']     = [
            'enabled'            => get_option('mcp_connector_enabled'),
            'writes'             => get_option('mcp_connector_enable_write_tools'),
            'destructive'        => get_option('mcp_connector_enable_destructive_tools'),
            'default_rate_limit' => get_option('mcp_connector_default_rate_limit'),
        ];

        $this->load->view('mcp_connector/keys/manage', $data);
    }

    public function create_key()
    {
        if (staff_cant('create', 'mcp_connector')) {
            access_denied('mcp_connector');
        }

        if ($this->input->method() !== 'post') {
            redirect(admin_url('mcp_connector'));
        }

        $label            = trim($this->input->post('label', true));
        $userId           = (int) $this->input->post('user_id');
        $allowDestructive = $this->input->post('allow_destructive') ? 1 : 0;
        $rateLimit        = (int) $this->input->post('rate_limit_per_minute');

        if ($label === '') {
            set_alert('warning', _l('mcp_connector_label_required'));
            redirect(admin_url('mcp_connector'));
        }

        // Privilege rule: only admins may mint a key acting as another staff
        // member. A non-admin create-capability holder is pinned to themselves.
        if (! is_admin() && $userId !== get_staff_user_id()) {
            $userId = get_staff_user_id();
        }

        if (! $this->staff_model->get($userId)) {
            set_alert('warning', _l('mcp_connector_invalid_staff'));
            redirect(admin_url('mcp_connector'));
        }

        $ipWhitelist = $this->normalize_ip_whitelist($this->input->post('ip_whitelist'));
        if ($ipWhitelist === false) {
            set_alert('warning', _l('mcp_connector_invalid_ip'));
            redirect(admin_url('mcp_connector'));
        }

        $token  = 'pcm_' . bin2hex(random_bytes(20));
        $hash   = hash('sha256', $token);
        $prefix = substr($token, 0, 12);

        $this->db->insert(db_prefix() . 'mcp_connector_keys', [
            'label'                 => $label,
            'key'                   => $hash,
            'token_prefix'          => $prefix,
            'user_id'               => $userId,
            'allow_destructive'     => $allowDestructive,
            'rate_limit_per_minute' => $rateLimit > 0 ? $rateLimit : (int) get_option('mcp_connector_default_rate_limit'),
            'ip_whitelist'          => $ipWhitelist,
            'requests_count'        => 0,
            'created_by'            => get_staff_user_id(),
            'created_at'            => date('Y-m-d H:i:s'),
        ]);

        // Shown exactly once.
        $this->session->set_flashdata('mcp_new_token', $token);
        set_alert('success', _l('mcp_connector_key_created'));
        redirect(admin_url('mcp_connector'));
    }

    public function audit()
    {
        if (staff_cant('view', 'mcp_connector')) {
            access_denied('mcp_connector');
        }

        // Opportunistic cleanup of expired MCP sessions.
        $this->db->where('expires_at <', time())->delete(db_prefix() . 'mcp_connector_sessions');

        $filterTool   = $this->input->get('tool', true);
        $filterStatus = $this->input->get('status', true);

        $this->db
            ->select('a.*, k.label as key_label, CONCAT(s.firstname, " ", s.lastname) as staff_name')
            ->from(db_prefix() . 'mcp_connector_audit a')
            ->join(db_prefix() . 'mcp_connector_keys k', 'k.id = a.key_id', 'left')
            ->join(db_prefix() . 'staff s', 's.staffid = a.staff_id', 'left')
            ->order_by('a.id', 'desc')
            ->limit(200);
        if ($filterTool) {
            $this->db->like('a.tool', $filterTool);
        }
        if (in_array($filterStatus, ['success', 'error', 'denied'], true)) {
            $this->db->where('a.result_status', $filterStatus);
        }

        $data['title']         = _l('mcp_connector_audit_log');
        $data['rows']          = $this->db->get()->result();
        $data['filter_tool']   = $filterTool;
        $data['filter_status'] = $filterStatus;

        $this->load->view('mcp_connector/audit/index', $data);
    }

    public function update_whitelist($id)
    {
        if (staff_cant('create', 'mcp_connector')) {
            access_denied('mcp_connector');
        }

        if ($this->input->method() !== 'post') {
            redirect(admin_url('mcp_connector'));
        }

        // Non-admins may only edit keys that act as themselves.
        $key = $this->db->where('id', (int) $id)->get(db_prefix() . 'mcp_connector_keys')->row();
        if (! $key || (! is_admin() && (int) $key->user_id !== get_staff_user_id())) {
            access_denied('mcp_connector');
        }

        $ipWhitelist = $this->normalize_ip_whitelist($this->input->post('ip_whitelist'));
        if ($ipWhitelist === false) {
            set_alert('warning', _l('mcp_connector_invalid_ip'));
            redirect(admin_url('mcp_connector'));
        }

        $this->db->where('id', (int) $id)->update(db_prefix() . 'mcp_connector_keys', ['ip_whitelist' => $ipWhitelist]);
        set_alert('success', _l('mcp_connector_whitelist_updated'));
        redirect(admin_url('mcp_connector'));
    }

    /**
     * Validate and normalize an IP-whitelist textarea into a newline-joined
     * string. Returns null for empty (no restriction), or false if any entry is
     * not a valid IP or CIDR range.
     *
     * @param string|null $raw
     *
     * @return string|null|false
     */
    private function normalize_ip_whitelist($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $entries = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $clean   = [];

        foreach ($entries as $entry) {
            if (strpos($entry, '/') !== false) {
                [$subnet, $bits] = array_pad(explode('/', $entry, 2), 2, '');
                if (filter_var($subnet, FILTER_VALIDATE_IP) === false || ! ctype_digit($bits)) {
                    return false;
                }
                $max = strpos($subnet, ':') !== false ? 128 : 32;
                if ((int) $bits < 0 || (int) $bits > $max) {
                    return false;
                }
            } elseif (filter_var($entry, FILTER_VALIDATE_IP) === false) {
                return false;
            }
            $clean[] = $entry;
        }

        return implode("\n", array_unique($clean));
    }

    public function revoke_key($id)
    {
        if (staff_cant('delete', 'mcp_connector')) {
            access_denied('mcp_connector');
        }

        $this->db->where('id', (int) $id)->update(db_prefix() . 'mcp_connector_keys', [
            'revoked_at' => date('Y-m-d H:i:s'),
        ]);

        set_alert('success', _l('mcp_connector_key_revoked'));
        redirect(admin_url('mcp_connector'));
    }

    public function save_settings()
    {
        if (! is_admin()) {
            access_denied('mcp_connector');
        }

        if ($this->input->method() !== 'post') {
            redirect(admin_url('mcp_connector'));
        }

        update_option('mcp_connector_enabled', $this->input->post('enabled') ? 1 : 0);
        update_option('mcp_connector_enable_write_tools', $this->input->post('writes') ? 1 : 0);
        update_option('mcp_connector_enable_destructive_tools', $this->input->post('destructive') ? 1 : 0);

        $rate = (int) $this->input->post('default_rate_limit');
        if ($rate > 0) {
            update_option('mcp_connector_default_rate_limit', $rate);
        }

        set_alert('success', _l('mcp_connector_settings_saved'));
        redirect(admin_url('mcp_connector'));
    }
}

<?php

namespace PerfexMcp\Tools;

use PerfexMcp\Auth\ReadScope;

/**
 * Setup-flavoured tools: announcements and custom-field discovery.
 *
 * Announcements have no permission feature in Perfex - the whole area is
 * admin-only - so reads require staff membership and every write requires
 * is_admin. Announcements_model::add() applies checkbox-presence semantics to
 * showtostaff/showtousers/showname and stores the AUTHOR'S NAME (not id) in
 * userid; update() is form-shaped, so updates write columns directly. The
 * message body is editor HTML, returned verbatim.
 *
 * custom_fields_list is discovery only: it tells an agent which custom
 * fields exist for an entity and what values they accept. Field definitions
 * and field VALUES are never written by this module.
 */
final class SystemTools extends AbstractTools
{
    private const ANNOUNCEMENT_COLUMNS = 'announcementid, name, showtousers, showtostaff, showname, dateadded, userid';

    /**
     * The fieldto values Perfex's custom-field form offers, verbatim. The
     * pluralization is inconsistent upstream (invoice/estimate/proposal
     * singular, expenses/contracts/projects plural) and must be matched
     * exactly.
     */
    public const CUSTOM_FIELD_TARGETS = [
        'company', 'leads', 'customers', 'contacts', 'staff', 'contracts', 'tasks', 'expenses',
        'invoice', 'items', 'credit_note', 'estimate', 'proposal', 'projects', 'tickets',
    ];

    // ---------------------------------------------------------- announcements

    public function announcements_list(?string $search = null, int $limit = 25, int $offset = 0): array
    {
        return $this->guard('announcements_list', ['read', 'announcements'], compact('search', 'limit', 'offset'), function (ReadScope $scope) use ($search, $limit, $offset) {
            $result = $this->paginate(
                $scope,
                db_prefix() . 'announcements',
                self::ANNOUNCEMENT_COLUMNS,
                function ($db) use ($search) {
                    if ($search !== null && $search !== '') {
                        $db->like('name', $search);
                    }
                },
                'dateadded desc, announcementid desc',
                '',
                $limit,
                $offset
            );
            foreach ($result['data'] as &$row) {
                $row['id'] = (int) $row['announcementid'];
            }

            return $result;
        });
    }

    public function announcements_get(int $id): array
    {
        return $this->guard('announcements_get', ['is_staff_member'], compact('id'), function () use ($id) {
            $row       = $this->requireRow('announcements', $id, 'Announcement', 'announcementid');
            $row['id'] = (int) $row['announcementid'];

            return ['announcement' => $row];
        });
    }

    public function announcements_create(array $fields): array
    {
        return $this->guard('announcements_create', ['is_admin'], compact('fields'), function () use ($fields) {
            $this->requireFields($fields, ['name', 'message']);

            $data = [
                'name'    => (string) $fields['name'],
                'message' => (string) $fields['message'],
            ];
            // CHECKBOX PRESENCE SEMANTICS in Announcements_model::add().
            foreach (['showtostaff', 'showtousers', 'showname'] as $flag) {
                $data = $this->setCheckboxFlag($data, $flag, ! empty($fields[$flag]));
            }
            if (! isset($data['showtostaff']) && ! isset($data['showtousers'])) {
                $this->fail('An announcement must be shown to staff, to customers, or both (showtostaff / showtousers).');
            }

            $this->CI->load->model('announcements_model');
            $id = $this->CI->announcements_model->add($data);
            if (! $id) {
                $this->fail('Failed to create announcement.');
            }

            return ['created' => true, 'id' => (int) $id];
        });
    }

    public function announcements_update(int $id, array $fields): array
    {
        return $this->guard('announcements_update', ['is_admin'], compact('id', 'fields'), function () use ($id, $fields) {
            $allowed = ['name', 'message', 'showtostaff', 'showtousers', 'showname'];
            $data    = $this->pickAllowed($fields, $allowed);
            if ($data === []) {
                $this->fail('No updatable fields provided (allowed: ' . implode(', ', $allowed) . ').');
            }
            $current = $this->requireRow('announcements', $id, 'Announcement', 'announcementid');

            if (array_key_exists('name', $data) && trim((string) $data['name']) === '') {
                $this->fail('name cannot be empty.');
            }
            foreach (['showtostaff', 'showtousers', 'showname'] as $flag) {
                if (array_key_exists($flag, $data)) {
                    $data[$flag] = empty($data[$flag]) ? 0 : 1;
                }
            }
            $staffAfter = array_key_exists('showtostaff', $data) ? $data['showtostaff'] : (int) $current['showtostaff'];
            $usersAfter = array_key_exists('showtousers', $data) ? $data['showtousers'] : (int) $current['showtousers'];
            if ($staffAfter === 0 && $usersAfter === 0) {
                $this->fail('An announcement must remain visible to staff, to customers, or both.');
            }

            $this->CI->db->where('announcementid', $id);
            $ok = $this->CI->db->update(db_prefix() . 'announcements', $data);

            return ['updated' => (bool) $ok, 'id' => $id];
        });
    }

    public function announcements_delete(int $id, bool $confirm = false): array
    {
        return $this->guard('announcements_delete', ['is_admin'], compact('id', 'confirm'), function () use ($id, $confirm) {
            $this->requireDestructive($confirm);
            $this->requireRow('announcements', $id, 'Announcement', 'announcementid');

            $this->CI->load->model('announcements_model');
            $ok = $this->CI->announcements_model->delete($id);

            return ['deleted' => (bool) $ok, 'id' => $id];
        });
    }

    // ---------------------------------------------------------- custom fields

    public function custom_fields_list(?string $fieldto = null, bool $active_only = true, int $limit = 25, int $offset = 0): array
    {
        return $this->guard('custom_fields_list', ['read', 'custom_fields'], compact('fieldto', 'active_only', 'limit', 'offset'), function (ReadScope $scope) use ($fieldto, $active_only, $limit, $offset) {
            if ($fieldto !== null && $fieldto !== '' && ! in_array($fieldto, self::CUSTOM_FIELD_TARGETS, true)) {
                $this->fail('Unknown fieldto "' . $fieldto . '". Valid values: ' . implode(', ', self::CUSTOM_FIELD_TARGETS) . '.');
            }

            $result = $this->paginate(
                $scope,
                db_prefix() . 'customfields',
                'id, fieldto, name, slug, type, options, required, active, only_admin, show_on_pdf, show_on_client_portal, disalow_client_to_edit, default_value, field_order',
                function ($db) use ($fieldto, $active_only) {
                    if ($fieldto !== null && $fieldto !== '') {
                        $db->where('fieldto', $fieldto);
                    }
                    if ($active_only) {
                        $db->where('active', 1);
                    }
                },
                'fieldto asc, field_order asc, id asc',
                '',
                $limit,
                $offset
            );
            foreach ($result['data'] as &$row) {
                // Perfex stores select/multiselect/checkbox options one per line.
                $options        = array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($row['options'] ?? ''))), static fn ($o) => $o !== '');
                $row['options'] = array_values($options);
                foreach (['required', 'active', 'only_admin', 'show_on_pdf', 'show_on_client_portal', 'disalow_client_to_edit'] as $flag) {
                    $row[$flag] = (int) ($row[$flag] ?? 0);
                }
            }

            return $result;
        });
    }
}

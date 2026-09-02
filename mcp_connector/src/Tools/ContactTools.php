<?php

namespace PerfexMcp\Tools;

use Mcp\Exception\ToolCallException;
use PerfexMcp\Auth\ReadScope;

/**
 * Customer contact tools (people belonging to a customer). Reads follow the
 * customers rule (Visibility 'contacts': without global view, the contacts of
 * customers the staff member administers); writes are gated by the customers
 * capabilities.
 */
final class ContactTools extends AbstractTools
{
    public function contacts_list(?int $customer_id = null, ?string $search = null, int $limit = 25, int $offset = 0): array
    {
        return $this->guard(
            'contacts_list',
            ['read', 'contacts'],
            compact('customer_id', 'search', 'limit', 'offset'),
            fn (ReadScope $scope) => $this->stripListData($this->paginate(
                $scope,
                db_prefix() . 'contacts',
                'id, userid, is_primary, firstname, lastname, email, phonenumber, title, active, datecreated',
                function ($db) use ($customer_id, $search) {
                    if ($customer_id !== null) {
                        $db->where('userid', (int) $customer_id);
                    }
                    if ($search !== null && $search !== '') {
                        $db->group_start()
                            ->like('firstname', $search)
                            ->or_like('lastname', $search)
                            ->or_like('email', $search)
                            ->group_end();
                    }
                },
                'firstname',
                'asc',
                $limit,
                $offset
            ))
        );
    }

    public function contacts_get(int $id): array
    {
        return $this->guard(
            'contacts_get',
            ['read', 'contacts'],
            compact('id'),
            function (ReadScope $scope) use ($id) {
                $this->CI->load->model('clients_model');
                $contact = $this->CI->clients_model->get_contact($id);
                if (! $contact) {
                    throw new ToolCallException('Contact ' . $id . ' not found.');
                }
                $this->assertVisible($scope, 'contacts', $id, 'Contact');

                return ['contact' => $this->stripSecrets($contact)];
            }
        );
    }

    private function stripListData(array $page): array
    {
        $page['data'] = $this->stripSecrets($page['data']);

        return $page;
    }

    private const WRITABLE = [
        'firstname', 'lastname', 'email', 'phonenumber', 'title', 'is_primary',
        'active', 'direction', 'password',
    ];

    public function contacts_create(int $customer_id, array $fields): array
    {
        return $this->guard('contacts_create', ['create', 'customers'], compact('customer_id', 'fields'), function () use ($customer_id, $fields) {
            $this->requireFields($fields, ['firstname', 'lastname', 'email']);
            $data = $this->pickAllowed($fields, self::WRITABLE);
            $this->CI->load->model('clients_model');
            $id = $this->CI->clients_model->add_contact($data, $customer_id);
            if (! $id) {
                $this->fail('Failed to create contact (duplicate email or invalid customer?).');
            }

            return ['created' => true, 'id' => (int) $id, 'customer_id' => $customer_id];
        });
    }

    public function contacts_update(int $id, array $fields): array
    {
        return $this->guard('contacts_update', ['edit', 'customers'], compact('id', 'fields'), function () use ($id, $fields) {
            $data = $this->pickAllowed($fields, self::WRITABLE);
            if ($data === []) {
                $this->fail('No updatable fields provided.');
            }
            $this->CI->load->model('clients_model');
            $this->CI->clients_model->update_contact($data, $id);

            return ['updated' => true, 'id' => $id];
        });
    }

    public function contacts_delete(int $id, bool $confirm = false): array
    {
        return $this->guard('contacts_delete', ['delete', 'customers'], compact('id', 'confirm'), function () use ($id, $confirm) {
            $this->requireDestructive($confirm);
            $this->CI->load->model('clients_model');
            $ok = $this->CI->clients_model->delete_contact($id);

            return ['deleted' => (bool) $ok, 'id' => $id];
        });
    }
}

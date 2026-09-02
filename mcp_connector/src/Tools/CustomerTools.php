<?php

namespace PerfexMcp\Tools;

use Mcp\Exception\ToolCallException;
use PerfexMcp\Auth\PermissionDenied;
use PerfexMcp\Auth\ReadScope;

/**
 * Customer (client) tools.
 *
 * Perfex's customers feature marks view_own as not applicable: without global
 * view a staff member sees the customers they are a "customer admin" of
 * (tblcustomer_admins), may edit those, and is made admin of any customer
 * they create so it stays visible to them. Visibility 'customers' carries the
 * list rule; the write-side checks mirror Clients.php.
 */
final class CustomerTools extends AbstractTools
{
    /**
     * List customers with optional search and pagination.
     *
     * Uses a parameterized read-only query rather than Clients_model::get()
     * (which returns all rows unbounded) so listing stays cheap on large CRMs.
     *
     * @return array
     */
    public function customers_list(?string $search = null, int $limit = 25, int $offset = 0): array
    {
        return $this->guard(
            'customers_list',
            ['read', 'customers'],
            compact('search', 'limit', 'offset'),
            fn (ReadScope $scope) => $this->paginate(
                $scope,
                db_prefix() . 'clients',
                'userid, company, vat, phonenumber, country, city, state, zip, datecreated, active',
                function ($db) use ($search) {
                    if ($search !== null && $search !== '') {
                        $db->group_start()
                            ->like('company', $search)
                            ->or_like('vat', $search)
                            ->or_like('phonenumber', $search)
                            ->or_where('userid', (int) $search)
                            ->group_end();
                    }
                },
                'company',
                'asc',
                $limit,
                $offset
            )
        );
    }

    /**
     * Get a single customer by id, including its contacts.
     *
     * @return array
     */
    public function customers_get(int $id): array
    {
        return $this->guard(
            'customers_get',
            ['read', 'customers'],
            compact('id'),
            function (ReadScope $scope) use ($id) {
                $this->CI->load->model('clients_model');
                $client = $this->CI->clients_model->get($id);

                if (! $client) {
                    throw new ToolCallException('Customer ' . $id . ' not found.');
                }
                $this->assertVisible($scope, 'clients', $id, 'Customer', 'userid');

                $contacts = $this->CI->clients_model->get_contacts($id);

                return [
                    'customer' => $client,
                    'contacts' => $contacts,
                ];
            }
        );
    }

    private const WRITABLE = [
        'company', 'vat', 'phonenumber', 'website', 'address', 'city', 'state',
        'zip', 'country', 'default_language', 'default_currency', 'billing_street',
        'billing_city', 'billing_state', 'billing_zip', 'billing_country',
    ];

    public function customers_create(array $fields): array
    {
        return $this->guard('customers_create', ['create', 'customers'], $fields, function () use ($fields) {
            $this->requireFields($fields, ['company']);
            $data = $this->pickAllowed($fields, self::WRITABLE);
            $this->CI->load->model('clients_model');
            $id = $this->CI->clients_model->add($data);
            if (! $id) {
                $this->fail('Failed to create customer.');
            }

            // Clients.php:96-100: a creator without global view is made the
            // customer's admin, otherwise the record they just created would
            // be invisible to them.
            if (! staff_can('view', 'customers')) {
                $this->CI->db->insert(db_prefix() . 'customer_admins', [
                    'customer_id'   => (int) $id,
                    'staff_id'      => (int) get_staff_user_id(),
                    'date_assigned' => date('Y-m-d H:i:s'),
                ]);
            }

            return ['created' => true, 'id' => (int) $id];
        });
    }

    public function customers_update(int $id, array $fields): array
    {
        // Clients.php:110-114: edit capability, or customer admin of this one.
        return $this->guard('customers_update', ['is_staff_member'], compact('id', 'fields'), function () use ($id, $fields) {
            $data = $this->pickAllowed($fields, self::WRITABLE);
            if ($data === []) {
                $this->fail('No updatable fields provided.');
            }
            if (! staff_can('edit', 'customers') && ! $this->isCustomerAdmin($id)) {
                throw new PermissionDenied('Permission denied: requires "edit" on customers, or being an admin of customer ' . $id . '.');
            }
            $this->CI->load->model('clients_model');
            $this->CI->clients_model->update($data, $id);

            return ['updated' => true, 'id' => $id];
        });
    }

    public function customers_delete(int $id, bool $confirm = false): array
    {
        return $this->guard('customers_delete', ['delete', 'customers'], compact('id', 'confirm'), function () use ($id, $confirm) {
            $this->requireDestructive($confirm);
            $this->CI->load->model('clients_model');
            $ok = $this->CI->clients_model->delete($id);

            return ['deleted' => (bool) $ok, 'id' => $id];
        });
    }

    /** is_customer_admin() without Perfex's per-request object cache. */
    private function isCustomerAdmin(int $customerId): bool
    {
        $this->CI->db->where(['customer_id' => $customerId, 'staff_id' => (int) get_staff_user_id()]);

        return (int) $this->CI->db->count_all_results(db_prefix() . 'customer_admins') > 0;
    }
}

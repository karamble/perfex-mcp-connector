<?php

namespace PerfexMcp\Tools;

use Mcp\Exception\ToolCallException;
use PerfexMcp\Auth\ReadScope;
use PerfexMcp\Auth\Visibility;

/**
 * Lead tools. The leads feature defines only view and delete capabilities.
 * Any staff member may list leads; without global view Perfex shows the ones
 * assigned to them, created by them, or marked public (Visibility 'leads'),
 * and gates editing and converting a lead through the same rule
 * (Leads_model::staff_can_access_lead), which is mirrored here.
 */
final class LeadTools extends AbstractTools
{
    public function leads_list(?string $search = null, ?int $status = null, int $limit = 25, int $offset = 0): array
    {
        return $this->guard(
            'leads_list',
            ['read', 'leads'],
            compact('search', 'status', 'limit', 'offset'),
            fn (ReadScope $scope) => $this->paginate(
                $scope,
                db_prefix() . 'leads',
                'id, name, company, email, phonenumber, status, source, assigned, dateadded, lastcontact, lost, junk, city, country, lead_value',
                function ($db) use ($search, $status) {
                    if ($status !== null) {
                        $db->where('status', (int) $status);
                    }
                    if ($search !== null && $search !== '') {
                        $db->group_start()
                            ->like('name', $search)
                            ->or_like('company', $search)
                            ->or_like('email', $search)
                            ->or_like('phonenumber', $search)
                            ->group_end();
                    }
                },
                'dateadded',
                'desc',
                $limit,
                $offset
            )
        );
    }

    public function leads_get(int $id): array
    {
        return $this->guard(
            'leads_get',
            ['read', 'leads'],
            compact('id'),
            function (ReadScope $scope) use ($id) {
                $this->CI->load->model('leads_model');
                $lead = $this->CI->leads_model->get($id);
                if (! $lead) {
                    throw new ToolCallException('Lead ' . $id . ' not found.');
                }
                $this->assertVisible($scope, 'leads', $id, 'Lead');

                return ['lead' => $this->stripSecrets($lead)];
            }
        );
    }

    private const WRITABLE = [
        'name', 'title', 'company', 'description', 'country', 'zip', 'city',
        'state', 'address', 'assigned', 'status', 'source', 'email', 'website',
        'phonenumber', 'default_language', 'lead_value', 'client_id',
    ];

    public function leads_create(array $fields): array
    {
        // Leads have no create capability; gate by staff membership.
        return $this->guard('leads_create', ['is_staff_member'], $fields, function () use ($fields) {
            $this->requireFields($fields, ['name', 'source', 'status']);
            $data = $this->pickAllowed($fields, self::WRITABLE);
            $this->CI->load->model('leads_model');
            $id = $this->CI->leads_model->add($data);
            if (! $id) {
                $this->fail('Failed to create lead.');
            }

            return ['created' => true, 'id' => (int) $id];
        });
    }

    public function leads_update(int $id, array $fields): array
    {
        // Perfex gates lead editing by visibility (staff_can_access_lead),
        // not by a capability - the one module where a write is row-scoped.
        return $this->guard('leads_update', ['read', 'leads'], compact('id', 'fields'), function (ReadScope $scope) use ($id, $fields) {
            $data = $this->pickAllowed($fields, self::WRITABLE);
            if ($data === []) {
                $this->fail('No updatable fields provided.');
            }
            $this->requireVisibleRow($scope, 'leads', $id, 'Lead');
            $this->CI->load->model('leads_model');
            $this->CI->leads_model->update($data, $id);

            return ['updated' => true, 'id' => $id];
        });
    }

    public function leads_convert_to_customer(int $id): array
    {
        return $this->guard('leads_convert_to_customer', ['create', 'customers'], compact('id'), function () use ($id) {
            // Conversion needs the customer capability AND access to the lead.
            $this->requireVisibleRow(Visibility::scope('leads'), 'leads', $id, 'Lead');
            $this->CI->load->model('leads_model');
            $clientId = $this->CI->leads_model->auto_convert_lead_to_customer($id);
            if (! $clientId) {
                $this->fail('Lead could not be converted (already converted, missing required fields, or auto-convert disabled).');
            }

            return ['converted' => true, 'lead_id' => $id, 'customer_id' => (int) $clientId];
        });
    }

    public function leads_delete(int $id, bool $confirm = false): array
    {
        return $this->guard('leads_delete', ['delete', 'leads'], compact('id', 'confirm'), function () use ($id, $confirm) {
            $this->requireDestructive($confirm);
            $this->CI->load->model('leads_model');
            $ok = $this->CI->leads_model->delete($id);

            return ['deleted' => (bool) $ok, 'id' => $id];
        });
    }
}

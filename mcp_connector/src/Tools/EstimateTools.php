<?php

namespace PerfexMcp\Tools;

use Mcp\Exception\ToolCallException;
use PerfexMcp\Auth\ReadScope;

final class EstimateTools extends AbstractTools
{
    public function estimates_list(?int $customer_id = null, ?string $status = null, ?string $search = null, int $limit = 25, int $offset = 0): array
    {
        return $this->guard(
            'estimates_list',
            ['read', 'estimates'],
            compact('customer_id', 'status', 'search', 'limit', 'offset'),
            function (ReadScope $scope) use ($customer_id, $status, $search, $limit, $offset) {
                return $this->paginate(
                    $scope,
                    db_prefix() . 'estimates',
                    'id, clientid, formatted_number, date, expirydate, currency, subtotal, total, status, invoiceid, reference_no',
                    function ($db) use ($customer_id, $status, $search) {
                        if ($customer_id !== null) {
                            $db->where('clientid', (int) $customer_id);
                        }
                        if ($status !== null && $status !== '') {
                            $db->where('status', (int) $status);
                        }
                        if ($search !== null && $search !== '') {
                            $db->group_start()->like('formatted_number', $search)->or_like('reference_no', $search)->group_end();
                        }
                    },
                    'date',
                    'desc',
                    $limit,
                    $offset
                );
            }
        );
    }

    public function estimates_get(int $id): array
    {
        return $this->guard(
            'estimates_get',
            ['read', 'estimates'],
            compact('id'),
            function (ReadScope $scope) use ($id) {
                $this->CI->load->model('estimates_model');
                $estimate = $this->CI->estimates_model->get($id);
                if (! $estimate) {
                    throw new ToolCallException('Estimate ' . $id . ' not found.');
                }
                $this->assertVisible($scope, 'estimates', $id, 'Estimate');

                return ['estimate' => $this->stripSecrets($estimate)];
            }
        );
    }

    /**
     * Create a DRAFT estimate. Per-item tax and discounts are not handled here.
     */
    public function estimates_create(int $customer_id, array $items, ?string $date = null, ?string $expirydate = null, int $currency = 0): array
    {
        return $this->guard('estimates_create', ['create', 'estimates'], compact('customer_id', 'items', 'date', 'expirydate', 'currency'), function () use ($customer_id, $items, $date, $expirydate, $currency) {
            [$newitems, $subtotal] = $this->buildSalesItems($items);

            $data = [
                'clientid'         => $customer_id,
                'date'             => $date !== null ? to_sql_date($date) : date('Y-m-d'),
                'expirydate'       => $expirydate !== null ? to_sql_date($expirydate) : '',
                'currency'         => $currency,
                'status'           => 1, // draft
                'subtotal'         => $subtotal,
                'total'            => $subtotal,
                'newitems'         => $newitems,
                'billing_street'   => '',
                'billing_city'     => '',
                'billing_state'    => '',
                'billing_zip'      => '',
                'billing_country'  => 0,
                'shipping_street'  => '',
                'shipping_city'    => '',
                'shipping_state'   => '',
                'shipping_zip'     => '',
                'shipping_country' => 0,
                'show_quantity_as' => 1,
            ];

            $this->CI->load->model('estimates_model');
            $id = $this->CI->estimates_model->add($data);
            if (! $id) {
                $this->fail('Failed to create estimate.');
            }

            return ['created' => true, 'id' => (int) $id, 'status' => 'draft', 'subtotal' => $subtotal];
        });
    }

    public function estimates_update(int $id, array $fields): array
    {
        return $this->guard('estimates_update', ['edit', 'estimates'], compact('id', 'fields'), function () use ($id, $fields) {
            $data = $this->pickAllowed($fields, ['expirydate', 'clientnote', 'adminnote', 'status', 'terms', 'reference_no', 'sale_agent']);
            if ($data === []) {
                $this->fail('No updatable fields provided.');
            }
            $this->CI->load->model('estimates_model');
            $ok = $this->CI->estimates_model->update($data, $id);

            return ['updated' => (bool) $ok, 'id' => $id];
        });
    }

    public function estimates_send(int $id, ?string $cc = null): array
    {
        return $this->guard('estimates_send', ['view', 'estimates'], compact('id', 'cc'), function () use ($id, $cc) {
            $this->CI->load->model('estimates_model');
            $ok = $this->CI->estimates_model->send_estimate_to_client($id, '', true, $cc ?? '', true);

            return ['sent' => (bool) $ok, 'id' => $id];
        });
    }

    public function estimates_convert_to_invoice(int $id): array
    {
        return $this->guard('estimates_convert_to_invoice', ['create', 'invoices'], compact('id'), function () use ($id) {
            $this->CI->load->model('estimates_model');
            $invoiceId = $this->CI->estimates_model->convert_to_invoice($id, false, true);
            if (! $invoiceId) {
                $this->fail('Failed to convert estimate to invoice.');
            }

            return ['converted' => true, 'estimate_id' => $id, 'invoice_id' => (int) $invoiceId];
        });
    }

    public function estimates_delete(int $id, bool $confirm = false): array
    {
        return $this->guard('estimates_delete', ['delete', 'estimates'], compact('id', 'confirm'), function () use ($id, $confirm) {
            $this->requireDestructive($confirm);
            $this->CI->load->model('estimates_model');
            $ok = $this->CI->estimates_model->delete($id);

            return ['deleted' => (bool) $ok, 'id' => $id];
        });
    }
}

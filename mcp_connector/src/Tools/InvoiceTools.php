<?php

namespace PerfexMcp\Tools;

use Mcp\Exception\ToolCallException;
use PerfexMcp\Auth\ReadScope;

final class InvoiceTools extends AbstractTools
{
    public function invoices_list(?int $customer_id = null, ?string $status = null, ?string $search = null, int $limit = 25, int $offset = 0): array
    {
        return $this->guard(
            'invoices_list',
            ['read', 'invoices'],
            compact('customer_id', 'status', 'search', 'limit', 'offset'),
            function (ReadScope $scope) use ($customer_id, $status, $search, $limit, $offset) {
                return $this->paginate(
                    $scope,
                    db_prefix() . 'invoices',
                    'id, clientid, formatted_number, date, duedate, currency, subtotal, total, status, project_id, sale_agent',
                    function ($db) use ($customer_id, $status, $search) {
                        if ($customer_id !== null) {
                            $db->where('clientid', (int) $customer_id);
                        }
                        if ($status !== null && $status !== '') {
                            $db->where('status', (int) $status);
                        }
                        if ($search !== null && $search !== '') {
                            $db->group_start()->like('formatted_number', $search)->or_like('clientnote', $search)->group_end();
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

    public function invoices_get(int $id): array
    {
        return $this->guard(
            'invoices_get',
            ['read', 'invoices'],
            compact('id'),
            function (ReadScope $scope) use ($id) {
                $this->CI->load->model('invoices_model');
                $invoice = $this->CI->invoices_model->get($id);
                if (! $invoice) {
                    throw new ToolCallException('Invoice ' . $id . ' not found.');
                }
                $this->assertVisible($scope, 'invoices', $id, 'Invoice');

                return ['invoice' => $this->stripSecrets($invoice)];
            }
        );
    }

    /**
     * Create a DRAFT invoice. Draft-first is deliberate: it avoids invoice-number
     * collisions and lets a human review before the invoice is finalized/sent.
     * Per-item tax and discounts are not handled in this version.
     *
     * $recurring repeats the invoice every N months (1-12; 0 = one-off). Only
     * the simple month cycle is exposed - Perfex's custom day/week recurrence
     * stays a UI-only feature. $cycles caps how many copies generate (0 = no
     * limit) and is only meaningful together with $recurring.
     *
     * $payment_modes selects which payment methods the invoice offers (offline
     * mode ids from Setup > Payment Modes, and/or gateway ids like "stripe").
     * Omitted means none selected, matching the admin form's default for a new
     * invoice. Note that offline modes can print their bank/payment details on
     * the invoice, so pick deliberately.
     */
    public function invoices_create(int $customer_id, array $items, ?string $date = null, ?string $duedate = null, int $currency = 0, int $recurring = 0, int $cycles = 0, ?array $payment_modes = null): array
    {
        return $this->guard('invoices_create', ['create', 'invoices'], compact('customer_id', 'items', 'date', 'duedate', 'currency', 'recurring', 'cycles', 'payment_modes'), function () use ($customer_id, $items, $date, $duedate, $currency, $recurring, $cycles, $payment_modes) {
            $this->validateRecurring($recurring, $cycles);
            $modes = $payment_modes === null ? [] : $this->normalizePaymentModes($payment_modes);
            [$newitems, $subtotal] = $this->buildSalesItems($items);

            $this->CI->load->model('clients_model');
            $client = $this->CI->clients_model->get($customer_id);
            if (! $client) {
                throw new ToolCallException('Customer ' . $customer_id . ' not found.');
            }

            $data = [
                'clientid'              => $customer_id,
                'date'                  => $date !== null ? to_sql_date($date) : date('Y-m-d'),
                'duedate'               => $duedate !== null ? to_sql_date($duedate) : date('Y-m-d'),
                'currency'              => $currency,
                'status'                => 6, // draft
                'save_as_draft'         => true,
                'subtotal'              => $subtotal,
                'total'                 => $subtotal,
                'newitems'              => $newitems,
                'recurring'             => $recurring,
                'custom_recurring'      => 0,
                'cycles'                => $recurring > 0 ? $cycles : 0,
                // Billing/shipping and the predefined terms + client note come
                // from the customer and the invoice defaults, the same way
                // Perfex fills them when a client is picked in the admin form
                // (see subscriptions_helper). Without this the created invoice
                // carries no address and no terms on its PDF.
                'billing_street'        => clear_textarea_breaks($client->billing_street),
                'billing_city'          => $client->billing_city,
                'billing_state'         => $client->billing_state,
                'billing_zip'           => $client->billing_zip,
                'billing_country'       => $client->billing_country,
                'shipping_street'       => clear_textarea_breaks($client->shipping_street),
                'shipping_city'         => $client->shipping_city,
                'shipping_state'        => $client->shipping_state,
                'shipping_zip'          => $client->shipping_zip,
                'shipping_country'      => $client->shipping_country,
                'terms'                 => clear_textarea_breaks(get_option('predefined_terms_invoice')),
                'clientnote'            => clear_textarea_breaks(get_option('predefined_clientnote_invoice')),
                'show_quantity_as'      => 1,
                'allowed_payment_modes' => $modes,
            ];
            if (! empty($client->shipping_street)) {
                $data['show_shipping_on_invoice'] = 1;
                $data['include_shipping']         = 1;
            }

            $this->CI->load->model('invoices_model');
            $id = $this->CI->invoices_model->add($data);
            if (! $id) {
                $this->fail('Failed to create invoice.');
            }

            return ['created' => true, 'id' => (int) $id, 'status' => 'draft', 'subtotal' => $subtotal];
        });
    }

    public function invoices_update(int $id, array $fields): array
    {
        return $this->guard('invoices_update', ['edit', 'invoices'], compact('id', 'fields'), function () use ($id, $fields) {
            // Safe scalar fields only; line-item editing is out of scope here.
            //
            // Deliberately a direct column update, NOT invoices_model->update():
            // the model method is written for the full admin form and resets
            // every absent form field - a scalar-only call through it wipes the
            // invoice's recurrence, allowed_payment_modes (the pay button) and
            // billing address. Scalars touch only their own columns here.
            $allowed = ['duedate', 'clientnote', 'adminnote', 'status', 'terms', 'sale_agent', 'recurring', 'cycles', 'payment_modes',
                'billing_street', 'billing_city', 'billing_state', 'billing_zip', 'billing_country',
                'shipping_street', 'shipping_city', 'shipping_state', 'shipping_zip', 'shipping_country',
                'show_shipping_on_invoice', 'include_shipping'];
            $data = $this->pickAllowed($fields, $allowed);
            if ($data === []) {
                $this->fail('No updatable fields provided (allowed: ' . implode(', ', $allowed) . ').');
            }

            $this->CI->load->model('invoices_model');
            $invoice = $this->CI->invoices_model->get($id);
            if (! $invoice) {
                throw new ToolCallException('Invoice ' . $id . ' not found.');
            }

            if (array_key_exists('recurring', $data)) {
                $this->validateRecurring((int) $data['recurring'], (int) ($data['cycles'] ?? 0));
                // Mirror the model's semantics for the simple month cycle.
                $data['recurring']        = (int) $data['recurring'];
                $data['custom_recurring'] = 0;
                $data['recurring_type']   = null;
                if ($data['recurring'] === 0) {
                    // Recurrence switched off: clear the cycle state too.
                    $data['cycles']              = 0;
                    $data['total_cycles']        = 0;
                    $data['last_recurring_date'] = null;
                }
            } elseif (array_key_exists('cycles', $data)) {
                if ((int) $invoice->recurring === 0) {
                    $this->fail('cycles only applies to a recurring invoice; set recurring first.');
                }
                $data['cycles'] = (int) $data['cycles'];
            }
            if (array_key_exists('duedate', $data)) {
                $data['duedate'] = to_sql_date($data['duedate']);
            }
            foreach (['billing_street', 'shipping_street'] as $streetField) {
                if (array_key_exists($streetField, $data)) {
                    $data[$streetField] = clear_textarea_breaks((string) $data[$streetField]);
                }
            }
            if (array_key_exists('payment_modes', $data)) {
                if (! is_array($data['payment_modes'])) {
                    $this->fail('payment_modes must be an array of mode ids (empty array clears them).');
                }
                // The column holds a serialized list; the model does this for
                // form posts, and this path writes the column directly.
                $data['allowed_payment_modes'] = serialize($this->normalizePaymentModes($data['payment_modes']));
                unset($data['payment_modes']);
            }

            $this->CI->db->where('id', $id);
            $ok = $this->CI->db->update(db_prefix() . 'invoices', $data);

            return ['updated' => (bool) $ok, 'id' => $id];
        });
    }

    /**
     * Validate payment mode ids against this install's offline modes and
     * payment gateways, so a typo cannot silently produce an invoice nobody
     * can pay. Returns a de-duplicated list of string ids.
     *
     * @param array<int, mixed> $modes
     * @return array<int, string>
     */
    private function normalizePaymentModes(array $modes): array
    {
        $this->CI->load->model('payment_modes_model');
        $valid = [];
        foreach ($this->CI->payment_modes_model->get('', [], true) as $mode) {
            $id = is_array($mode) ? ($mode['id'] ?? null) : ($mode->id ?? null);
            if ($id !== null && $id !== '') {
                $valid[(string) $id] = true;
            }
        }

        $out = [];
        foreach ($modes as $mode) {
            if (! is_scalar($mode) || $mode === '') {
                $this->fail('payment_modes entries must be non-empty mode ids.');
            }
            $id = (string) $mode;
            if (! isset($valid[$id])) {
                $this->fail('Unknown payment mode "' . $id . '". Available: ' . implode(', ', array_keys($valid)) . '.');
            }
            if (! in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * Recurrence bounds shared by create and update: repeat every 1-12 months
     * (0 = off), a non-negative generation cap, and no cap without recurrence.
     */
    private function validateRecurring(int $recurring, int $cycles): void
    {
        if ($recurring < 0 || $recurring > 12) {
            $this->fail('recurring must be 0-12 (repeat every N months; 0 = not recurring).');
        }
        if ($cycles < 0) {
            $this->fail('cycles must be >= 0 (0 = no limit).');
        }
        if ($recurring === 0 && $cycles > 0) {
            $this->fail('cycles requires recurring to be set.');
        }
    }

    public function invoices_send(int $id, ?string $cc = null): array
    {
        return $this->guard('invoices_send', ['view', 'invoices'], compact('id', 'cc'), function () use ($id, $cc) {
            $this->CI->load->model('invoices_model');
            // $manually=true emails the customer's default invoice contacts.
            $ok = $this->CI->invoices_model->send_invoice_to_client($id, '', true, $cc ?? '', true);

            return ['sent' => (bool) $ok, 'id' => $id];
        });
    }

    public function invoices_delete(int $id, bool $confirm = false): array
    {
        return $this->guard('invoices_delete', ['delete', 'invoices'], compact('id', 'confirm'), function () use ($id, $confirm) {
            $this->requireDestructive($confirm);
            $this->CI->load->model('invoices_model');
            $ok = $this->CI->invoices_model->delete($id);

            return ['deleted' => (bool) $ok, 'id' => $id];
        });
    }
}

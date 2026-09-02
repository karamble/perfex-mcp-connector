<?php

namespace PerfexMcp\Tools;

use PerfexMcp\Auth\ReadScope;

/**
 * Credit notes: money owed BACK to a customer, applied against invoices or
 * refunded. Statuses: 1 open, 2 closed, 3 void.
 *
 * Remaining credit is never stored: remaining = total - applied - refunded,
 * from tblcredits and tblcreditnote_refunds. Upstream traps this class
 * absorbs:
 *  - Credit_notes_model::add() never runs to_sql_date() on date (unlike
 *    expenses/contracts) - it expects Y-m-d, so create passes the validated
 *    ISO string straight through. Do not "harmonize" with ExpenseTools.
 *  - subtotal/total come from the admin form's JavaScript; the server only
 *    recomputes total_tax. Create supplies them from buildSalesItems().
 *  - save_and_send is checkbox presence and emails every contact with
 *    credit_note_emails=1; it is never forwarded.
 *  - map_shipping_columns() runs on add AND update with presence semantics
 *    (include_shipping => 0 sets 1; an absent key NULLs every posted
 *    shipping field). Create posts shipping all-or-nothing; updates write
 *    columns directly so the mapper never runs.
 *  - Credit_notes_model::update() calls update_credit_note_status(), which
 *    only ever emits 1 or 2 - it silently UN-VOIDS a voided note. Updates
 *    here are direct, refuse voided notes unless allow_voided_edit, and
 *    never write status.
 *  - apply_credits() permanently assigns a real number to a DRAFT invoice
 *    and does NOT update the invoice's own status; the tool requires an
 *    explicit acknowledgement for drafts and calls update_invoice_status().
 *  - The model's delete() cascades and decrements the global
 *    next_credit_note_number; only the CONTROLLER refuses when credits are
 *    used or the note is closed. The delete tool replicates that guard.
 *
 * Never writable through this class: status (use credit_notes_void),
 * total_tax, number, prefix, number_format, formatted_number, subtotal,
 * total, discount_*, addedfrom, datecreated, and line items after create.
 */
final class CreditNoteTools extends AbstractTools
{
    private const COLUMNS = 'id, clientid, number, prefix, formatted_number, date, datecreated, currency, subtotal, '
        . 'total_tax, total, discount_total, status, project_id, reference_no, addedfrom';

    private const STATUSES = [1 => 'open', 2 => 'closed', 3 => 'void'];

    /** Invoices_model: 1 unpaid, 3 partially paid, 4 overdue, 6 draft. */
    private const CREDITABLE_INVOICE_STATUSES = [1, 3, 4, 6];

    private const INVOICE_STATUS_DRAFT = 6;

    private const SHIPPING_FIELDS = ['street', 'city', 'state', 'zip', 'country'];

    // ------------------------------------------------------------------ reads

    public function credit_notes_list(
        ?int $customer_id = null,
        ?int $status = null,
        ?string $date_from = null,
        ?string $date_to = null,
        ?string $search = null,
        int $limit = 25,
        int $offset = 0
    ): array {
        $args = compact('customer_id', 'status', 'date_from', 'date_to', 'search', 'limit', 'offset');

        return $this->guard('credit_notes_list', ['read', 'credit_notes'], $args, function (ReadScope $scope) use ($customer_id, $status, $date_from, $date_to, $search, $limit, $offset) {
            foreach (['date_from' => $date_from, 'date_to' => $date_to] as $name => $value) {
                if ($value !== null) {
                    $this->isoDate($value, $name);
                }
            }
            if ($status !== null && ! isset(self::STATUSES[$status])) {
                $this->fail('status must be 1 (open), 2 (closed) or 3 (void).');
            }

            $result = $this->paginate(
                $scope,
                db_prefix() . 'creditnotes',
                self::COLUMNS,
                function ($db) use ($customer_id, $status, $date_from, $date_to, $search) {
                    if ($customer_id !== null) {
                        $db->where('clientid', $customer_id);
                    }
                    if ($status !== null) {
                        $db->where('status', $status);
                    }
                    if ($date_from !== null) {
                        $db->where('date >=', $date_from);
                    }
                    if ($date_to !== null) {
                        $db->where('date <=', $date_to);
                    }
                    if ($search !== null && $search !== '') {
                        $db->group_start()->like('formatted_number', $search)->or_like('reference_no', $search)->group_end();
                    }
                },
                'date desc, id desc',
                '',
                $limit,
                $offset
            );
            $result['data'] = $this->decorateLookup($result['data'], 'currencies', 'currency', 'name', 'currency_code');
            $result['data'] = $this->decorateLookup($result['data'], 'clients', 'clientid', 'company', 'customer_name', 'userid');
            foreach ($result['data'] as &$row) {
                $row['status_name'] = self::STATUSES[(int) $row['status']] ?? null;
            }

            return $result;
        });
    }

    public function credit_notes_get(int $id): array
    {
        return $this->guard('credit_notes_get', ['read', 'credit_notes'], compact('id'), function (ReadScope $scope) use ($id) {
            $this->requireVisibleRow($scope, 'creditnotes', $id, 'Credit note');

            // The model's numeric get() is the one place the derived figures
            // (remaining, used, refunded) and the item/refund lists are
            // assembled; it LEFT JOINs currencies, so a bad currency cannot
            // hide the row.
            $this->CI->load->model('credit_notes_model');
            $note = $this->CI->credit_notes_model->get($id);
            if (! $note) {
                $this->fail('Credit note ' . $id . ' not found.');
            }
            $out = (array) $note;
            $out['status_name'] = self::STATUSES[(int) $out['status']] ?? null;
            $out['client']      = is_object($note->client)
                ? ['id' => (int) ($note->client->userid ?? $note->clientid), 'company' => $note->client->company ?? null]
                : null;
            unset($out['attachments']);
            foreach (['clientnote', 'adminnote', 'terms'] as $text) {
                if (isset($out[$text])) {
                    $out[$text] = $this->decodeBreaks($out[$text]);
                }
            }

            return ['credit_note' => $out];
        });
    }

    // ----------------------------------------------------------------- writes

    /**
     * Create an OPEN credit note with line items. Per-item tax and discounts
     * are not handled here (same as invoices/estimates).
     */
    public function credit_notes_create(
        int $customer_id,
        array $items,
        ?string $date = null,
        $currency = null,
        ?string $reference_no = null,
        ?string $clientnote = null,
        ?string $adminnote = null,
        int $project_id = 0,
        ?array $shipping = null
    ): array {
        $args = compact('customer_id', 'items', 'date', 'currency', 'reference_no', 'clientnote', 'adminnote', 'project_id', 'shipping');

        return $this->guard('credit_notes_create', ['create', 'credit_notes'], $args, function () use ($customer_id, $items, $date, $currency, $reference_no, $clientnote, $adminnote, $project_id, $shipping) {
            $client = $this->requireCustomer($customer_id);
            [$newitems, $subtotal] = $this->buildSalesItems($items);

            // No to_sql_date() anywhere on this path: the model stores date
            // verbatim, so the validated ISO string is what gets written.
            $iso = $date !== null ? $this->isoDate($date, 'date') : date('Y-m-d');

            $currencyId = ($currency !== null && $currency !== '')
                ? $this->normalizeCurrency($currency)
                : $this->normalizeCurrency((int) ($client['default_currency'] ?? 0) > 0 ? (int) $client['default_currency'] : null, true);

            $data = [
                'clientid'         => $customer_id,
                'number'           => (int) get_option('next_credit_note_number'),
                'date'             => $iso,
                'currency'         => $currencyId,
                'subtotal'         => $subtotal,
                'total'            => $subtotal,
                'discount_total'   => 0,
                'discount_percent' => 0,
                'discount_type'    => '',
                'adjustment'       => 0,
                'show_quantity_as' => 1,
                'status'           => 1,
                'project_id'       => $project_id > 0 ? $this->requireProjectOf($project_id, $customer_id) : 0,
                'reference_no'     => $reference_no ?? '',
                'clientnote'       => $clientnote !== null ? $this->encodeBreaks($clientnote) : clear_textarea_breaks((string) get_option('predefined_clientnote_credit_note')),
                'terms'            => clear_textarea_breaks((string) get_option('predefined_terms_credit_note')),
                'adminnote'        => $adminnote !== null ? $this->encodeBreaks($adminnote) : '',
                'billing_street'   => clear_textarea_breaks((string) ($client['billing_street'] ?? '')),
                'billing_city'     => (string) ($client['billing_city'] ?? ''),
                'billing_state'    => (string) ($client['billing_state'] ?? ''),
                'billing_zip'      => (string) ($client['billing_zip'] ?? ''),
                'billing_country'  => (int) ($client['billing_country'] ?? 0),
                'newitems'         => $newitems,
            ];

            // map_shipping_columns() is presence-driven: either every
            // shipping column is posted together with include_shipping, or
            // none of them is. Never post include_shipping => 0.
            if ($shipping !== null) {
                foreach (self::SHIPPING_FIELDS as $f) {
                    $data['shipping_' . $f] = $f === 'country' ? (int) ($shipping[$f] ?? 0) : (string) ($shipping[$f] ?? '');
                }
                $data['shipping_street']              = clear_textarea_breaks($data['shipping_street']);
                $data['include_shipping']             = 1;
                $data['show_shipping_on_credit_note'] = 1;
            }

            $this->CI->load->model('credit_notes_model');
            $id = $this->CI->credit_notes_model->add($data);
            if (! $id) {
                $this->fail('Failed to create credit note.');
            }
            $created = $this->requireRow('creditnotes', (int) $id, 'Credit note');

            return ['created' => true, 'id' => (int) $id, 'formatted_number' => $created['formatted_number'], 'status' => 'open', 'subtotal' => $subtotal];
        });
    }

    public function credit_notes_update(int $id, array $fields): array
    {
        return $this->guard('credit_notes_update', ['edit', 'credit_notes'], compact('id', 'fields'), function () use ($id, $fields) {
            $allowed = ['date', 'reference_no', 'clientnote', 'adminnote', 'terms', 'project_id'];
            $data    = $this->pickAllowed($fields, $allowed);
            if ($data === []) {
                $this->fail('No updatable fields provided (allowed: ' . implode(', ', $allowed) . ').');
            }

            $note = $this->requireRow('creditnotes', $id, 'Credit note');
            if ((int) $note['status'] === 3 && empty($fields['allow_voided_edit'])) {
                $this->fail('Credit note ' . $id . ' is void. Pass allow_voided_edit=true to edit it anyway (its status stays void).');
            }

            if (array_key_exists('date', $data)) {
                $data['date'] = $this->isoDate((string) $data['date'], 'date');
            }
            foreach (['clientnote', 'adminnote', 'terms'] as $text) {
                if (array_key_exists($text, $data)) {
                    $data[$text] = $this->encodeBreaks((string) $data[$text]);
                }
            }
            if (array_key_exists('project_id', $data)) {
                $data['project_id'] = (int) $data['project_id'] > 0 ? $this->requireProjectOf((int) $data['project_id'], (int) $note['clientid']) : 0;
            }

            // Direct write: no map_shipping_columns(), no status recompute.
            $this->CI->db->where('id', $id);
            $ok = $this->CI->db->update(db_prefix() . 'creditnotes', $data);

            return ['updated' => (bool) $ok, 'id' => $id];
        });
    }

    /** Void an open, unused credit note. Reopening stays in Perfex. */
    public function credit_notes_void(int $id): array
    {
        return $this->guard('credit_notes_void', ['edit', 'credit_notes'], compact('id'), function () use ($id) {
            $note = $this->requireRow('creditnotes', $id, 'Credit note');
            if ((int) $note['status'] === 3) {
                return ['voided' => false, 'already_void' => true, 'id' => $id];
            }
            if ((int) $note['status'] === 2) {
                $this->fail('Credit note ' . $id . ' is closed (fully used) and cannot be voided.');
            }
            if ($this->creditsUsed($id) > 0) {
                $this->fail('Credit note ' . $id . ' has credits applied to invoices; unapply them first (credit_notes_unapply).');
            }
            if ($this->refunded($id) > 0) {
                $this->fail('Credit note ' . $id . ' has refunds recorded and cannot be voided.');
            }

            // The model's mark() is a bare status write plus a hook - the
            // guards above are the controller's, replicated.
            $this->CI->load->model('credit_notes_model');
            $ok = $this->CI->credit_notes_model->mark($id, 3);

            return ['voided' => (bool) $ok, 'id' => $id];
        });
    }

    public function credit_notes_apply_to_invoice(int $id, int $invoice_id, float $amount, bool $acknowledge_draft_numbering = false): array
    {
        $args = compact('id', 'invoice_id', 'amount', 'acknowledge_draft_numbering');

        return $this->guard('credit_notes_apply_to_invoice', ['edit', 'credit_notes'], $args, function () use ($id, $invoice_id, $amount, $acknowledge_draft_numbering) {
            // Both sides change, so both permissions are required.
            $this->requirePermission(['edit', 'invoices']);

            $note = $this->requireRow('creditnotes', $id, 'Credit note');
            if ((int) $note['status'] !== 1) {
                $this->fail('Credit note ' . $id . ' is ' . (self::STATUSES[(int) $note['status']] ?? 'not open') . '; only open notes can be applied.');
            }
            $invoice = $this->requireRow('invoices', $invoice_id, 'Invoice');
            if ((int) $invoice['clientid'] !== (int) $note['clientid']) {
                $this->fail('Invoice ' . $invoice_id . ' belongs to a different customer than credit note ' . $id . '.');
            }
            if ((int) $invoice['currency'] !== (int) $note['currency']) {
                $this->fail('Invoice ' . $invoice_id . ' and credit note ' . $id . ' are in different currencies.');
            }
            if (! in_array((int) $invoice['status'], self::CREDITABLE_INVOICE_STATUSES, true)) {
                $this->fail('Invoice ' . $invoice_id . ' has status ' . (int) $invoice['status'] . '; credits apply only to unpaid, partially paid, overdue or draft invoices.');
            }
            if ((int) $invoice['status'] === self::INVOICE_STATUS_DRAFT && ! $acknowledge_draft_numbering) {
                $this->fail('Invoice ' . $invoice_id . ' is a DRAFT: applying credit permanently assigns it the next invoice number. Pass acknowledge_draft_numbering=true to proceed.');
            }
            if ($amount <= 0) {
                $this->fail('amount must be greater than 0.');
            }
            $amount    = round($amount, 2);
            $remaining = $this->remaining($id);
            if ($amount > $remaining + 0.00001) {
                $this->fail('amount ' . $amount . ' exceeds the remaining credit ' . $remaining . ' on credit note ' . $id . '.');
            }
            $left = $this->invoiceLeftToPay($invoice);
            if ($amount > $left + 0.00001) {
                $this->fail('amount ' . $amount . ' exceeds the ' . $left . ' left to pay on invoice ' . $invoice_id . '.');
            }

            $this->CI->load->model('credit_notes_model');
            $creditId = $this->CI->credit_notes_model->apply_credits($id, ['invoice_id' => $invoice_id, 'amount' => $amount]);
            if (! $creditId) {
                $this->fail('Failed to apply credit.');
            }
            // apply_credits() recomputes the credit note's status but not
            // the invoice's - the controller does that, so this does too.
            update_invoice_status($invoice_id, true);

            $noteAfter    = $this->requireRow('creditnotes', $id, 'Credit note');
            $invoiceAfter = $this->requireRow('invoices', $invoice_id, 'Invoice');

            return [
                'applied'            => true,
                'credit_note_id'     => $id,
                'invoice_id'         => $invoice_id,
                'applied_credit_id'  => (int) $creditId,
                'amount'             => $amount,
                'credit_note_status' => self::STATUSES[(int) $noteAfter['status']] ?? (int) $noteAfter['status'],
                'invoice_status'     => (int) $invoiceAfter['status'],
                'remaining_credits'  => $this->remaining($id),
            ];
        });
    }

    public function credit_notes_refund_create(int $id, float $amount, string $payment_mode, ?string $refunded_on = null, ?string $note = null): array
    {
        $args = compact('id', 'amount', 'payment_mode', 'refunded_on', 'note');

        return $this->guard('credit_notes_refund_create', ['edit', 'credit_notes'], $args, function () use ($id, $amount, $payment_mode, $refunded_on, $note) {
            $row = $this->requireRow('creditnotes', $id, 'Credit note');
            if ((int) $row['status'] !== 1) {
                $this->fail('Credit note ' . $id . ' is ' . (self::STATUSES[(int) $row['status']] ?? 'not open') . '; only open notes can be refunded.');
            }
            if ($amount <= 0) {
                $this->fail('amount must be greater than 0.');
            }
            $amount    = round($amount, 2);
            $remaining = $this->remaining($id);
            if ($amount > $remaining + 0.00001) {
                $this->fail('amount ' . $amount . ' exceeds the remaining credit ' . $remaining . '.');
            }
            $mode = $this->requirePaymentModeId($payment_mode);
            // Already Y-m-d for the model (the controller converts, the model
            // does not).
            $date = $refunded_on !== null ? $this->isoDate($refunded_on, 'refunded_on') : date('Y-m-d');

            $this->CI->load->model('credit_notes_model');
            $refundId = $this->CI->credit_notes_model->create_refund($id, [
                'staff_id'     => get_staff_user_id(),
                'refunded_on'  => $date,
                'payment_mode' => $mode,
                'amount'       => $amount,
                'note'         => $note ?? '',
            ]);
            if (! $refundId) {
                $this->fail('Failed to record the refund.');
            }
            $after = $this->requireRow('creditnotes', $id, 'Credit note');

            return [
                'refunded'           => true,
                'id'                 => $id,
                'refund_id'          => (int) $refundId,
                'amount'             => $amount,
                'credit_note_status' => self::STATUSES[(int) $after['status']] ?? (int) $after['status'],
                'remaining_credits'  => $this->remaining($id),
            ];
        });
    }

    // ------------------------------------------------------------ destructive

    public function credit_notes_delete(int $id, bool $confirm = false): array
    {
        return $this->guard('credit_notes_delete', ['delete', 'credit_notes'], compact('id', 'confirm'), function () use ($id, $confirm) {
            $this->requireDestructive($confirm);
            $note = $this->requireRow('creditnotes', $id, 'Credit note');

            // The controller's guard, which the model lacks.
            if ($this->creditsUsed($id) > 0) {
                $this->fail('Credit note ' . $id . ' has credits applied to invoices; unapply them first.');
            }
            if ((int) $note['status'] === 2) {
                $this->fail('Credit note ' . $id . ' is closed and cannot be deleted.');
            }

            $this->CI->load->model('credit_notes_model');
            $ok = $this->CI->credit_notes_model->delete($id);

            return ['deleted' => (bool) $ok, 'id' => $id];
        });
    }

    /**
     * Reverse one application of credit. applied_credit_id comes from
     * credit_notes_get -> applied_credits[].id.
     */
    public function credit_notes_unapply(int $id, int $applied_credit_id, int $invoice_id, bool $confirm = false): array
    {
        $args = compact('id', 'applied_credit_id', 'invoice_id', 'confirm');

        return $this->guard('credit_notes_unapply', ['delete', 'credit_notes'], $args, function () use ($id, $applied_credit_id, $invoice_id, $confirm) {
            $this->requireDestructive($confirm);
            $this->requirePermission(['edit', 'invoices']);

            $this->requireRow('creditnotes', $id, 'Credit note');
            $applied = $this->requireRow('credits', $applied_credit_id, 'Applied credit');
            if ((int) $applied['credit_id'] !== $id || (int) $applied['invoice_id'] !== $invoice_id) {
                $this->fail('Applied credit ' . $applied_credit_id . ' links credit note ' . (int) $applied['credit_id']
                    . ' to invoice ' . (int) $applied['invoice_id'] . ', not credit note ' . $id . ' to invoice ' . $invoice_id . '.');
            }

            // delete_applied_credit() recomputes the credit note status AND
            // calls update_invoice_status() itself.
            $this->CI->load->model('credit_notes_model');
            $this->CI->credit_notes_model->delete_applied_credit($applied_credit_id, $id, $invoice_id);

            $this->CI->db->where('id', $applied_credit_id);
            $stillThere = $this->CI->db->get(db_prefix() . 'credits')->row_array();
            if ($stillThere) {
                $this->fail('Failed to remove the applied credit.');
            }
            $noteAfter    = $this->requireRow('creditnotes', $id, 'Credit note');
            $invoiceAfter = $this->requireRow('invoices', $invoice_id, 'Invoice');

            return [
                'unapplied'          => true,
                'credit_note_id'     => $id,
                'invoice_id'         => $invoice_id,
                'amount'             => round((float) $applied['amount'], 2),
                'credit_note_status' => self::STATUSES[(int) $noteAfter['status']] ?? (int) $noteAfter['status'],
                'invoice_status'     => (int) $invoiceAfter['status'],
                'remaining_credits'  => $this->remaining($id),
            ];
        });
    }

    // ---------------------------------------------------------------- helpers

    private function creditsUsed(int $id): float
    {
        $this->CI->db->select_sum('amount');
        $this->CI->db->where('credit_id', $id);
        $row = $this->CI->db->get(db_prefix() . 'credits')->row_array();

        return round((float) ($row['amount'] ?? 0), 2);
    }

    private function refunded(int $id): float
    {
        $this->CI->db->select_sum('amount');
        $this->CI->db->where('credit_note_id', $id);
        $row = $this->CI->db->get(db_prefix() . 'creditnote_refunds')->row_array();

        return round((float) ($row['amount'] ?? 0), 2);
    }

    /** total - applied - refunded, the model's own definition. */
    private function remaining(int $id): float
    {
        $note = $this->requireRow('creditnotes', $id, 'Credit note');

        return round((float) $note['total'] - $this->creditsUsed($id) - $this->refunded($id), 2);
    }

    /** total - payments - credits already applied to the invoice. */
    private function invoiceLeftToPay(array $invoice): float
    {
        $this->CI->db->select_sum('amount');
        $this->CI->db->where('invoiceid', (int) $invoice['id']);
        $paid = (float) ($this->CI->db->get(db_prefix() . 'invoicepaymentrecords')->row_array()['amount'] ?? 0);

        $this->CI->db->select_sum('amount');
        $this->CI->db->where('invoice_id', (int) $invoice['id']);
        $credited = (float) ($this->CI->db->get(db_prefix() . 'credits')->row_array()['amount'] ?? 0);

        return round((float) $invoice['total'] - $paid - $credited, 2);
    }

    private function requireProjectOf(int $projectId, int $customerId): int
    {
        $project = $this->requireRow('projects', $projectId, 'Project');
        if ((int) $project['clientid'] !== $customerId) {
            $this->fail('Project ' . $projectId . ' belongs to customer ' . (int) $project['clientid'] . ', not ' . $customerId . '.');
        }

        return $projectId;
    }
}

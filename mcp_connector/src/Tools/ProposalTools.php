<?php

namespace PerfexMcp\Tools;

use PerfexMcp\Auth\ReadScope;

/**
 * Proposals: the pre-sale document that can target a customer OR a lead.
 *
 * Statuses: 1 open, 2 declined, 3 accepted, 4 sent, 5 revised, 6 draft.
 * Upstream traps this class absorbs:
 *  - Proposals_model::add() reads $data['address'] unconditionally (then
 *    nl2br()s it), treats allow_comments and save_and_send as checkbox
 *    presence, force-sets content to '{proposal_items}', and expects
 *    date/open_till already in Y-m-d (the controller converts, the model
 *    does not). save_and_send EMAILS THE RECIPIENT and is never forwarded.
 *  - subtotal/total/discount_total are computed by the admin form's
 *    JavaScript; the server never recalculates them. Create supplies them
 *    from buildSalesItems(); line items are not editable on update, so they
 *    cannot drift.
 *  - Proposals_model::get() substitutes merge fields into content unless
 *    called for the editor. Reads here are direct; rendering is opt-in and
 *    flagged. content is not writable at all (the template is edited in
 *    Perfex).
 *  - Proposals_model::update() detaches the proposal when rel_type is empty
 *    and wipes tags when tags is ''. Updates here write columns directly and
 *    never touch rel_type/rel_id/tags.
 *  - convert_to_invoice() returns the PROPOSAL id despite its docblock; the
 *    tool re-reads invoice_id.
 *  - Proposals_model::delete() cascades to comments, notes, attachments,
 *    line items, tags and EVERY TASK linked to the proposal.
 *
 * Never writable through this class: content, hash, short_link, signature,
 * acceptance_*, estimate_id, invoice_id, date_converted, pipeline_order,
 * is_expiry_notified, total_tax, rel_type/rel_id after create, tags,
 * addedfrom, datecreated.
 */
final class ProposalTools extends AbstractTools
{
    private const COLUMNS = 'id, subject, rel_type, rel_id, proposal_to, email, phone, date, open_till, currency, '
        . 'subtotal, total_tax, total, discount_total, status, assigned, project_id, estimate_id, invoice_id, '
        . 'date_converted, allow_comments, addedfrom, datecreated';

    private const STATUSES = [1 => 'open', 2 => 'declined', 3 => 'accepted', 4 => 'sent', 5 => 'revised', 6 => 'draft'];

    private const REL_TYPES = ['customer', 'lead'];

    // ------------------------------------------------------------------ reads

    public function proposals_list(
        ?int $customer_id = null,
        ?int $lead_id = null,
        ?int $status = null,
        ?string $search = null,
        ?string $date_from = null,
        ?string $date_to = null,
        int $limit = 25,
        int $offset = 0
    ): array {
        $args = compact('customer_id', 'lead_id', 'status', 'search', 'date_from', 'date_to', 'limit', 'offset');

        return $this->guard('proposals_list', ['read', 'proposals'], $args, function (ReadScope $scope) use ($customer_id, $lead_id, $status, $search, $date_from, $date_to, $limit, $offset) {
            if ($customer_id !== null && $lead_id !== null) {
                $this->fail('Filter by customer_id or lead_id, not both.');
            }
            foreach (['date_from' => $date_from, 'date_to' => $date_to] as $name => $value) {
                if ($value !== null) {
                    $this->isoDate($value, $name);
                }
            }
            if ($status !== null) {
                $this->requireStatus($status);
            }

            $result = $this->paginate(
                $scope,
                db_prefix() . 'proposals',
                self::COLUMNS,
                function ($db) use ($customer_id, $lead_id, $status, $search, $date_from, $date_to) {
                    if ($customer_id !== null) {
                        $db->where(['rel_type' => 'customer', 'rel_id' => $customer_id]);
                    }
                    if ($lead_id !== null) {
                        $db->where(['rel_type' => 'lead', 'rel_id' => $lead_id]);
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
                        $db->group_start()->like('subject', $search)->or_like('proposal_to', $search)->group_end();
                    }
                },
                'date desc, id desc',
                '',
                $limit,
                $offset
            );
            $result['data'] = $this->decorateLookup($result['data'], 'currencies', 'currency', 'name', 'currency_code');
            foreach ($result['data'] as &$row) {
                $row['status_name'] = self::STATUSES[(int) $row['status']] ?? null;
            }

            return $result;
        });
    }

    public function proposals_get(int $id, bool $render_merge_fields = false): array
    {
        return $this->guard('proposals_get', ['read', 'proposals'], compact('id', 'render_merge_fields'), function (ReadScope $scope) use ($id, $render_merge_fields) {
            $proposal = $this->requireVisibleRow($scope, 'proposals', $id, 'Proposal');
            $proposal = $this->stripSecrets($proposal, ['signature', 'acceptance_ip']);

            $proposal['status_name'] = self::STATUSES[(int) $proposal['status']] ?? null;
            $proposal['address']     = $this->decodeBreaks($proposal['address'] ?? '');

            $this->CI->db->where('id', (int) $proposal['currency']);
            $cur                  = $this->CI->db->get(db_prefix() . 'currencies')->row_array();
            $proposal['currency'] = $cur ? ['id' => (int) $cur['id'], 'name' => $cur['name'], 'symbol' => $cur['symbol']] : null;

            $proposal['related'] = null;
            if ($proposal['rel_type'] === 'customer' && (int) $proposal['rel_id'] > 0) {
                $this->CI->db->where('userid', (int) $proposal['rel_id']);
                $c                   = $this->CI->db->get(db_prefix() . 'clients')->row_array();
                $proposal['related'] = $c ? ['type' => 'customer', 'id' => (int) $c['userid'], 'name' => $c['company']] : null;
            } elseif ($proposal['rel_type'] === 'lead' && (int) $proposal['rel_id'] > 0) {
                $this->CI->db->where('id', (int) $proposal['rel_id']);
                $l                   = $this->CI->db->get(db_prefix() . 'leads')->row_array();
                $proposal['related'] = $l ? ['type' => 'lead', 'id' => (int) $l['id'], 'name' => $l['name']] : null;
            }

            $proposal['items'] = $this->lineItems($id);

            $proposal['content_is_rendered'] = false;
            if ($render_merge_fields) {
                $this->CI->load->model('proposals_model');
                $rendered = $this->CI->proposals_model->get($id);
                if ($rendered) {
                    $proposal['content']             = $rendered->content;
                    $proposal['content_is_rendered'] = true;
                    $proposal['warning']             = 'content has merge fields substituted. It is read-only here; the template is edited in Perfex.';
                }
            }

            return ['proposal' => $proposal];
        });
    }

    // ----------------------------------------------------------------- writes

    public function proposals_create(array $fields): array
    {
        return $this->guard('proposals_create', ['create', 'proposals'], compact('fields'), function () use ($fields) {
            $this->requireFields($fields, ['subject', 'rel_type', 'rel_id', 'items']);

            $relType = (string) $fields['rel_type'];
            if (! in_array($relType, self::REL_TYPES, true)) {
                $this->fail('rel_type must be one of: ' . implode(', ', self::REL_TYPES) . '.');
            }
            $relId = (int) $fields['rel_id'];
            if ($relType === 'customer') {
                $related  = $this->requireCustomer($relId);
                $defaults = $this->customerDefaults($related);
            } else {
                $related  = $this->requireRow('leads', $relId, 'Lead');
                $defaults = [
                    'proposal_to' => $related['company'] !== '' && $related['company'] !== null ? $related['company'] : $related['name'],
                    'email'       => (string) ($related['email'] ?? ''),
                    'phone'       => (string) ($related['phonenumber'] ?? ''),
                    'address'     => (string) ($related['address'] ?? ''),
                    'city'        => (string) ($related['city'] ?? ''),
                    'state'       => (string) ($related['state'] ?? ''),
                    'zip'         => (string) ($related['zip'] ?? ''),
                    'country'     => (int) ($related['country'] ?? 0),
                ];
            }

            if (! is_array($fields['items'])) {
                $this->fail('items must be an array of line items.');
            }
            [$newitems, $subtotal] = $this->buildSalesItems($fields['items']);

            $date     = isset($fields['date']) ? $this->isoDate((string) $fields['date'], 'date') : date('Y-m-d');
            $openTill = null;
            if (isset($fields['open_till']) && $fields['open_till'] !== '') {
                $openTill = $this->isoDate((string) $fields['open_till'], 'open_till');
            } elseif ((int) get_option('proposal_due_after') > 0) {
                // Same default the admin form applies.
                $openTill = date('Y-m-d', strtotime('+' . (int) get_option('proposal_due_after') . ' days', strtotime($date)));
            }
            if ($openTill !== null && $openTill < $date) {
                $this->fail('open_till must not be before date.');
            }

            $currencyId = isset($fields['currency']) && $fields['currency'] !== ''
                ? $this->normalizeCurrency($fields['currency'])
                : $this->normalizeCurrency($relType === 'customer' && (int) ($related['default_currency'] ?? 0) > 0 ? (int) $related['default_currency'] : null, true);

            $assigned = 0;
            if (isset($fields['assigned']) && (int) $fields['assigned'] > 0) {
                $assigned = (int) $this->requireRow('staff', (int) $fields['assigned'], 'Staff member', 'staffid')['staffid'];
            }

            $text = static fn (array $f, string $key, string $default): string => isset($f[$key]) ? (string) $f[$key] : $default;

            // Model expectations, every unconditionally-read key present:
            // address always a string; date/open_till already Y-m-d; totals
            // supplied because the server never recomputes them.
            $data = [
                'subject'          => (string) $fields['subject'],
                'rel_type'         => $relType,
                'rel_id'           => $relId,
                'proposal_to'      => $text($fields, 'proposal_to', $defaults['proposal_to']),
                'email'            => $text($fields, 'email', $defaults['email']),
                'phone'            => $text($fields, 'phone', $defaults['phone']),
                'address'          => $text($fields, 'address', $defaults['address']),
                'city'             => $text($fields, 'city', $defaults['city']),
                'state'            => $text($fields, 'state', $defaults['state']),
                'zip'              => $text($fields, 'zip', $defaults['zip']),
                'country'          => isset($fields['country']) ? (int) $fields['country'] : $defaults['country'],
                'date'             => $date,
                'open_till'        => $openTill,
                'currency'         => $currencyId,
                'subtotal'         => $subtotal,
                'total'            => $subtotal,
                'discount_total'   => 0,
                'discount_percent' => 0,
                'discount_type'    => '',
                'adjustment'       => 0,
                'show_quantity_as' => 1,
                'status'           => 6, // draft; send or change_status moves it on
                'assigned'         => $assigned,
                'newitems'         => $newitems,
            ];
            if ($relType === 'customer' && isset($fields['project_id']) && (int) $fields['project_id'] > 0) {
                $data['project_id'] = $this->requireProjectOf((int) $fields['project_id'], $relId);
            }
            // CHECKBOX PRESENCE SEMANTICS; save_and_send is never set.
            $data = $this->setCheckboxFlag($data, 'allow_comments', ! empty($fields['allow_comments']));

            $this->CI->load->model('proposals_model');
            $id = $this->CI->proposals_model->add($data);
            if (! $id) {
                $this->fail('Failed to create proposal.');
            }

            return ['created' => true, 'id' => (int) $id, 'status' => 'draft', 'subtotal' => $subtotal];
        });
    }

    public function proposals_update(int $id, array $fields): array
    {
        return $this->guard('proposals_update', ['edit', 'proposals'], compact('id', 'fields'), function () use ($id, $fields) {
            $allowed = ['subject', 'proposal_to', 'email', 'phone', 'address', 'city', 'state', 'zip', 'country',
                'date', 'open_till', 'currency', 'assigned', 'allow_comments', 'project_id'];
            $data = $this->pickAllowed($fields, $allowed);
            if ($data === []) {
                $this->fail('No updatable fields provided (allowed: ' . implode(', ', $allowed) . ').');
            }

            $proposal = $this->requireRow('proposals', $id, 'Proposal');

            if (array_key_exists('subject', $data) && trim((string) $data['subject']) === '') {
                $this->fail('subject cannot be empty.');
            }
            $dateAfter = $proposal['date'];
            $openAfter = $proposal['open_till'];
            if (array_key_exists('date', $data)) {
                $dateAfter = $data['date'] = $this->isoDate((string) $data['date'], 'date');
            }
            if (array_key_exists('open_till', $data)) {
                $openAfter = $data['open_till'] = $data['open_till'] === '' ? null : $this->isoDate((string) $data['open_till'], 'open_till');
            }
            if ($openAfter !== null && $dateAfter !== null && $openAfter < $dateAfter) {
                $this->fail('open_till must not be before date.');
            }
            if (array_key_exists('currency', $data)) {
                $data['currency'] = $this->normalizeCurrency($data['currency']);
            }
            if (array_key_exists('address', $data)) {
                $data['address'] = $this->encodeBreaks((string) $data['address']);
            }
            if (array_key_exists('country', $data)) {
                $data['country'] = max(0, (int) $data['country']);
            }
            if (array_key_exists('assigned', $data)) {
                $data['assigned'] = (int) $data['assigned'] > 0
                    ? (int) $this->requireRow('staff', (int) $data['assigned'], 'Staff member', 'staffid')['staffid']
                    : 0;
            }
            if (array_key_exists('allow_comments', $data)) {
                $data['allow_comments'] = empty($data['allow_comments']) ? 0 : 1;
            }
            if (array_key_exists('project_id', $data)) {
                if ($proposal['rel_type'] !== 'customer') {
                    $this->fail('project_id only applies to a proposal for a customer.');
                }
                $data['project_id'] = (int) $data['project_id'] > 0 ? $this->requireProjectOf((int) $data['project_id'], (int) $proposal['rel_id']) : null;
            }

            $this->CI->db->where('id', $id);
            $ok = $this->CI->db->update(db_prefix() . 'proposals', $data);

            return ['updated' => (bool) $ok, 'id' => $id];
        });
    }

    /**
     * Direct status write. Perfex's own mark_action_status() additionally
     * notifies staff on accept/decline; that path is customer-portal driven
     * and is not replicated here.
     */
    public function proposals_change_status(int $id, int $status): array
    {
        return $this->guard('proposals_change_status', ['edit', 'proposals'], compact('id', 'status'), function () use ($id, $status) {
            $this->requireStatus($status);
            $this->requireRow('proposals', $id, 'Proposal');

            $this->CI->db->where('id', $id);
            $ok = $this->CI->db->update(db_prefix() . 'proposals', ['status' => $status]);

            return ['updated' => (bool) $ok, 'id' => $id, 'status' => $status, 'status_name' => self::STATUSES[$status]];
        });
    }

    /** Emails the proposal to its recipient (the proposal's email field); a draft becomes sent. */
    public function proposals_send(int $id, ?string $cc = null): array
    {
        return $this->guard('proposals_send', ['view', 'proposals'], compact('id', 'cc'), function () use ($id, $cc) {
            $proposal = $this->requireRow('proposals', $id, 'Proposal');
            if (trim((string) $proposal['email']) === '') {
                $this->fail('Proposal ' . $id . ' has no recipient email; set email via proposals_update first.');
            }

            $this->CI->load->model('proposals_model');
            $ok = $this->CI->proposals_model->send_proposal_to_email($id, true, $cc ?? '');

            return ['sent' => (bool) $ok, 'id' => $id];
        });
    }

    public function proposals_convert_to_invoice(int $id): array
    {
        return $this->guard('proposals_convert_to_invoice', ['create', 'invoices'], compact('id'), function () use ($id) {
            $proposal = $this->requireRow('proposals', $id, 'Proposal');
            if ($proposal['rel_type'] !== 'customer') {
                $this->fail('Only a proposal for a customer can become an invoice; convert the lead to a customer first.');
            }
            if ((int) $proposal['invoice_id'] > 0) {
                $this->fail('Proposal ' . $id . ' was already converted to invoice ' . (int) $proposal['invoice_id'] . '.');
            }

            $this->CI->load->model('proposals_model');
            // The return value is the PROPOSAL id (upstream docblock says
            // otherwise); the invoice id is read back from the row.
            $this->CI->proposals_model->convert_to_invoice($id);

            $after = $this->requireRow('proposals', $id, 'Proposal');
            if ((int) $after['invoice_id'] <= 0) {
                $this->fail('Failed to convert proposal to invoice.');
            }

            return ['converted' => true, 'proposal_id' => $id, 'invoice_id' => (int) $after['invoice_id']];
        });
    }

    // ------------------------------------------------------------ destructive

    public function proposals_delete(int $id, bool $confirm = false): array
    {
        return $this->guard('proposals_delete', ['delete', 'proposals'], compact('id', 'confirm'), function () use ($id, $confirm) {
            $this->requireDestructive($confirm);
            $this->requireRow('proposals', $id, 'Proposal');

            $this->CI->db->where(['rel_type' => 'proposal', 'rel_id' => $id]);
            $tasks = (int) $this->CI->db->count_all_results(db_prefix() . 'tasks');

            $this->CI->load->model('proposals_model');
            $ok = $this->CI->proposals_model->delete($id);

            return ['deleted' => (bool) $ok, 'id' => $id, 'cascade' => ['tasks' => $tasks]];
        });
    }

    // ---------------------------------------------------------------- helpers

    private function requireStatus(int $status): void
    {
        if (! isset(self::STATUSES[$status])) {
            $list = [];
            foreach (self::STATUSES as $k => $v) {
                $list[] = $k . '=' . $v;
            }
            $this->fail('status must be one of: ' . implode(', ', $list) . '.');
        }
    }

    /** Line items with their taxes, the shape sales documents share. */
    private function lineItems(int $proposalId): array
    {
        $this->CI->db->where(['rel_type' => 'proposal', 'rel_id' => $proposalId]);
        $this->CI->db->order_by('item_order', 'asc');
        $items = $this->CI->db->get(db_prefix() . 'itemable')->result_array();
        if ($items === []) {
            return [];
        }
        $this->CI->db->where(['rel_type' => 'proposal', 'rel_id' => $proposalId]);
        $taxes = [];
        foreach ($this->CI->db->get(db_prefix() . 'item_tax')->result_array() as $t) {
            $taxes[(int) $t['itemid']][] = ['taxname' => $t['taxname'], 'taxrate' => $t['taxrate']];
        }
        foreach ($items as &$item) {
            $item['taxes'] = $taxes[(int) $item['id']] ?? [];
        }

        return $items;
    }

    /** What the admin form pre-fills when a customer is picked. */
    private function customerDefaults(array $client): array
    {
        $this->CI->db->where(['userid' => (int) $client['userid'], 'is_primary' => 1]);
        $primary = $this->CI->db->get(db_prefix() . 'contacts')->row_array();

        return [
            'proposal_to' => (string) $client['company'],
            'email'       => (string) ($primary['email'] ?? ''),
            'phone'       => (string) ($client['phonenumber'] ?? ''),
            'address'     => (string) ($client['address'] ?? ''),
            'city'        => (string) ($client['city'] ?? ''),
            'state'       => (string) ($client['state'] ?? ''),
            'zip'         => (string) ($client['zip'] ?? ''),
            'country'     => (int) ($client['country'] ?? 0),
        ];
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

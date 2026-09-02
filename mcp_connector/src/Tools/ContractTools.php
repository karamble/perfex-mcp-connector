<?php

namespace PerfexMcp\Tools;

use PerfexMcp\Auth\ReadScope;

/**
 * Contracts and contract types.
 *
 * A contract has no status, no currency and no line items: it is a subject,
 * a customer, a date range, a single base-currency contract_value and an
 * HTML body (content) that Perfex renders through merge fields. Upstream
 * traps this class absorbs:
 *  - Contracts_model::update() is form-shaped AND inverted: an always-true
 *    guard resets trash and not_visible_to_client to 0 whenever the keys are
 *    absent (silently PUBLISHING the contract to the client portal), while a
 *    present 'trash' => 0 sets trash to 1. It also reads dateend
 *    unconditionally. Updates here never call it; they write columns.
 *  - Contracts_model::add() reads datestart AND dateend unconditionally and
 *    runs to_sql_date() (display format) on both, so create requires both
 *    and hands the model _d($iso) like ExpenseTools does.
 *  - Contracts_model::get() INNER JOINs the customer and substitutes merge
 *    fields into content - blanking any it cannot resolve - so a value read
 *    that way must never be written back. Reads here are direct; rendering
 *    is opt-in (render_merge_fields) and flagged in the response.
 *  - The signed-contract guard in Perfex's controller unsets 'clientid' but
 *    the column is 'client', so upstream never actually freezes the
 *    customer of a signed contract. This class does.
 *  - Contracts_model::delete() cascades to comments, notes, renewals,
 *    attachments (disk unlink) and DELETES EVERY TASK linked to the
 *    contract; the delete tool reports those counts.
 *  - send_contract_to_client() reads its recipients from $_POST and is not
 *    reachable from MCP; sending stays in Perfex.
 *
 * Never writable through this class: hash, short_link, signed, signature,
 * marked_as_signed, acceptance_*, contacts_sent_to, last_sent_at,
 * last_sign_reminder_at, isexpirynotified (reset automatically when dateend
 * changes, mirroring the model), addedfrom, dateadded.
 */
final class ContractTools extends AbstractTools
{
    private const COLUMNS = 'id, subject, client, contract_type, datestart, dateend, contract_value, project_id, '
        . 'trash, not_visible_to_client, signed, marked_as_signed, last_sent_at, addedfrom, dateadded';

    private const FROZEN_WHEN_SIGNED = ['customer_id', 'contract_value', 'datestart', 'dateend'];

    // ------------------------------------------------------------------ reads

    public function contracts_list(
        ?int $customer_id = null,
        ?int $contract_type = null,
        bool $trash = false,
        bool $expired_only = false,
        ?string $search = null,
        int $limit = 25,
        int $offset = 0
    ): array {
        $args = compact('customer_id', 'contract_type', 'trash', 'expired_only', 'search', 'limit', 'offset');

        return $this->guard('contracts_list', ['read', 'contracts'], $args, function (ReadScope $scope) use ($customer_id, $contract_type, $trash, $expired_only, $search, $limit, $offset) {
            $result = $this->paginate(
                $scope,
                db_prefix() . 'contracts',
                self::COLUMNS,
                function ($db) use ($customer_id, $contract_type, $trash, $expired_only, $search) {
                    $db->where('trash', $trash ? 1 : 0);
                    if ($customer_id !== null) {
                        $db->where('client', $customer_id);
                    }
                    if ($contract_type !== null) {
                        $db->where('contract_type', $contract_type);
                    }
                    if ($expired_only) {
                        $db->where('dateend IS NOT NULL', null, false);
                        $db->where('dateend <', date('Y-m-d'));
                    }
                    if ($search !== null && $search !== '') {
                        $db->like('subject', $search);
                    }
                },
                'datestart desc, id desc',
                '',
                $limit,
                $offset
            );
            $result['data'] = $this->decorateLookup($result['data'], 'contracts_types', 'contract_type', 'name', 'type_name');
            $result['data'] = $this->decorateLookup($result['data'], 'clients', 'client', 'company', 'customer_name', 'userid');

            return $result;
        });
    }

    public function contracts_get(int $id, bool $render_merge_fields = false): array
    {
        return $this->guard('contracts_get', ['read', 'contracts'], compact('id', 'render_merge_fields'), function (ReadScope $scope) use ($id, $render_merge_fields) {
            $contract = $this->requireVisibleRow($scope, 'contracts', $id, 'Contract');
            $contract = $this->stripSecrets($contract, ['signature', 'acceptance_ip']);

            $contract['type'] = null;
            if ((int) ($contract['contract_type'] ?? 0) > 0) {
                $this->CI->db->where('id', (int) $contract['contract_type']);
                $type             = $this->CI->db->get(db_prefix() . 'contracts_types')->row_array();
                $contract['type'] = $type ? ['id' => (int) $type['id'], 'name' => $type['name']] : null;
            }

            $this->CI->db->where('userid', (int) $contract['client']);
            $client               = $this->CI->db->get(db_prefix() . 'clients')->row_array();
            $contract['customer'] = $client ? ['id' => (int) $client['userid'], 'company' => $client['company']] : null;
            if ($client === null) {
                $contract['warning'] = 'Customer no longer exists; Contracts_model::get() INNER JOINs the customer, so this contract is INVISIBLE in the Perfex UI.';
            }

            if (! empty($contract['contacts_sent_to'])) {
                $decoded                      = json_decode((string) $contract['contacts_sent_to'], true);
                $contract['contacts_sent_to'] = is_array($decoded) ? $decoded : $contract['contacts_sent_to'];
            }

            $this->CI->db->where(['rel_id' => $id, 'rel_type' => 'contract']);
            $this->CI->db->order_by('id', 'asc');
            $contract['attachments'] = array_map(static fn ($f) => [
                'id'        => (int) $f['id'],
                'file_name' => $f['file_name'],
                'filetype'  => $f['filetype'],
                'dateadded' => $f['dateadded'],
                'staffid'   => (int) $f['staffid'],
            ], $this->CI->db->get(db_prefix() . 'files')->result_array());

            $this->CI->db->where('contractid', $id);
            $this->CI->db->order_by('id', 'desc');
            $contract['renewals'] = $this->CI->db->get(db_prefix() . 'contract_renewals')->result_array();

            $contract['content_is_rendered'] = false;
            if ($render_merge_fields) {
                if ($client === null) {
                    $this->fail('Cannot render merge fields: the customer no longer exists.');
                }
                $this->CI->load->model('contracts_model');
                $rendered = $this->CI->contracts_model->get($id);
                if ($rendered) {
                    $contract['content']             = $rendered->content;
                    $contract['content_is_rendered'] = true;
                    $contract['warning']             = 'content has merge fields substituted (unresolved ones blanked). Never write this value back via contracts_update; call contracts_get without render_merge_fields for the editable template.';
                }
            }

            return ['contract' => $contract];
        });
    }

    public function contract_types_list(int $limit = 25, int $offset = 0): array
    {
        return $this->guard('contract_types_list', ['read', 'contract_types'], compact('limit', 'offset'), function (ReadScope $scope) use ($limit, $offset) {
            // Direct table read: Contract_types_model::get() serves a
            // per-request object cache, so a type created moments ago would
            // be missing from the model's answer.
            return $this->paginate($scope, db_prefix() . 'contracts_types', 'id, name', null, 'name', 'asc', $limit, $offset);
        });
    }

    // ----------------------------------------------------------------- writes

    public function contracts_create(array $fields): array
    {
        return $this->guard('contracts_create', ['create', 'contracts'], compact('fields'), function () use ($fields) {
            $this->requireFields($fields, ['subject', 'customer_id', 'datestart', 'dateend']);

            $customerId = (int) $fields['customer_id'];
            $this->requireCustomer($customerId);
            $start = $this->isoDate((string) $fields['datestart'], 'datestart');
            $end   = $this->isoDate((string) $fields['dateend'], 'dateend');
            if ($end < $start) {
                $this->fail('dateend must not be before datestart.');
            }

            // Every key the model reads unconditionally is present; dates go
            // in as the DISPLAY form because add() runs to_sql_date().
            $data = [
                'subject'        => (string) $fields['subject'],
                'client'         => $customerId,
                'datestart'      => _d($start),
                'dateend'        => _d($end),
                'contract_type'  => null,
                'contract_value' => null,
                'project_id'     => null,
                'description'    => isset($fields['description']) ? (string) $fields['description'] : '',
            ];
            if (isset($fields['contract_type']) && (int) $fields['contract_type'] > 0) {
                $data['contract_type'] = $this->requireContractType((int) $fields['contract_type']);
            }
            if (isset($fields['contract_value'])) {
                $data['contract_value'] = $this->nonNegative($fields['contract_value'], 'contract_value');
            }
            if (isset($fields['project_id']) && (int) $fields['project_id'] > 0) {
                $data['project_id'] = $this->requireProjectOf((int) $fields['project_id'], $customerId);
            }
            // Checkbox-presence semantics in add() (1 or 'on' -> 1, else 0).
            $data = $this->setCheckboxFlag($data, 'not_visible_to_client', ! empty($fields['not_visible_to_client']));

            $this->CI->load->model('contracts_model');
            $id = $this->CI->contracts_model->add($data);
            if (! $id) {
                $this->fail('Failed to create contract.');
            }

            // content is not part of the add() form; Perfex saves it in a
            // separate request. Same here: a direct column write after insert.
            if (isset($fields['content']) && $fields['content'] !== '') {
                $this->CI->db->where('id', (int) $id);
                $this->CI->db->update(db_prefix() . 'contracts', ['content' => (string) $fields['content']]);
            }

            return ['created' => true, 'id' => (int) $id];
        });
    }

    public function contracts_update(int $id, array $fields): array
    {
        return $this->guard('contracts_update', ['edit', 'contracts'], compact('id', 'fields'), function () use ($id, $fields) {
            $allowed = ['subject', 'customer_id', 'contract_type', 'datestart', 'dateend', 'contract_value', 'project_id',
                'description', 'content', 'not_visible_to_client', 'trash'];
            $data = $this->pickAllowed($fields, $allowed);
            if ($data === []) {
                $this->fail('No updatable fields provided (allowed: ' . implode(', ', $allowed) . ').');
            }

            $contract = $this->requireRow('contracts', $id, 'Contract');

            if ((int) $contract['signed'] === 1 || (int) $contract['marked_as_signed'] === 1) {
                foreach (self::FROZEN_WHEN_SIGNED as $frozen) {
                    if (array_key_exists($frozen, $data)) {
                        $this->fail('Contract ' . $id . ' is signed; ' . $frozen . ' cannot change. Unmark it as signed in Perfex first.');
                    }
                }
            }

            if (array_key_exists('subject', $data) && trim((string) $data['subject']) === '') {
                $this->fail('subject cannot be empty.');
            }
            $customerAfter = (int) $contract['client'];
            if (array_key_exists('customer_id', $data)) {
                $customerAfter = (int) $data['customer_id'];
                $this->requireCustomer($customerAfter);
                $data['client'] = $customerAfter;
                unset($data['customer_id']);
            }
            // Direct column writes: the validated ISO string IS the SQL date.
            $startAfter = $contract['datestart'];
            $endAfter   = $contract['dateend'];
            if (array_key_exists('datestart', $data)) {
                $startAfter = $data['datestart'] = $this->isoDate((string) $data['datestart'], 'datestart');
            }
            if (array_key_exists('dateend', $data)) {
                $endAfter = $data['dateend'] = $this->isoDate((string) $data['dateend'], 'dateend');
                if ($endAfter !== $contract['dateend']) {
                    // Mirror the model: a new end date re-arms the expiry reminder.
                    $data['isexpirynotified'] = 0;
                }
            }
            if ($startAfter !== null && $endAfter !== null && $endAfter < $startAfter) {
                $this->fail('dateend must not be before datestart.');
            }
            if (array_key_exists('contract_type', $data)) {
                $data['contract_type'] = (int) $data['contract_type'] > 0 ? $this->requireContractType((int) $data['contract_type']) : null;
            }
            if (array_key_exists('contract_value', $data)) {
                $data['contract_value'] = $this->nonNegative($data['contract_value'], 'contract_value');
            }
            if (array_key_exists('project_id', $data)) {
                $data['project_id'] = (int) $data['project_id'] > 0 ? $this->requireProjectOf((int) $data['project_id'], $customerAfter) : null;
            }
            foreach (['not_visible_to_client', 'trash'] as $flag) {
                if (array_key_exists($flag, $data)) {
                    // Literal 0/1 on a direct write - unlike the model's
                    // presence-only update() path this class never uses.
                    $data[$flag] = empty($data[$flag]) ? 0 : 1;
                }
            }

            $this->CI->db->where('id', $id);
            $ok = $this->CI->db->update(db_prefix() . 'contracts', $data);

            return ['updated' => (bool) $ok, 'id' => $id];
        });
    }

    public function contract_types_create(array $fields): array
    {
        return $this->guard('contract_types_create', ['create', 'contracts'], compact('fields'), function () use ($fields) {
            $this->requireFields($fields, ['name']);
            $name = trim((string) $fields['name']);

            // Idempotent bootstrap, matching expense_categories_create.
            $this->CI->db->where('LOWER(name)', mb_strtolower($name));
            $existing = $this->CI->db->get(db_prefix() . 'contracts_types')->row_array();
            if ($existing) {
                return ['created' => false, 'existing' => true, 'id' => (int) $existing['id']];
            }

            // Contract_types_model::add() inserts its argument verbatim.
            $this->CI->load->model('contract_types_model');
            $id = $this->CI->contract_types_model->add(['name' => $name]);
            if (! $id) {
                $this->fail('Failed to create contract type.');
            }

            return ['created' => true, 'id' => (int) $id];
        });
    }

    // ------------------------------------------------------------ destructive

    public function contracts_delete(int $id, bool $confirm = false): array
    {
        return $this->guard('contracts_delete', ['delete', 'contracts'], compact('id', 'confirm'), function () use ($id, $confirm) {
            $this->requireDestructive($confirm);
            $this->requireRow('contracts', $id, 'Contract');

            // Recorded before the cascade so the audit row says what went.
            $this->CI->db->where(['rel_type' => 'contract', 'rel_id' => $id]);
            $tasks = (int) $this->CI->db->count_all_results(db_prefix() . 'tasks');
            $this->CI->db->where(['rel_type' => 'contract', 'rel_id' => $id]);
            $files = (int) $this->CI->db->count_all_results(db_prefix() . 'files');

            $this->CI->load->model('contracts_model');
            $ok = $this->CI->contracts_model->delete($id);

            return ['deleted' => (bool) $ok, 'id' => $id, 'cascade' => ['tasks' => $tasks, 'files' => $files]];
        });
    }

    // ---------------------------------------------------------------- helpers

    private function requireContractType(int $id): int
    {
        $this->CI->db->where('id', $id);
        if ($this->CI->db->get(db_prefix() . 'contracts_types')->row_array()) {
            return $id;
        }
        $rows = $this->CI->db->order_by('name', 'asc')->get(db_prefix() . 'contracts_types')->result_array();
        $list = array_map(static fn ($t) => $t['id'] . '=' . $t['name'], array_slice($rows, 0, 20));
        $this->fail('Unknown contract type ' . $id . '. ' . ($rows === []
            ? 'No types exist yet - create one with contract_types_create.'
            : 'Available: ' . implode(', ', $list) . '.'));
    }

    /** The project must exist and belong to the contract's customer. */
    private function requireProjectOf(int $projectId, int $customerId): int
    {
        $project = $this->requireRow('projects', $projectId, 'Project');
        if ((int) $project['clientid'] !== $customerId) {
            $this->fail('Project ' . $projectId . ' belongs to customer ' . (int) $project['clientid'] . ', not ' . $customerId . '.');
        }

        return $projectId;
    }

    private function nonNegative($value, string $field): float
    {
        if (! is_numeric($value) || (float) $value < 0) {
            $this->fail($field . ' must be a number of 0 or greater.');
        }

        return round((float) $value, 2);
    }
}

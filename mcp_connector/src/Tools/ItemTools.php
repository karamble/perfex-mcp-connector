<?php

namespace PerfexMcp\Tools;

use PerfexMcp\Auth\ReadScope;

/**
 * Items library: the reusable sales-item catalog (Sales > Items). Rates are
 * in the base currency; per-currency rate columns Perfex adds on the fly
 * (rate_currency_N) are not exposed.
 *
 * Perfex's permission feature "items" has no view_own, so plain
 * ['view', 'items'] is correct here. Invoice_items_model::edit() is
 * form-shaped; updates write columns directly.
 */
final class ItemTools extends AbstractTools
{
    private const COLUMNS = 'id, description, long_description, rate, tax, tax2, unit, group_id';

    // ------------------------------------------------------------------ reads

    public function items_list(?int $group_id = null, ?string $search = null, int $limit = 25, int $offset = 0): array
    {
        return $this->guard('items_list', ['read', 'items'], compact('group_id', 'search', 'limit', 'offset'), function (ReadScope $scope) use ($group_id, $search, $limit, $offset) {
            $result = $this->paginate(
                $scope,
                db_prefix() . 'items',
                self::COLUMNS,
                function ($db) use ($group_id, $search) {
                    if ($group_id !== null) {
                        $db->where('group_id', $group_id);
                    }
                    if ($search !== null && $search !== '') {
                        $db->group_start()->like('description', $search)->or_like('long_description', $search)->group_end();
                    }
                },
                'description',
                'asc',
                $limit,
                $offset
            );
            $result['data'] = $this->decorateLookup($result['data'], 'items_groups', 'group_id', 'name', 'group_name');

            return $result;
        });
    }

    public function items_get(int $id): array
    {
        return $this->guard('items_get', ['view', 'items'], compact('id'), function () use ($id) {
            $item = $this->requireRow('items', $id, 'Item');

            $item['group'] = null;
            if ((int) $item['group_id'] > 0) {
                $this->CI->db->where('id', (int) $item['group_id']);
                $group = $this->CI->db->get(db_prefix() . 'items_groups')->row_array();
                $item['group'] = $group ? ['id' => (int) $group['id'], 'name' => $group['name']] : null;
            }
            foreach (['tax', 'tax2'] as $col) {
                $item[$col . '_detail'] = null;
                if ((int) ($item[$col] ?? 0) > 0) {
                    $this->CI->db->where('id', (int) $item[$col]);
                    $tax = $this->CI->db->get(db_prefix() . 'taxes')->row_array();
                    if ($tax) {
                        $item[$col . '_detail'] = ['id' => (int) $tax['id'], 'name' => $tax['name'], 'taxrate' => $tax['taxrate']];
                    }
                }
            }

            return ['item' => $item];
        });
    }

    // ----------------------------------------------------------------- writes

    public function items_create(array $fields): array
    {
        return $this->guard('items_create', ['create', 'items'], compact('fields'), function () use ($fields) {
            $this->requireFields($fields, ['description']);
            if (! isset($fields['rate'])) {
                $this->fail('Missing required field: rate.');
            }
            $rate = (float) $fields['rate'];
            if ($rate < 0) {
                $this->fail('rate must be 0 or greater.');
            }

            $data = [
                'description'      => (string) $fields['description'],
                'long_description' => isset($fields['long_description']) ? (string) $fields['long_description'] : '',
                'rate'             => $rate,
                'unit'             => isset($fields['unit']) ? (string) $fields['unit'] : '',
                'group_id'         => isset($fields['group_id']) ? $this->requireGroup((int) $fields['group_id']) : 0,
            ];
            foreach (['tax', 'tax2'] as $col) {
                if (isset($fields[$col]) && (int) $fields[$col] > 0) {
                    $data[$col] = $this->requireTax((int) $fields[$col], $col);
                }
            }

            $this->CI->load->model('invoice_items_model');
            $id = $this->CI->invoice_items_model->add($data);
            if (! $id) {
                $this->fail('Failed to create item.');
            }

            return ['created' => true, 'id' => (int) $id];
        });
    }

    public function items_update(int $id, array $fields): array
    {
        return $this->guard('items_update', ['edit', 'items'], compact('id', 'fields'), function () use ($id, $fields) {
            $allowed = ['description', 'long_description', 'rate', 'unit', 'tax', 'tax2', 'group_id'];
            $data    = $this->pickAllowed($fields, $allowed);
            if ($data === []) {
                $this->fail('No updatable fields provided (allowed: ' . implode(', ', $allowed) . ').');
            }
            $this->requireRow('items', $id, 'Item');

            if (array_key_exists('description', $data) && trim((string) $data['description']) === '') {
                $this->fail('description cannot be empty.');
            }
            if (array_key_exists('rate', $data)) {
                $data['rate'] = (float) $data['rate'];
                if ($data['rate'] < 0) {
                    $this->fail('rate must be 0 or greater.');
                }
            }
            if (array_key_exists('group_id', $data)) {
                $data['group_id'] = $this->requireGroup((int) $data['group_id']);
            }
            foreach (['tax', 'tax2'] as $col) {
                if (array_key_exists($col, $data)) {
                    // 0 clears the tax (the column is nullable); anything else
                    // must exist.
                    $data[$col] = (int) $data[$col] > 0 ? $this->requireTax((int) $data[$col], $col) : null;
                }
            }

            $this->CI->db->where('id', $id);
            $ok = $this->CI->db->update(db_prefix() . 'items', $data);

            return ['updated' => (bool) $ok, 'id' => $id];
        });
    }

    // ------------------------------------------------------------ destructive

    public function items_delete(int $id, bool $confirm = false): array
    {
        return $this->guard('items_delete', ['delete', 'items'], compact('id', 'confirm'), function () use ($id, $confirm) {
            $this->requireDestructive($confirm);
            $this->requireRow('items', $id, 'Item');

            // Deleting a catalog item never touches documents that used it:
            // sales line items are copies in tblitemable.
            $this->CI->load->model('invoice_items_model');
            $ok = $this->CI->invoice_items_model->delete($id);

            return ['deleted' => (bool) $ok, 'id' => $id];
        });
    }

    // ---------------------------------------------------------------- helpers

    /** 0 = no group; otherwise the group must exist. */
    private function requireGroup(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }
        $this->CI->db->where('id', $id);
        if ($this->CI->db->get(db_prefix() . 'items_groups')->row_array()) {
            return $id;
        }
        $rows = $this->CI->db->order_by('name', 'asc')->get(db_prefix() . 'items_groups')->result_array();
        $list = array_map(static fn ($g) => $g['id'] . '=' . $g['name'], array_slice($rows, 0, 20));
        $this->fail('Unknown item group ' . $id . '. ' . ($rows === [] ? 'No groups exist; use 0.' : 'Available: ' . implode(', ', $list) . '.'));
    }

    private function requireTax(int $id, string $field): int
    {
        $this->CI->db->where('id', $id);
        if ($this->CI->db->get(db_prefix() . 'taxes')->row_array()) {
            return $id;
        }
        $rows = $this->CI->db->get(db_prefix() . 'taxes')->result_array();
        $list = array_map(static fn ($t) => $t['id'] . '=' . $t['name'] . ' ' . $t['taxrate'] . '%', array_slice($rows, 0, 20));
        $this->fail('Unknown ' . $field . ' id ' . $id . '. ' . ($rows === [] ? 'No taxes are configured in Perfex.' : 'Available: ' . implode(', ', $list) . '.'));
    }
}

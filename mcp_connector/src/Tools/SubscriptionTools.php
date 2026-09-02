<?php

namespace PerfexMcp\Tools;

use PerfexMcp\Auth\ReadScope;

/**
 * Subscriptions: the recurring-billing records Perfex pairs with Stripe.
 *
 * Only the CRUD side is exposed, and it is Stripe-free by construction:
 *  - Subscriptions_model::create()/update() call handleSelectedTax(), which
 *    loads the stripe_core library unconditionally and calls the Stripe API
 *    whenever a stripe_tax_id key is present. Writes here are direct
 *    inserts/updates of the same columns the model writes (created, hash,
 *    created_from), so a store without Stripe configured still works and no
 *    tax key ever reaches Stripe. Local tax_id/tax_id_2 are validated
 *    against tbltaxes instead.
 *  - The subscribe / cancel / resume / invoice-generation lifecycle is
 *    Stripe-only and stays in Perfex.
 *  - Subscriptions_model::get() INNER JOINs currencies and clients, so a
 *    bad currency makes a row vanish from every Perfex list; currency is
 *    validated on every write and reads here are direct.
 *  - Subscriptions_model::delete() calls $this->db->where('subscription_id')
 *    with ONE argument, which CodeIgniter treats as raw SQL - it detaches
 *    EVERY subscription-linked invoice in the install. There is deliberately
 *    no subscriptions_delete tool; do not add one "for symmetry".
 *
 * Perfex's permission feature "subscriptions" has a real view_own (owner
 * column created_from). Never writable through this class: hash,
 * stripe_subscription_id, stripe_tax_id, stripe_tax_id_2, next_billing_cycle,
 * ends_at, status, date_subscribed, in_test_environment, last_sent_at,
 * created, created_from.
 */
final class SubscriptionTools extends AbstractTools
{
    private const COLUMNS = 'id, name, clientid, project_id, currency, date, status, quantity, tax_id, tax_id_2, '
        . 'stripe_plan_id, stripe_subscription_id, next_billing_cycle, ends_at, date_subscribed, created, created_from, '
        . 'in_test_environment, last_sent_at';

    /** Columns that would desync from Stripe once the customer has subscribed. */
    private const FROZEN_WHEN_SUBSCRIBED = ['clientid', 'currency', 'quantity', 'stripe_plan_id', 'tax_id', 'tax_id_2'];

    // ------------------------------------------------------------------ reads

    public function subscriptions_list(?int $customer_id = null, ?int $project_id = null, ?string $status = null, ?string $search = null, int $limit = 25, int $offset = 0): array
    {
        $args = compact('customer_id', 'project_id', 'status', 'search', 'limit', 'offset');

        return $this->guard('subscriptions_list', ['read', 'subscriptions'], $args, function (ReadScope $scope) use ($customer_id, $project_id, $status, $search, $limit, $offset) {
            $result = $this->paginate(
                $scope,
                db_prefix() . 'subscriptions',
                self::COLUMNS,
                function ($db) use ($customer_id, $project_id, $status, $search) {
                    if ($customer_id !== null) {
                        $db->where('clientid', $customer_id);
                    }
                    if ($project_id !== null) {
                        $db->where('project_id', $project_id);
                    }
                    if ($status !== null && $status !== '') {
                        $db->where('status', $status);
                    }
                    if ($search !== null && $search !== '') {
                        $db->like('name', $search);
                    }
                },
                'created desc, id desc',
                '',
                $limit,
                $offset
            );
            $result['data'] = $this->decorateLookup($result['data'], 'currencies', 'currency', 'name', 'currency_code');
            $result['data'] = $this->decorateLookup($result['data'], 'clients', 'clientid', 'company', 'customer_name', 'userid');
            foreach ($result['data'] as &$row) {
                $row['subscribed_on_stripe'] = ($row['stripe_subscription_id'] ?? '') !== '';
                $row                         = $this->stripSecrets($row);
            }

            return $result;
        });
    }

    public function subscriptions_get(int $id): array
    {
        return $this->guard('subscriptions_get', ['read', 'subscriptions'], compact('id'), function (ReadScope $scope) use ($id) {
            $sub = $this->requireVisibleRow($scope, 'subscriptions', $id, 'Subscription');
            $sub = $this->stripSecrets($sub);

            $sub['subscribed_on_stripe'] = ($sub['stripe_subscription_id'] ?? '') !== '';
            foreach (['next_billing_cycle', 'ends_at'] as $unix) {
                $sub[$unix . '_iso'] = ! empty($sub[$unix]) ? date('Y-m-d H:i:s', (int) $sub[$unix]) : null;
            }
            foreach (['description', 'terms'] as $text) {
                if (isset($sub[$text])) {
                    $sub[$text] = $this->decodeBreaks($sub[$text]);
                }
            }

            $this->CI->db->where('id', (int) $sub['currency']);
            $cur             = $this->CI->db->get(db_prefix() . 'currencies')->row_array();
            $sub['currency'] = $cur ? ['id' => (int) $cur['id'], 'name' => $cur['name'], 'symbol' => $cur['symbol']] : null;
            if ($cur === null) {
                $sub['warning'] = 'Currency no longer exists; Subscriptions_model INNER JOINs it, so this subscription is INVISIBLE in the Perfex UI until currency is fixed via subscriptions_update.';
            }

            $this->CI->db->where('userid', (int) $sub['clientid']);
            $client          = $this->CI->db->get(db_prefix() . 'clients')->row_array();
            $sub['customer'] = $client ? ['id' => (int) $client['userid'], 'company' => $client['company']] : null;

            foreach (['tax_id', 'tax_id_2'] as $col) {
                $sub[$col . '_detail'] = null;
                if ((int) $sub[$col] > 0) {
                    $this->CI->db->where('id', (int) $sub[$col]);
                    $tax = $this->CI->db->get(db_prefix() . 'taxes')->row_array();
                    if ($tax) {
                        $sub[$col . '_detail'] = ['id' => (int) $tax['id'], 'name' => $tax['name'], 'taxrate' => $tax['taxrate']];
                    }
                }
            }

            return ['subscription' => $sub];
        });
    }

    // ----------------------------------------------------------------- writes

    public function subscriptions_create(array $fields): array
    {
        return $this->guard('subscriptions_create', ['create', 'subscriptions'], compact('fields'), function () use ($fields) {
            $this->requireFields($fields, ['name', 'customer_id']);
            $customerId = (int) $fields['customer_id'];
            $client     = $this->requireCustomer($customerId);

            $currencyId = isset($fields['currency']) && $fields['currency'] !== ''
                ? $this->normalizeCurrency($fields['currency'])
                : $this->normalizeCurrency((int) ($client['default_currency'] ?? 0) > 0 ? (int) $client['default_currency'] : null, true);

            $quantity = isset($fields['quantity']) ? (int) $fields['quantity'] : 1;
            if ($quantity < 1) {
                $this->fail('quantity must be at least 1.');
            }

            // Everything the model's create() would set, minus Stripe.
            $data = [
                'name'                   => (string) $fields['name'],
                'description'            => isset($fields['description']) ? $this->encodeBreaks((string) $fields['description']) : null,
                'description_in_item'    => empty($fields['description_in_item']) ? 0 : 1,
                'clientid'               => $customerId,
                'date'                   => isset($fields['date']) && $fields['date'] !== '' ? $this->isoDate((string) $fields['date'], 'date') : null,
                'terms'                  => isset($fields['terms']) ? $this->encodeBreaks((string) $fields['terms']) : null,
                'currency'               => $currencyId,
                'tax_id'                 => isset($fields['tax_id']) ? $this->requireTaxOrZero((int) $fields['tax_id'], 'tax_id') : 0,
                'tax_id_2'               => isset($fields['tax_id_2']) ? $this->requireTaxOrZero((int) $fields['tax_id_2'], 'tax_id_2') : 0,
                'stripe_plan_id'         => isset($fields['stripe_plan_id']) ? trim((string) $fields['stripe_plan_id']) : null,
                'stripe_subscription_id' => '',
                'quantity'               => $quantity,
                'project_id'             => isset($fields['project_id']) && (int) $fields['project_id'] > 0 ? $this->requireProjectOf((int) $fields['project_id'], $customerId) : 0,
                'hash'                   => app_generate_hash(),
                'created'                => date('Y-m-d H:i:s'),
                'created_from'           => get_staff_user_id(),
            ];

            $this->CI->db->insert(db_prefix() . 'subscriptions', $data);
            $id = (int) $this->CI->db->insert_id();
            if ($id <= 0) {
                $this->fail('Failed to create subscription.');
            }

            return ['created' => true, 'id' => $id];
        });
    }

    public function subscriptions_update(int $id, array $fields): array
    {
        return $this->guard('subscriptions_update', ['edit', 'subscriptions'], compact('id', 'fields'), function () use ($id, $fields) {
            $allowed = ['name', 'customer_id', 'currency', 'date', 'terms', 'description', 'description_in_item',
                'project_id', 'quantity', 'tax_id', 'tax_id_2', 'stripe_plan_id'];
            $data = $this->pickAllowed($fields, $allowed);
            if ($data === []) {
                $this->fail('No updatable fields provided (allowed: ' . implode(', ', $allowed) . ').');
            }

            $sub = $this->requireRow('subscriptions', $id, 'Subscription');

            if (array_key_exists('customer_id', $data)) {
                $data['clientid'] = (int) $data['customer_id'];
                unset($data['customer_id']);
            }
            if (($sub['stripe_subscription_id'] ?? '') !== '') {
                foreach (self::FROZEN_WHEN_SUBSCRIBED as $frozen) {
                    if (array_key_exists($frozen, $data)) {
                        $this->fail('Subscription ' . $id . ' is active on Stripe; ' . $frozen . ' cannot change here. Manage it in Perfex.');
                    }
                }
            }

            $customerAfter = (int) $sub['clientid'];
            if (array_key_exists('name', $data) && trim((string) $data['name']) === '') {
                $this->fail('name cannot be empty.');
            }
            if (array_key_exists('clientid', $data)) {
                $customerAfter = $data['clientid'];
                $this->requireCustomer($customerAfter);
            }
            if (array_key_exists('currency', $data)) {
                $data['currency'] = $this->normalizeCurrency($data['currency']);
            }
            if (array_key_exists('date', $data)) {
                $data['date'] = $data['date'] === '' ? null : $this->isoDate((string) $data['date'], 'date');
            }
            foreach (['description', 'terms'] as $text) {
                if (array_key_exists($text, $data)) {
                    $data[$text] = $this->encodeBreaks((string) $data[$text]);
                }
            }
            if (array_key_exists('description_in_item', $data)) {
                $data['description_in_item'] = empty($data['description_in_item']) ? 0 : 1;
            }
            if (array_key_exists('quantity', $data)) {
                $data['quantity'] = (int) $data['quantity'];
                if ($data['quantity'] < 1) {
                    $this->fail('quantity must be at least 1.');
                }
            }
            foreach (['tax_id', 'tax_id_2'] as $col) {
                if (array_key_exists($col, $data)) {
                    $data[$col] = $this->requireTaxOrZero((int) $data[$col], $col);
                }
            }
            if (array_key_exists('stripe_plan_id', $data)) {
                $data['stripe_plan_id'] = trim((string) $data['stripe_plan_id']);
            }
            if (array_key_exists('project_id', $data)) {
                $data['project_id'] = (int) $data['project_id'] > 0 ? $this->requireProjectOf((int) $data['project_id'], $customerAfter) : 0;
            }

            $this->CI->db->where('id', $id);
            $ok = $this->CI->db->update(db_prefix() . 'subscriptions', $data);

            return ['updated' => (bool) $ok, 'id' => $id];
        });
    }

    // ---------------------------------------------------------------- helpers

    private function requireTaxOrZero(int $id, string $field): int
    {
        if ($id <= 0) {
            return 0;
        }
        $this->CI->db->where('id', $id);
        if ($this->CI->db->get(db_prefix() . 'taxes')->row_array()) {
            return $id;
        }
        $rows = $this->CI->db->get(db_prefix() . 'taxes')->result_array();
        $list = array_map(static fn ($t) => $t['id'] . '=' . $t['name'] . ' ' . $t['taxrate'] . '%', array_slice($rows, 0, 20));
        $this->fail('Unknown ' . $field . ' ' . $id . '. ' . ($rows === [] ? 'No taxes are configured in Perfex; use 0.' : 'Available: ' . implode(', ', $list) . '.'));
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

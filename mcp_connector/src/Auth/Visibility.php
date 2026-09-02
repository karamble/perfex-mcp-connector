<?php

namespace PerfexMcp\Auth;

/**
 * THE visibility table: how Perfex 3.4.1 decides which rows a staff member
 * may read, per feature, reproduced as SQL predicates.
 *
 * Perfex has no central row-authorization function. Each module's rule lives
 * in its DataTables script (views/admin/tables/*.php), a helper
 * (get_invoices_where_sql_for_staff, get_tasks_where_string, ...) or a
 * controller, and every one of them is a plain predicate over the module's
 * table. This class carries them all in one place so that every *_list
 * applies the rule and every *_get re-checks it, and so that a feature
 * missing from the table is refused rather than served unfiltered.
 *
 * Gate = may this staff member read the feature at all (else PermissionDenied).
 * Predicate = the row filter for staff WITHOUT global view (null = no filter).
 * Admins pass every staff_can() check, so they always get null.
 *
 * Sources (Perfex 3.4.1):
 *  - invoices/estimates: helpers get_{invoices,estimates}_where_sql_for_staff()
 *    - view_own: addedfrom = S, plus sale_agent = S when the
 *    allow_staff_view_*_assigned option is on; without view_own but with the
 *    option on: sale_agent = S alone. Gate: view, view_own or the option.
 *  - proposals: get_proposals_sql_where_staff(), same shape, column `assigned`.
 *  - expenses, contracts, credit_notes: tables/*.php `addedfrom = S`.
 *  - subscriptions: tables/subscriptions.php `created_from = S`.
 *  - leads: tables/leads.php:128 (assigned/addedfrom/is_public); feature has
 *    only view + delete, so any staff member is gated in.
 *  - customers/contacts: tables/clients.php:47 (customer_admins membership).
 *  - projects: tables/projects.php:42 (project_members).
 *  - tasks: tasks_helper.php get_tasks_where_string() verbatim.
 *  - tickets: tables/tickets.php:130-147 and Tickets.php:205-214 - department
 *    membership, only when staff_access_only_assigned_departments is on and
 *    the staff member is not an admin; a staff member with no department sees
 *    nothing. There is no tickets permission feature.
 *  - payments: tables/payments.php:29-37 - follows the invoices rule.
 *  - staff: Staff.php:14-30 - the directory page needs view on staff, but
 *    Perfex shows staff NAMES to everyone in assignee pickers; so the read is
 *    gated in for any staff member and flagged lite without the capability.
 *
 * The addedfrom IN (SELECT staff_id FROM staff_permissions WHERE ... view_own)
 * sub-clause Perfex emits re-checks what the gate already established for S
 * and is dropped.
 */
final class Visibility
{
    public static function scope(string $feature): ReadScope
    {
        $rules = self::rules();
        if (! isset($rules[$feature])) {
            log_message('error', '[mcp_connector] no visibility rule for "' . $feature . '"');
            throw new PermissionDenied('Permission denied: no visibility rule for "' . $feature . '".');
        }
        [$gate, $predicate] = $rules[$feature];
        if (! $gate()) {
            throw new PermissionDenied('Permission denied: this key\'s staff member cannot read ' . $feature . '.');
        }
        $sql = $predicate();
        // CI3's _wh() appends " IS NULL" to a where() string without an
        // operator; every predicate here carries one, and a future one
        // without would silently turn a filter into "IS NULL".
        if ($sql !== null && ! preg_match('/[=<>]|\sIN\s*\(/', $sql)) {
            throw new \LogicException('Visibility predicate for ' . $feature . ' has no operator: ' . $sql);
        }
        $lite = $feature === 'staff' && ! staff_can('view', 'staff');

        return new ReadScope($feature, $sql, $lite);
    }

    /** @return string[] every feature the table knows */
    public static function features(): array
    {
        return array_keys(self::rules());
    }

    /**
     * @return array<string, array{0: \Closure(): bool, 1: \Closure(): ?string}>
     */
    private static function rules(): array
    {
        $p     = db_prefix();
        $S     = (int) get_staff_user_id();
        $on    = static fn (string $opt): bool => (string) get_option($opt) === '1';
        $view  = static fn (string $f): bool => (bool) staff_can('view', $f);
        $own   = static fn (string $f): bool => (bool) staff_can('view_own', $f);
        $staff = static fn (): bool => (bool) staff_can('is_staff_member');

        $customerAdmin = static fn (string $col): string => "{$col} IN (SELECT customer_id FROM {$p}customer_admins WHERE staff_id = {$S})";
        $deptMember    = static fn (string $col): string => "{$col} IN (SELECT departmentid FROM {$p}staff_departments WHERE staffid = {$S})";
        $ticketsGlobal = static fn (): bool => is_admin() || ! $on('staff_access_only_assigned_departments');

        // Sales documents with a sale-agent style column and an "assigned"
        // option: view_own => own [+ assigned]; no view_own => assigned only.
        $salesDoc = static function (string $feature, string $table, string $agentColumn, string $option) use ($p, $S, $on, $view, $own): array {
            return [
                fn () => $view($feature) || $own($feature) || $on($option),
                function () use ($feature, $table, $agentColumn, $option, $p, $S, $on, $view, $own): ?string {
                    if ($view($feature)) {
                        return null;
                    }
                    if (! $own($feature)) {
                        return "{$p}{$table}.{$agentColumn} = {$S}";
                    }

                    return "{$p}{$table}.addedfrom = {$S}"
                        . ($on($option) ? " OR {$p}{$table}.{$agentColumn} = {$S}" : '');
                },
            ];
        };
        $ownOnly = static fn (string $feature, string $table, string $column = 'addedfrom'): array => [
            fn () => $view($feature) || $own($feature),
            fn () => $view($feature) ? null : "{$p}{$table}.{$column} = {$S}",
        ];

        return [
            'invoices'      => $salesDoc('invoices', 'invoices', 'sale_agent', 'allow_staff_view_invoices_assigned'),
            'estimates'     => $salesDoc('estimates', 'estimates', 'sale_agent', 'allow_staff_view_estimates_assigned'),
            'proposals'     => $salesDoc('proposals', 'proposals', 'assigned', 'allow_staff_view_proposals_assigned'),
            'expenses'      => $ownOnly('expenses', 'expenses'),
            'contracts'     => $ownOnly('contracts', 'contracts'),
            'credit_notes'  => $ownOnly('credit_notes', 'creditnotes'),
            'subscriptions' => $ownOnly('subscriptions', 'subscriptions', 'created_from'),

            'leads' => [$staff, fn () => $view('leads') ? null
                : "{$p}leads.assigned = {$S} OR {$p}leads.addedfrom = {$S} OR {$p}leads.is_public = 1"],
            'customers' => [$staff, fn () => $view('customers') ? null : $customerAdmin("{$p}clients.userid")],
            'contacts'  => [$staff, fn () => $view('customers') ? null : $customerAdmin("{$p}contacts.userid")],
            'projects'  => [$staff, fn () => $view('projects') ? null
                : "{$p}projects.id IN (SELECT project_id FROM {$p}project_members WHERE staff_id = {$S})"],
            'tasks' => [$staff, fn () => $view('tasks') ? null : implode(' OR ', array_values(array_filter([
                "{$p}tasks.id IN (SELECT taskid FROM {$p}task_assigned WHERE staffid = {$S})",
                "{$p}tasks.id IN (SELECT taskid FROM {$p}task_followers WHERE staffid = {$S})",
                "({$p}tasks.addedfrom = {$S} AND {$p}tasks.is_added_from_contact = 0)",
                $on('show_all_tasks_for_project_member')
                    ? "({$p}tasks.rel_type = \"project\" AND {$p}tasks.rel_id IN (SELECT project_id FROM {$p}project_members WHERE staff_id = {$S}))"
                    : null,
                "{$p}tasks.is_public = 1",
            ])))],
            'tickets'     => [$staff, fn () => $ticketsGlobal() ? null : $deptMember("{$p}tickets.department")],
            'departments' => [$staff, fn () => $ticketsGlobal() ? null : $deptMember("{$p}departments.departmentid")],

            'payments' => [
                fn () => $view('payments') || $own('invoices') || $on('allow_staff_view_invoices_assigned'),
                function () use ($p, $S, $view, $own, $on): ?string {
                    if ($view('payments')) {
                        return null;
                    }
                    $parts = [];
                    if ($own('invoices')) {
                        $parts[] = "{$p}invoicepaymentrecords.invoiceid IN (SELECT id FROM {$p}invoices WHERE addedfrom = {$S})";
                    }
                    if ($on('allow_staff_view_invoices_assigned')) {
                        $parts[] = "{$p}invoicepaymentrecords.invoiceid IN (SELECT id FROM {$p}invoices WHERE sale_agent = {$S})";
                    }

                    return $parts === [] ? '1 = 0' : implode(' OR ', $parts);
                },
            ],

            // No row predicate; listed so every list must still name a rule.
            'staff'              => [$staff, fn () => null],
            'items'              => [fn () => $view('items'), fn () => null],
            'knowledge_base'     => [fn () => $view('knowledge_base'), fn () => null],
            'announcements'      => [$staff, fn () => null],
            'custom_fields'      => [$staff, fn () => null],
            'contract_types'     => [$staff, fn () => null],
            'expense_categories' => [fn () => $view('expenses') || $own('expenses'), fn () => null],
        ];
    }
}

<?php

namespace PerfexMcp\Tools;

use Mcp\Schema\ToolAnnotations;

/**
 * Single source of truth for the tool catalog. Each entry maps a tool name to a
 * [Class, method] handler, its JSON-Schema input contract (the SDK validates
 * arguments against it before invoking), a human title/description, and MCP
 * annotations (readOnly/destructive hints).
 *
 * The endpoint honors the global write/destructive kill-switches by filtering
 * this list, so a disabled category never appears in tools/list at all.
 */
final class ToolRegistrar
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $writesEnabled      = (bool) get_option('mcp_connector_enable_write_tools');
        $destructiveEnabled = (bool) get_option('mcp_connector_enable_destructive_tools');

        $tools = self::readTools();

        if ($writesEnabled) {
            $tools = array_merge($tools, self::writeTools());
        }
        if ($writesEnabled && $destructiveEnabled) {
            $tools = array_merge($tools, self::destructiveTools());
        }

        return $tools;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function readTools(): array
    {
        $ro = new ToolAnnotations(readOnlyHint: true, openWorldHint: false);
        $t  = [];

        // Customers
        $t[] = self::tool('customers_list', 'List customers', 'List customers (clients) with optional search and pagination. Without global view: the customers the staff member administers, as in Perfex.', [CustomerTools::class, 'customers_list'], $ro, self::listSchema(['search' => self::str('Match company, VAT, phone, or customer id.')]));
        $t[] = self::tool('customers_get', 'Get customer', 'Get a single customer by id, including its contacts.', [CustomerTools::class, 'customers_get'], $ro, self::idSchema('Customer (client) id.'));

        // Contacts
        $t[] = self::tool('contacts_list', 'List contacts', 'List customer contacts, optionally filtered by customer_id or search. Scoped like customers.', [ContactTools::class, 'contacts_list'], $ro, self::listSchema(['customer_id' => self::int('Restrict to this customer id.'), 'search' => self::str('Match first/last name or email.')]));
        $t[] = self::tool('contacts_get', 'Get contact', 'Get a single contact by id.', [ContactTools::class, 'contacts_get'], $ro, self::idSchema('Contact id.'));

        // Leads
        $t[] = self::tool('leads_list', 'List leads', 'List leads with optional search and status filter. Without global view: leads assigned to, created by, or public to the staff member, as in Perfex.', [LeadTools::class, 'leads_list'], $ro, self::listSchema(['search' => self::str('Match name, company, email, or phone.'), 'status' => self::int('Lead status id.')]));
        $t[] = self::tool('leads_get', 'Get lead', 'Get a single lead by id.', [LeadTools::class, 'leads_get'], $ro, self::idSchema('Lead id.'));

        // Invoices
        $t[] = self::tool('invoices_list', 'List invoices', 'List invoices, optionally by customer, status, or search. Scoped to what the staff member may see in Perfex (own or sale-agent invoices without global view).', [InvoiceTools::class, 'invoices_list'], $ro, self::listSchema(['customer_id' => self::int('Restrict to this customer id.'), 'status' => self::str('Invoice status id (1 unpaid, 2 paid, 3 partially paid, 4 overdue, 5 cancelled, 6 draft).'), 'search' => self::str('Match the invoice number.')]));
        $t[] = self::tool('invoices_get', 'Get invoice', 'Get a single invoice by id.', [InvoiceTools::class, 'invoices_get'], $ro, self::idSchema('Invoice id.'));

        // Estimates
        $t[] = self::tool('estimates_list', 'List estimates', 'List estimates, optionally by customer, status, or search. Scoped to what the staff member may see in Perfex.', [EstimateTools::class, 'estimates_list'], $ro, self::listSchema(['customer_id' => self::int('Restrict to this customer id.'), 'status' => self::str('Estimate status id.'), 'search' => self::str('Match number or reference.')]));
        $t[] = self::tool('estimates_get', 'Get estimate', 'Get a single estimate by id.', [EstimateTools::class, 'estimates_get'], $ro, self::idSchema('Estimate id.'));

        // Payments
        $t[] = self::tool('payments_list', 'List payments', 'List recorded invoice payments, optionally for one invoice. Scoped like invoices for staff without global view on payments.', [PaymentTools::class, 'payments_list'], $ro, self::listSchema(['invoice_id' => self::int('Restrict to this invoice id.')], false));
        $t[] = self::tool('payments_get', 'Get payment', 'Get a single payment record by id.', [PaymentTools::class, 'payments_get'], $ro, self::idSchema('Payment id.'));

        // Expenses
        $t[] = self::tool('expenses_list', 'List expenses', 'List expenses (outgoing costs). Amounts are per-currency; never sum across currencies - filter by currency and total per currency. Every row is a real expense occurrence, including a recurring parent; generated occurrences reference it via recurring_from and are not duplicates.', [ExpenseTools::class, 'expenses_list'], $ro, self::listSchema(['category' => self::int('Restrict to this expense category id.'), 'currency' => ['type' => ['string', 'integer'], 'description' => 'Currency as ISO code (e.g. EUR) or currency id.'], 'date_from' => self::str('Earliest date, inclusive (Y-m-d).'), 'date_to' => self::str('Latest date, inclusive (Y-m-d).'), 'year' => self::int('Calendar year; mutually exclusive with date_from/date_to.'), 'search' => self::str('Match reference number or expense name.'), 'recurring_only' => ['type' => 'boolean', 'description' => 'Only recurring parent expenses (the templates the cron copies).'], 'recurring_from' => self::int('Only occurrences generated from this recurring parent id.')]));
        $t[] = self::tool('expenses_get', 'Get expense', 'Get a single expense by id, with category, currency, project, attachments and recurring-children count.', [ExpenseTools::class, 'expenses_get'], $ro, self::idSchema('Expense id.'));
        $t[] = self::tool('expense_categories_list', 'List expense categories', 'List expense categories.', [ExpenseTools::class, 'expense_categories_list'], $ro, self::listSchema(['search' => self::str('Match the category name.')]));

        // Tasks
        $t[] = self::tool('tasks_list', 'List tasks', 'List tasks with optional search, status, and related-entity filters. Without global view: tasks assigned to, followed by, created by, or public to the staff member (plus their projects\' tasks when Perfex is set so), as in Perfex.', [TaskTools::class, 'tasks_list'], $ro, self::listSchema(['search' => self::str('Match the task name.'), 'status' => self::int('Task status id (1 not started, 2 awaiting feedback, 3 testing, 4 in progress, 5 complete).'), 'rel_type' => self::str('Related entity type (customer, lead, project, invoice, estimate, ticket, ...).'), 'rel_id' => self::int('Related entity id.')]));
        $t[] = self::tool('tasks_get', 'Get task', 'Get a single task by id.', [TaskTools::class, 'tasks_get'], $ro, self::idSchema('Task id.'));

        // Tickets
        $t[] = self::tool('tickets_list', 'List tickets', 'List support tickets with optional status, customer, and search filters. Non-admins see their departments\' tickets when Perfex restricts by department.', [TicketTools::class, 'tickets_list'], $ro, self::listSchema(['status' => self::int('Ticket status id (1 open, 2 in progress, 3 answered, 4 on hold, 5 closed).'), 'customer_id' => self::int('Restrict to this customer id.'), 'search' => self::str('Match subject or ticket key.')]));
        $t[] = self::tool('tickets_get', 'Get ticket', 'Get a single ticket by id, including its replies.', [TicketTools::class, 'tickets_get'], $ro, self::idSchema('Ticket id.'));

        // Projects
        $t[] = self::tool('projects_list', 'List projects', 'List projects with optional customer, status, and search filters. Without global view: projects the staff member is a member of, as in Perfex.', [ProjectTools::class, 'projects_list'], $ro, self::listSchema(['customer_id' => self::int('Restrict to this customer id.'), 'status' => self::int('Project status id (1 not started, 2 in progress, 3 on hold, 4 cancelled, 5 finished).'), 'search' => self::str('Match the project name.')]));
        $t[] = self::tool('projects_get', 'Get project', 'Get a single project by id.', [ProjectTools::class, 'projects_get'], $ro, self::idSchema('Project id.'));

        // Notes
        $t[] = self::tool('notes_list', 'List notes', 'List notes attached to a CRM entity.', [NoteTools::class, 'notes_list'], $ro, [
            'type'       => 'object',
            'properties' => [
                'rel_type' => ['type' => 'string', 'description' => 'Entity type the notes belong to.', 'enum' => ['customer', 'lead', 'invoice', 'estimate', 'project', 'task', 'ticket']],
                'rel_id'   => ['type' => 'integer', 'description' => 'Id of the entity.'],
            ],
            'required'             => ['rel_type', 'rel_id'],
            'additionalProperties' => false,
        ]);

        // Staff (read-only directory)
        $t[] = self::tool('staff_list', 'List staff', 'List staff members. Any staff member gets id, name and active flag; the directory fields (email, phone, role, admin, dates) only with view on staff.', [StaffTools::class, 'staff_list'], $ro, self::listSchema(['search' => self::str('Match first/last name or email.'), 'active_only' => ['type' => 'boolean', 'description' => 'Only active staff (default true).']]));
        $t[] = self::tool('staff_get', 'Get staff member', 'Get a single staff member: id, name and active flag for any staff member; the directory fields only with view on staff.', [StaffTools::class, 'staff_get'], $ro, self::idSchema('Staff id.'));

        // Proposals
        $t[] = self::tool('proposals_list', 'List proposals', 'List proposals (for customers or leads) with optional customer/lead, status, date-range and search filters. Statuses: 1 open, 2 declined, 3 accepted, 4 sent, 5 revised, 6 draft. Scoped to what the staff member may see in Perfex (own or assigned proposals without global view).', [ProposalTools::class, 'proposals_list'], $ro, self::listSchema(['customer_id' => self::int('Only proposals for this customer id.'), 'lead_id' => self::int('Only proposals for this lead id (exclusive with customer_id).'), 'status' => self::int('Status id (1 open, 2 declined, 3 accepted, 4 sent, 5 revised, 6 draft).'), 'search' => self::str('Match subject or recipient name.'), 'date_from' => self::str('Earliest proposal date, inclusive (Y-m-d).'), 'date_to' => self::str('Latest proposal date, inclusive (Y-m-d).')]));
        $t[] = self::tool('proposals_get', 'Get proposal', 'Get a proposal with its line items and taxes, currency and related customer or lead. content is the raw template by default; render_merge_fields=true returns it with merge fields substituted (read-only).', [ProposalTools::class, 'proposals_get'], $ro, self::idSchema('Proposal id.', ['render_merge_fields' => self::bool('Substitute merge fields into content (default false).')]));

        // Contracts
        $t[] = self::tool('contracts_list', 'List contracts', 'List contracts with optional customer, type, expiry and search filters. Trashed contracts are excluded unless trash=true. Contracts have no status or currency; contract_value is in the base currency. Scoped to what the staff member may see in Perfex.', [ContractTools::class, 'contracts_list'], $ro, self::listSchema(['customer_id' => self::int('Only contracts of this customer id.'), 'contract_type' => self::int('Contract type id (see contract_types_list).'), 'trash' => self::bool('List trashed contracts instead of live ones (default false).'), 'expired_only' => self::bool('Only contracts whose end date has passed.'), 'search' => self::str('Match the subject.')]));
        $t[] = self::tool('contracts_get', 'Get contract', 'Get a contract with its type, customer, attachments and renewal history. content is the raw template by default; render_merge_fields=true returns it with merge fields substituted - read-only, never write that value back.', [ContractTools::class, 'contracts_get'], $ro, self::idSchema('Contract id.', ['render_merge_fields' => self::bool('Substitute merge fields into content (default false).')]));
        $t[] = self::tool('contract_types_list', 'List contract types', 'List contract types.', [ContractTools::class, 'contract_types_list'], $ro, self::listSchema([]));

        // Credit notes
        $t[] = self::tool('credit_notes_list', 'List credit notes', 'List credit notes with optional customer, status (1 open, 2 closed, 3 void), date-range and search filters. Remaining credit is derived, not stored - use credit_notes_get for it. Scoped to what the staff member may see in Perfex.', [CreditNoteTools::class, 'credit_notes_list'], $ro, self::listSchema(['customer_id' => self::int('Only credit notes of this customer id.'), 'status' => self::int('Status id (1 open, 2 closed, 3 void).'), 'date_from' => self::str('Earliest date, inclusive (Y-m-d).'), 'date_to' => self::str('Latest date, inclusive (Y-m-d).'), 'search' => self::str('Match the number or reference.')]));
        $t[] = self::tool('credit_notes_get', 'Get credit note', 'Get a credit note with line items, applied credits, refunds and the derived figures remaining_credits, credits_used and total_refunds.', [CreditNoteTools::class, 'credit_notes_get'], $ro, self::idSchema('Credit note id.'));

        // Subscriptions
        $t[] = self::tool('subscriptions_list', 'List subscriptions', 'List subscriptions with optional customer, project, status and search filters. CRUD only: subscribing, cancelling and invoice generation are Stripe operations done in Perfex. Scoped to what the staff member may see in Perfex.', [SubscriptionTools::class, 'subscriptions_list'], $ro, self::listSchema(['customer_id' => self::int('Only subscriptions of this customer id.'), 'project_id' => self::int('Only subscriptions linked to this project id.'), 'status' => self::str('Stripe subscription status (active, past_due, canceled, ...); not set until the customer subscribes.'), 'search' => self::str('Match the name.')]));
        $t[] = self::tool('subscriptions_get', 'Get subscription', 'Get a subscription with its currency, customer, project and tax details.', [SubscriptionTools::class, 'subscriptions_get'], $ro, self::idSchema('Subscription id.'));

        // Items (sales catalog)
        $t[] = self::tool('items_list', 'List items', 'List the sales-item catalog (Sales > Items), optionally by group or search. Rates are in the base currency.', [ItemTools::class, 'items_list'], $ro, self::listSchema(['group_id' => self::int('Only items in this group id.'), 'search' => self::str('Match description or long description.')]));
        $t[] = self::tool('items_get', 'Get item', 'Get a catalog item with its group and tax details.', [ItemTools::class, 'items_get'], $ro, self::idSchema('Item id.'));

        // Knowledge base
        $t[] = self::tool('knowledge_base_list', 'List knowledge base articles', 'List knowledge base articles (without bodies), optionally by group, active flag or search.', [KnowledgeBaseTools::class, 'knowledge_base_list'], $ro, self::listSchema(['group_id' => self::int('Only articles in this group id.'), 'active' => self::bool('Only active (true) or only inactive (false) articles.'), 'search' => self::str('Match the subject.')]));
        $t[] = self::tool('knowledge_base_get', 'Get knowledge base article', 'Get an article including its HTML body and group.', [KnowledgeBaseTools::class, 'knowledge_base_get'], $ro, self::idSchema('Article id.'));
        $t[] = self::tool('knowledge_base_groups_list', 'List knowledge base groups', 'List knowledge base article groups.', [KnowledgeBaseTools::class, 'knowledge_base_groups_list'], $ro, self::listSchema(['active' => self::bool('Only active (true) or only inactive (false) groups.')]));

        // Announcements
        $t[] = self::tool('announcements_list', 'List announcements', 'List announcements (without bodies).', [SystemTools::class, 'announcements_list'], $ro, self::listSchema(['search' => self::str('Match the title.')]));
        $t[] = self::tool('announcements_get', 'Get announcement', 'Get an announcement including its HTML message.', [SystemTools::class, 'announcements_get'], $ro, self::idSchema('Announcement id.'));

        // Custom fields (discovery only)
        $t[] = self::tool('custom_fields_list', 'List custom fields', 'Discover the custom fields defined for an entity: name, slug, type and the allowed options. Discovery only - this connector never writes custom field values. fieldto values are Perfex\'s own and inconsistently pluralized: ' . implode(', ', SystemTools::CUSTOM_FIELD_TARGETS) . '.', [SystemTools::class, 'custom_fields_list'], $ro, self::listSchema(['fieldto' => ['type' => 'string', 'enum' => SystemTools::CUSTOM_FIELD_TARGETS, 'description' => 'Entity the fields belong to.'], 'active_only' => self::bool('Only active fields (default true).')]));

        return $t;
    }

    /**
     * Write (create/update/action) tools.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function writeTools(): array
    {
        $w = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, openWorldHint: false);
        $t = [];

        // Customers
        $t[] = self::tool('customers_create', 'Create customer', 'Create a customer (client). Requires company.', [CustomerTools::class, 'customers_create'], $w, self::fieldsSchema(['company' => self::str('Company / customer name (required).'), 'vat' => self::str('VAT number.'), 'phonenumber' => self::str('Phone.'), 'website' => self::str('Website.'), 'address' => self::str('Address.'), 'city' => self::str('City.'), 'state' => self::str('State.'), 'zip' => self::str('ZIP.'), 'country' => self::int('Country id.')], ['company']));
        $t[] = self::tool('customers_update', 'Update customer', 'Update fields on a customer.', [CustomerTools::class, 'customers_update'], $w, self::updateSchema(['company' => self::str('Company / customer name.'), 'vat' => self::str('VAT.'), 'phonenumber' => self::str('Phone.'), 'website' => self::str('Website.'), 'address' => self::str('Address.'), 'city' => self::str('City.'), 'state' => self::str('State.'), 'zip' => self::str('ZIP.'), 'country' => self::int('Country id.')]));

        // Contacts
        $t[] = self::tool('contacts_create', 'Create contact', 'Create a contact under a customer.', [ContactTools::class, 'contacts_create'], $w, [
            'type' => 'object',
            'properties' => ['customer_id' => self::int('Customer id the contact belongs to.'), 'fields' => self::obj(['firstname' => self::str('First name (required).'), 'lastname' => self::str('Last name (required).'), 'email' => self::str('Email (required).'), 'phonenumber' => self::str('Phone.'), 'title' => self::str('Job title.'), 'is_primary' => ['type' => 'boolean', 'description' => 'Primary contact.']], ['firstname', 'lastname', 'email'])],
            'required' => ['customer_id', 'fields'], 'additionalProperties' => false,
        ]);
        $t[] = self::tool('contacts_update', 'Update contact', 'Update fields on a contact.', [ContactTools::class, 'contacts_update'], $w, self::updateSchema(['firstname' => self::str('First name.'), 'lastname' => self::str('Last name.'), 'email' => self::str('Email.'), 'phonenumber' => self::str('Phone.'), 'title' => self::str('Job title.'), 'active' => ['type' => 'boolean', 'description' => 'Active.']]));

        // Leads
        $t[] = self::tool('leads_create', 'Create lead', 'Create a lead. Requires name, source (id), status (id).', [LeadTools::class, 'leads_create'], $w, self::fieldsSchema(['name' => self::str('Lead name (required).'), 'source' => self::int('Lead source id (required).'), 'status' => self::int('Lead status id (required).'), 'company' => self::str('Company.'), 'email' => self::str('Email.'), 'phonenumber' => self::str('Phone.'), 'assigned' => self::int('Assigned staff id.'), 'city' => self::str('City.'), 'country' => self::int('Country id.'), 'description' => self::str('Description.'), 'lead_value' => ['type' => 'number', 'description' => 'Estimated value.']], ['name', 'source', 'status']));
        $t[] = self::tool('leads_update', 'Update lead', 'Update fields on a lead.', [LeadTools::class, 'leads_update'], $w, self::updateSchema(['name' => self::str('Name.'), 'status' => self::int('Status id.'), 'source' => self::int('Source id.'), 'assigned' => self::int('Assigned staff id.'), 'company' => self::str('Company.'), 'email' => self::str('Email.'), 'phonenumber' => self::str('Phone.'), 'description' => self::str('Description.'), 'lead_value' => ['type' => 'number', 'description' => 'Value.']]));
        $t[] = self::tool('leads_convert_to_customer', 'Convert lead to customer', 'Convert a lead into a customer (client).', [LeadTools::class, 'leads_convert_to_customer'], $w, self::idSchema('Lead id.'));

        // Invoices
        $invoiceCreateSchema = self::salesDocSchema('duedate', 'Due date (Y-m-d).');
        $invoiceCreateSchema['properties']['recurring']     = self::int('Repeat every N months (1-12); 0 or omitted = one-off. Simple month cycle only.');
        $invoiceCreateSchema['properties']['cycles']        = self::int('How many copies to generate (0 = no limit); only with recurring.');
        $invoiceCreateSchema['properties']['payment_modes'] = self::modesSchema();
        $t[] = self::tool('invoices_create', 'Create invoice (draft)', 'Create a DRAFT invoice with line items, optionally recurring every N months and with selected payment modes. Draft-first avoids number collisions; finalize/send separately. Per-item tax and discounts are not supported in this version.', [InvoiceTools::class, 'invoices_create'], $w, $invoiceCreateSchema);
        $t[] = self::tool('invoices_update', 'Update invoice', 'Update scalar fields on an invoice (duedate, notes, status, terms, sale_agent, recurring, cycles, payment_modes). Setting recurring N repeats the invoice every N months; 0 switches recurrence off.', [InvoiceTools::class, 'invoices_update'], $w, self::updateSchema(['duedate' => self::str('Due date (Y-m-d).'), 'clientnote' => self::str('Client note.'), 'adminnote' => self::str('Admin note.'), 'status' => self::int('Status id.'), 'terms' => self::str('Terms.'), 'sale_agent' => self::int('Sale agent staff id.'), 'recurring' => self::int('Repeat every N months (1-12); 0 switches recurrence off. Simple month cycle only.'), 'cycles' => self::int('How many copies to generate (0 = no limit); only on a recurring invoice.'), 'payment_modes' => self::modesSchema(),
            'billing_street' => self::str('Billing street.'), 'billing_city' => self::str('Billing city.'), 'billing_state' => self::str('Billing state.'), 'billing_zip' => self::str('Billing ZIP.'), 'billing_country' => self::int('Billing country id.'),
            'shipping_street' => self::str('Shipping street.'), 'shipping_city' => self::str('Shipping city.'), 'shipping_state' => self::str('Shipping state.'), 'shipping_zip' => self::str('Shipping ZIP.'), 'shipping_country' => self::int('Shipping country id.'),
            'show_shipping_on_invoice' => self::int('Show shipping address on the invoice (0/1).'), 'include_shipping' => self::int('Include shipping details (0/1).')]));
        $t[] = self::tool('invoices_send', 'Send invoice', 'Email the invoice to the customer\'s default invoice contacts.', [InvoiceTools::class, 'invoices_send'], $w, ['type' => 'object', 'properties' => ['id' => self::int('Invoice id.'), 'cc' => self::str('Optional CC email(s).')], 'required' => ['id'], 'additionalProperties' => false]);
        $t[] = self::tool('invoices_record_payment', 'Record invoice payment', 'Record a payment against an invoice.', [PaymentTools::class, 'payments_record'], $w, self::paymentSchema());

        // Estimates
        $t[] = self::tool('estimates_create', 'Create estimate (draft)', 'Create a DRAFT estimate with line items. Per-item tax/discounts unsupported in this version.', [EstimateTools::class, 'estimates_create'], $w, self::salesDocSchema('expirydate', 'Expiry date (Y-m-d).'));
        $t[] = self::tool('estimates_update', 'Update estimate', 'Update scalar fields on an estimate.', [EstimateTools::class, 'estimates_update'], $w, self::updateSchema(['expirydate' => self::str('Expiry (Y-m-d).'), 'clientnote' => self::str('Client note.'), 'adminnote' => self::str('Admin note.'), 'status' => self::int('Status id.'), 'reference_no' => self::str('Reference.'), 'sale_agent' => self::int('Sale agent staff id.')]));
        $t[] = self::tool('estimates_send', 'Send estimate', 'Email the estimate to the customer.', [EstimateTools::class, 'estimates_send'], $w, ['type' => 'object', 'properties' => ['id' => self::int('Estimate id.'), 'cc' => self::str('Optional CC email(s).')], 'required' => ['id'], 'additionalProperties' => false]);
        $t[] = self::tool('estimates_convert_to_invoice', 'Convert estimate to invoice', 'Convert an estimate into a draft invoice.', [EstimateTools::class, 'estimates_convert_to_invoice'], $w, self::idSchema('Estimate id.'));

        // Expenses
        $expenseFields = [
            'category'       => self::int('Expense category id (required; must exist - see expense_categories_list/create).'),
            'amount'         => ['type' => 'number', 'description' => 'Net amount in the expense currency, greater than 0. Taxes are not exposed in this version.'],
            'date'           => self::str('Expense date (Y-m-d).'),
            'currency'       => ['type' => ['string', 'integer'], 'description' => 'Currency as ISO code (e.g. EUR) or currency id; defaults to the base currency.'],
            'expense_name'   => self::str('Short name for this expense (the list label otherwise falls back to the category name).'),
            'note'           => self::str('Free-text note; line breaks are preserved.'),
            'reference_no'   => self::str('Invoice/receipt reference number.'),
            'payment_mode'   => self::str('Payment mode id from the configured payment modes.'),
            'customer_id'    => self::int('Customer id this expense belongs to (0 = none).'),
            'project_id'     => self::int('Project id (0 = none).'),
            'billable'       => ['type' => 'boolean', 'description' => 'Bill this expense to the customer later; requires customer_id.'],
            'repeat_every'   => self::int('Repeat every N periods (with recurring_type). On update, 0 switches recurrence off and clears the cycle state.'),
            'recurring_type' => ['type' => 'string', 'enum' => ['day', 'week', 'month', 'year'], 'description' => 'Recurrence period unit; required together with repeat_every.'],
            'cycles'         => self::int('How many occurrences to generate (0 = no limit); only with recurrence.'),
        ];
        $t[] = self::tool('expenses_create', 'Create expense', 'Record an outgoing cost (hosting, infra, domains, ...), optionally recurring. Amount is net in the given currency.', [ExpenseTools::class, 'expenses_create'], $w, self::fieldsSchema($expenseFields, ['category', 'amount', 'date']));
        $t[] = self::tool('expenses_update', 'Update expense', 'Update fields on an expense. null leaves a field unchanged; 0 detaches customer_id/project_id; empty string clears text fields. Fields frozen while the expense is billed on an invoice: amount, currency, billable, customer_id, category.', [ExpenseTools::class, 'expenses_update'], $w, self::updateSchema($expenseFields));
        $t[] = self::tool('expenses_attach_receipt', 'Attach expense receipt', 'Attach the receipt/invoice document (pdf, png, jpg or webp; max 5 MiB decoded) to an expense as base64. An expense holds ONE receipt: pass replace=true to swap it.', [ExpenseTools::class, 'expenses_attach_receipt'], $w, ['type' => 'object', 'properties' => ['id' => self::int('Expense id.'), 'filename' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150, 'description' => 'Original file name; extension must match the actual file type.'], 'content_base64' => ['type' => 'string', 'minLength' => 4, 'maxLength' => 7200000, 'description' => 'Base64-encoded file content (a data:*;base64, prefix is tolerated).'], 'replace' => ['type' => 'boolean', 'description' => 'Replace the existing receipt if one is present.']], 'required' => ['id', 'filename', 'content_base64'], 'additionalProperties' => false]);
        $t[] = self::tool('expense_categories_create', 'Create expense category', 'Create an expense category; returns the existing id when the name already exists (idempotent bootstrap).', [ExpenseTools::class, 'expense_categories_create'], $w, self::fieldsSchema(['name' => self::str('Category name (required).'), 'description' => self::str('Description.')], ['name']));

        // Tasks
        $t[] = self::tool('tasks_create', 'Create task', 'Create a task. Requires name and startdate (Y-m-d).', [TaskTools::class, 'tasks_create'], $w, self::fieldsSchema(['name' => self::str('Task name (required).'), 'startdate' => self::str('Start date Y-m-d (required).'), 'duedate' => self::str('Due date Y-m-d.'), 'description' => self::str('Description.'), 'priority' => self::int('Priority (1 low - 4 urgent).'), 'status' => self::int('Status id.'), 'rel_type' => self::str('Related entity type.'), 'rel_id' => self::int('Related entity id.'), 'billable' => ['type' => 'boolean', 'description' => 'Billable.']], ['name', 'startdate']));
        $t[] = self::tool('tasks_update', 'Update task', 'Update fields on a task.', [TaskTools::class, 'tasks_update'], $w, self::updateSchema(['name' => self::str('Name.'), 'duedate' => self::str('Due date Y-m-d.'), 'startdate' => self::str('Start date Y-m-d.'), 'description' => self::str('Description.'), 'priority' => self::int('Priority.'), 'status' => self::int('Status id.')]));
        $t[] = self::tool('tasks_change_status', 'Change task status', 'Set a task status (1 not started, 2 awaiting feedback, 3 testing, 4 in progress, 5 complete).', [TaskTools::class, 'tasks_change_status'], $w, self::idStatusSchema('Task id.'));
        $t[] = self::tool('tasks_comment_add', 'Add task comment', 'Add a comment to a task.', [TaskTools::class, 'tasks_comment_add'], $w, ['type' => 'object', 'properties' => ['task_id' => self::int('Task id.'), 'content' => self::str('Comment text.')], 'required' => ['task_id', 'content'], 'additionalProperties' => false]);

        // Tickets
        $t[] = self::tool('tickets_create', 'Create ticket', 'Open a support ticket. Requires subject, message, department (id) and a contact (contactid+userid) or email.', [TicketTools::class, 'tickets_create'], $w, self::fieldsSchema(['subject' => self::str('Subject (required).'), 'message' => self::str('Body (required).'), 'department' => self::int('Department id (required).'), 'contactid' => self::int('Customer contact id.'), 'userid' => self::int('Customer id.'), 'email' => self::str('Email (if not an existing contact).'), 'name' => self::str('Requester name.'), 'priority' => self::int('Priority id.'), 'assigned' => self::int('Assigned staff id.')], ['subject', 'message', 'department']));
        $t[] = self::tool('tickets_reply', 'Reply to ticket', 'Add a staff reply to a ticket (emails the customer).', [TicketTools::class, 'tickets_reply'], $w, ['type' => 'object', 'properties' => ['id' => self::int('Ticket id.'), 'message' => self::str('Reply text.'), 'status' => self::int('Optional new status id.')], 'required' => ['id', 'message'], 'additionalProperties' => false]);
        $t[] = self::tool('tickets_change_status', 'Change ticket status', 'Set a ticket status (1 open, 2 in progress, 3 answered, 4 on hold, 5 closed).', [TicketTools::class, 'tickets_change_status'], $w, self::idStatusSchema('Ticket id.'));

        // Projects
        $t[] = self::tool('projects_create', 'Create project', 'Create a project. Requires name, clientid, billing_type (1 fixed, 2 project hours, 3 task hours), start_date (Y-m-d).', [ProjectTools::class, 'projects_create'], $w, self::fieldsSchema(['name' => self::str('Project name (required).'), 'clientid' => self::int('Customer id (required).'), 'billing_type' => self::int('Billing type (required).'), 'start_date' => self::str('Start date Y-m-d (required).'), 'deadline' => self::str('Deadline Y-m-d.'), 'description' => self::str('Description.'), 'status' => self::int('Status id.'), 'project_cost' => ['type' => 'number', 'description' => 'Fixed cost.']], ['name', 'clientid', 'billing_type', 'start_date']));
        $t[] = self::tool('projects_update', 'Update project', 'Update fields on a project.', [ProjectTools::class, 'projects_update'], $w, self::updateSchema(['name' => self::str('Name.'), 'status' => self::int('Status id.'), 'deadline' => self::str('Deadline Y-m-d.'), 'description' => self::str('Description.'), 'progress' => self::int('Progress %.')]));

        // Notes
        $t[] = self::tool('notes_add', 'Add note', 'Attach a note to a CRM entity.', [NoteTools::class, 'notes_add'], $w, ['type' => 'object', 'properties' => ['rel_type' => ['type' => 'string', 'enum' => ['customer', 'lead', 'invoice', 'estimate', 'project', 'task', 'ticket']], 'rel_id' => self::int('Entity id.'), 'description' => self::str('Note text.')], 'required' => ['rel_type', 'rel_id', 'description'], 'additionalProperties' => false]);

        // Proposals
        $proposalContact = [
            'proposal_to'    => self::str('Recipient name (defaults to the customer company or lead name).'),
            'email'          => self::str('Recipient email (defaults to the customer\'s primary contact or the lead email).'),
            'phone'          => self::str('Phone.'),
            'address'        => self::str('Address; line breaks are preserved.'),
            'city'           => self::str('City.'),
            'state'          => self::str('State.'),
            'zip'            => self::str('ZIP.'),
            'country'        => self::int('Country id.'),
            'date'           => self::str('Proposal date (Y-m-d; default today).'),
            'open_till'      => self::str('Valid until (Y-m-d; default follows the proposal_due_after setting; empty string clears it on update).'),
            'currency'       => self::currencySchema('defaults to the customer\'s currency, else the base currency'),
            'assigned'       => self::int('Assigned staff id (0 = none).'),
            'allow_comments' => self::bool('Let the recipient comment on the proposal.'),
            'project_id'     => self::int('Project id (customer proposals only; 0 = none).'),
        ];
        $t[] = self::tool('proposals_create', 'Create proposal (draft)', 'Create a DRAFT proposal for a customer or a lead with line items; totals are computed from the items. The body template is fixed to {proposal_items} and edited in Perfex. Use proposals_send to email it. Per-item tax and discounts are not supported in this version.', [ProposalTools::class, 'proposals_create'], $w, self::fieldsSchema(array_merge(['subject' => self::str('Subject (required).'), 'rel_type' => ['type' => 'string', 'enum' => ['customer', 'lead'], 'description' => 'Recipient type (required).'], 'rel_id' => self::int('Customer id or lead id, matching rel_type (required).'), 'items' => self::itemsSchema()], $proposalContact), ['subject', 'rel_type', 'rel_id', 'items']));
        $t[] = self::tool('proposals_update', 'Update proposal', 'Update scalar fields on a proposal (subject, recipient details, dates, currency, assignee, allow_comments, project). Line items, the recipient (rel_type/rel_id) and the body template are not editable here.', [ProposalTools::class, 'proposals_update'], $w, self::updateSchema(array_merge(['subject' => self::str('Subject.')], $proposalContact)));
        $t[] = self::tool('proposals_change_status', 'Change proposal status', 'Set a proposal status (1 open, 2 declined, 3 accepted, 4 sent, 5 revised, 6 draft). No notifications are sent.', [ProposalTools::class, 'proposals_change_status'], $w, self::idStatusSchema('Proposal id.'));
        $t[] = self::tool('proposals_send', 'Send proposal', 'Email the proposal (with PDF) to its recipient email; a draft becomes sent.', [ProposalTools::class, 'proposals_send'], $w, ['type' => 'object', 'properties' => ['id' => self::int('Proposal id.'), 'cc' => self::str('Optional CC email(s).')], 'required' => ['id'], 'additionalProperties' => false]);
        $t[] = self::tool('proposals_convert_to_invoice', 'Convert proposal to invoice', 'Convert a customer proposal into an UNPAID invoice (Perfex numbers it immediately, unlike invoices_create). Returns the new invoice id.', [ProposalTools::class, 'proposals_convert_to_invoice'], $w, self::idSchema('Proposal id.'));

        // Contracts
        $contractFields = [
            'subject'               => self::str('Subject (required on create).'),
            'customer_id'           => self::int('Customer id (required on create).'),
            'datestart'             => self::str('Start date (Y-m-d; required on create).'),
            'dateend'               => self::str('End date (Y-m-d; required on create). Changing it re-arms the expiry reminder.'),
            'contract_type'         => self::int('Contract type id (see contract_types_list; 0 = none).'),
            'contract_value'        => ['type' => 'number', 'description' => 'Contract value in the base currency.'],
            'project_id'            => self::int('Project id belonging to the customer (0 = none).'),
            'description'           => self::str('Internal description.'),
            'content'               => self::str('HTML body; may use Perfex merge fields such as {contract_subject}. Never write back a rendered content value.'),
            'not_visible_to_client' => self::bool('Hide the contract from the client portal (default false = visible).'),
        ];
        $t[] = self::tool('contracts_create', 'Create contract', 'Create a contract for a customer. Requires subject, customer_id, datestart and dateend. Contracts have no status, currency or line items.', [ContractTools::class, 'contracts_create'], $w, self::fieldsSchema($contractFields, ['subject', 'customer_id', 'datestart', 'dateend']));
        $t[] = self::tool('contracts_update', 'Update contract', 'Update fields on a contract. Absent flags are left unchanged. customer_id, contract_value, datestart and dateend are frozen once the contract is signed. trash=true moves it to trash; trash=false restores.', [ContractTools::class, 'contracts_update'], $w, self::updateSchema($contractFields + ['trash' => self::bool('Trash (true) or restore (false) the contract.')]));
        $t[] = self::tool('contract_types_create', 'Create contract type', 'Create a contract type; returns the existing id when the name already exists (idempotent bootstrap).', [ContractTools::class, 'contract_types_create'], $w, self::fieldsSchema(['name' => self::str('Type name (required).')], ['name']));

        // Credit notes
        $t[] = self::tool('credit_notes_create', 'Create credit note', 'Create an OPEN credit note for a customer with line items; totals are computed from the items and Perfex assigns the number. Apply it to invoices with credit_notes_apply_to_invoice or refund it. Per-item tax and discounts are not supported in this version.', [CreditNoteTools::class, 'credit_notes_create'], $w, [
            'type'       => 'object',
            'properties' => [
                'customer_id'  => self::int('Customer id.'),
                'items'        => self::itemsSchema(),
                'date'         => self::str('Credit note date (Y-m-d; default today).'),
                'currency'     => self::currencySchema('defaults to the customer\'s currency, else the base currency'),
                'reference_no' => self::str('Reference number.'),
                'clientnote'   => self::str('Client note (defaults to the predefined credit note client note).'),
                'adminnote'    => self::str('Admin note.'),
                'project_id'   => self::int('Project id belonging to the customer (0 = none).'),
                'shipping'     => ['type' => 'object', 'description' => 'Shipping address; omit entirely when not needed.', 'properties' => ['street' => self::str('Street.'), 'city' => self::str('City.'), 'state' => self::str('State.'), 'zip' => self::str('ZIP.'), 'country' => self::int('Country id.')], 'additionalProperties' => false],
            ],
            'required'             => ['customer_id', 'items'],
            'additionalProperties' => false,
        ]);
        $t[] = self::tool('credit_notes_update', 'Update credit note', 'Update scalar fields on a credit note (date, reference, notes, terms, project). Status is never changed here; a void note is refused unless allow_voided_edit=true. Line items are not editable.', [CreditNoteTools::class, 'credit_notes_update'], $w, self::updateSchema(['date' => self::str('Date (Y-m-d).'), 'reference_no' => self::str('Reference number.'), 'clientnote' => self::str('Client note.'), 'adminnote' => self::str('Admin note.'), 'terms' => self::str('Terms.'), 'project_id' => self::int('Project id (0 = none).'), 'allow_voided_edit' => self::bool('Allow editing a void credit note (its status stays void).')]));
        $t[] = self::tool('credit_notes_void', 'Void credit note', 'Void an open credit note that has no credits applied and no refunds. Reopening a void note is done in Perfex.', [CreditNoteTools::class, 'credit_notes_void'], $w, self::idSchema('Credit note id.'));
        $t[] = self::tool('credit_notes_apply_to_invoice', 'Apply credit to invoice', 'Apply part of an open credit note to one of the same customer\'s unpaid, partially paid, overdue or draft invoices (same currency) and update the invoice status. Applying to a DRAFT permanently assigns it the next invoice number and requires acknowledge_draft_numbering=true. Requires edit on invoices too.', [CreditNoteTools::class, 'credit_notes_apply_to_invoice'], $w, ['type' => 'object', 'properties' => ['id' => self::int('Credit note id.'), 'invoice_id' => self::int('Invoice id.'), 'amount' => ['type' => 'number', 'description' => 'Amount to apply; at most the remaining credit and at most what is left to pay.'], 'acknowledge_draft_numbering' => self::bool('Required when the invoice is a draft.')], 'required' => ['id', 'invoice_id', 'amount'], 'additionalProperties' => false]);
        $t[] = self::tool('credit_notes_refund_create', 'Record credit note refund', 'Record a cash refund against an open credit note (reduces its remaining credit; closes it when fully used).', [CreditNoteTools::class, 'credit_notes_refund_create'], $w, ['type' => 'object', 'properties' => ['id' => self::int('Credit note id.'), 'amount' => ['type' => 'number', 'description' => 'Refunded amount; at most the remaining credit.'], 'payment_mode' => self::str('Payment mode id (Setup > Payment Modes).'), 'refunded_on' => self::str('Refund date (Y-m-d; default today).'), 'note' => self::str('Optional note.')], 'required' => ['id', 'amount', 'payment_mode'], 'additionalProperties' => false]);

        // Subscriptions
        $subscriptionFields = [
            'name'                => self::str('Subscription name (required on create).'),
            'customer_id'         => self::int('Customer id (required on create).'),
            'currency'            => self::currencySchema('defaults to the customer\'s currency, else the base currency'),
            'date'                => self::str('First billing date (Y-m-d); empty string clears it on update.'),
            'description'         => self::str('Description; line breaks are preserved.'),
            'description_in_item' => self::bool('Print the description on the generated invoice items.'),
            'terms'               => self::str('Terms; line breaks are preserved.'),
            'project_id'          => self::int('Project id belonging to the customer (0 = none).'),
            'quantity'            => self::int('Quantity (default 1).'),
            'tax_id'              => self::int('Tax id from Setup > Finance > Taxes (0 = none).'),
            'tax_id_2'            => self::int('Second tax id (0 = none).'),
            'stripe_plan_id'      => self::str('Stripe price/plan id to bill with; validated by Perfex when the customer subscribes.'),
        ];
        $t[] = self::tool('subscriptions_create', 'Create subscription', 'Create a subscription record (not yet subscribed on Stripe). Requires name and customer_id. No Stripe call is made.', [SubscriptionTools::class, 'subscriptions_create'], $w, self::fieldsSchema($subscriptionFields, ['name', 'customer_id']));
        $t[] = self::tool('subscriptions_update', 'Update subscription', 'Update fields on a subscription. Once the customer has subscribed on Stripe, customer_id, currency, quantity, stripe_plan_id and taxes are frozen here.', [SubscriptionTools::class, 'subscriptions_update'], $w, self::updateSchema($subscriptionFields));

        // Items (sales catalog)
        $itemFields = [
            'description'      => self::str('Item name / short description (required on create).'),
            'long_description' => self::str('Long description.'),
            'rate'             => ['type' => 'number', 'description' => 'Unit price in the base currency (required on create).'],
            'unit'             => self::str('Unit label.'),
            'tax'              => self::int('Tax id (0 = none).'),
            'tax2'             => self::int('Second tax id (0 = none).'),
            'group_id'         => self::int('Item group id (0 = none).'),
        ];
        $t[] = self::tool('items_create', 'Create item', 'Add an item to the sales catalog. Requires description and rate.', [ItemTools::class, 'items_create'], $w, self::fieldsSchema($itemFields, ['description', 'rate']));
        $t[] = self::tool('items_update', 'Update item', 'Update a catalog item. Documents that already used the item keep their copied line.', [ItemTools::class, 'items_update'], $w, self::updateSchema($itemFields));

        // Knowledge base
        $articleFields = [
            'subject'       => self::str('Article title (required on create).'),
            'description'   => self::str('Article body as HTML (required on create).'),
            'articlegroup'  => self::int('Group id (required on create; see knowledge_base_groups_list).'),
            'active'        => self::bool('Published (default true).'),
            'staff_article' => self::bool('Visible to staff only (default false).'),
        ];
        $t[] = self::tool('knowledge_base_create', 'Create knowledge base article', 'Create an article; Perfex generates the slug from the subject.', [KnowledgeBaseTools::class, 'knowledge_base_create'], $w, self::fieldsSchema($articleFields, ['subject', 'description', 'articlegroup']));
        $t[] = self::tool('knowledge_base_update', 'Update knowledge base article', 'Update an article. The slug is kept stable even when the subject changes.', [KnowledgeBaseTools::class, 'knowledge_base_update'], $w, self::updateSchema($articleFields + ['article_order' => self::int('Sort position within the group.')]));
        $t[] = self::tool('knowledge_base_groups_create', 'Create knowledge base group', 'Create an article group; returns the existing id when the name already exists (idempotent bootstrap).', [KnowledgeBaseTools::class, 'knowledge_base_groups_create'], $w, self::fieldsSchema(['name' => self::str('Group name (required).'), 'description' => self::str('Description.'), 'color' => self::str('Hex colour like #28B8DA.'), 'active' => self::bool('Published (default true).')], ['name']));

        // Announcements (admin only)
        $announcementFields = [
            'name'        => self::str('Title (required on create).'),
            'message'     => self::str('Message body as HTML (required on create).'),
            'showtostaff' => self::bool('Show to staff.'),
            'showtousers' => self::bool('Show to customers in the client portal.'),
            'showname'    => self::bool('Show the author name.'),
        ];
        $t[] = self::tool('announcements_create', 'Create announcement', 'Publish an announcement to staff and/or customers. Administrators only.', [SystemTools::class, 'announcements_create'], $w, self::fieldsSchema($announcementFields, ['name', 'message']));
        $t[] = self::tool('announcements_update', 'Update announcement', 'Update an announcement. Administrators only.', [SystemTools::class, 'announcements_update'], $w, self::updateSchema($announcementFields));

        return $t;
    }

    /**
     * Destructive (delete) tools. Only advertised when the destructive
     * kill-switch is on; each additionally requires a destructive-enabled key
     * and confirm=true at call time.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function destructiveTools(): array
    {
        $d   = new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false);
        $map = [
            ['customers_delete', 'Delete customer', [CustomerTools::class, 'customers_delete'], 'Customer id.'],
            ['contacts_delete', 'Delete contact', [ContactTools::class, 'contacts_delete'], 'Contact id.'],
            ['leads_delete', 'Delete lead', [LeadTools::class, 'leads_delete'], 'Lead id.'],
            ['invoices_delete', 'Delete invoice', [InvoiceTools::class, 'invoices_delete'], 'Invoice id.'],
            ['estimates_delete', 'Delete estimate', [EstimateTools::class, 'estimates_delete'], 'Estimate id.'],
            ['payments_delete', 'Delete payment', [PaymentTools::class, 'payments_delete'], 'Payment id.'],
            ['expenses_delete', 'Delete expense (cascades attachments, tasks, reminders; refused while billed on an invoice)', [ExpenseTools::class, 'expenses_delete'], 'Expense id.'],
            ['tasks_delete', 'Delete task', [TaskTools::class, 'tasks_delete'], 'Task id.'],
            ['tickets_delete', 'Delete ticket', [TicketTools::class, 'tickets_delete'], 'Ticket id.'],
            ['projects_delete', 'Delete project', [ProjectTools::class, 'projects_delete'], 'Project id.'],
            ['notes_delete', 'Delete note', [NoteTools::class, 'notes_delete'], 'Note id.'],
            ['proposals_delete', 'Delete proposal (cascades comments, notes, attachments, line items, tags and every task linked to it)', [ProposalTools::class, 'proposals_delete'], 'Proposal id.'],
            ['contracts_delete', 'Delete contract (cascades comments, notes, renewals, attachments and every task linked to it)', [ContractTools::class, 'contracts_delete'], 'Contract id.'],
            ['credit_notes_delete', 'Delete credit note (refused while credits are applied or the note is closed; may decrement the next credit note number)', [CreditNoteTools::class, 'credit_notes_delete'], 'Credit note id.'],
            ['items_delete', 'Delete catalog item', [ItemTools::class, 'items_delete'], 'Item id.'],
            ['knowledge_base_delete', 'Delete knowledge base article', [KnowledgeBaseTools::class, 'knowledge_base_delete'], 'Article id.'],
            ['announcements_delete', 'Delete announcement (administrators only)', [SystemTools::class, 'announcements_delete'], 'Announcement id.'],
        ];

        $t = [];
        foreach ($map as [$name, $title, $handler, $idDesc]) {
            $t[] = self::tool($name, $title, $title . '. Requires confirm=true and a destructive-enabled key.', $handler, $d, [
                'type'       => 'object',
                'properties' => [
                    'id'      => self::int($idDesc),
                    'confirm' => ['type' => 'boolean', 'description' => 'Must be true to actually delete.'],
                ],
                'required'             => ['id', 'confirm'],
                'additionalProperties' => false,
            ]);
        }

        // Not id+confirm shaped: reversing one application of credit needs
        // the credit note, the applied-credit row and the invoice.
        $t[] = self::tool('credit_notes_unapply', 'Remove applied credit', 'Remove one application of credit from an invoice (applied_credit_id from credit_notes_get -> applied_credits[].id); recomputes both the credit note and the invoice status. Requires confirm=true, a destructive-enabled key and edit on invoices.', [CreditNoteTools::class, 'credit_notes_unapply'], $d, [
            'type'       => 'object',
            'properties' => [
                'id'                => self::int('Credit note id.'),
                'applied_credit_id' => self::int('Id of the applied-credit record to remove.'),
                'invoice_id'        => self::int('Invoice the credit was applied to.'),
                'confirm'           => ['type' => 'boolean', 'description' => 'Must be true to actually remove it.'],
            ],
            'required'             => ['id', 'applied_credit_id', 'invoice_id', 'confirm'],
            'additionalProperties' => false,
        ]);

        return $t;
    }

    // -- helpers --------------------------------------------------------------

    private static function tool(string $name, string $title, string $description, array $handler, ToolAnnotations $annotations, array $inputSchema): array
    {
        return compact('name', 'title', 'description', 'handler', 'annotations', 'inputSchema');
    }

    /**
     * Build a list-tool input schema: the given extra properties plus standard
     * limit/offset pagination.
     */
    private static function listSchema(array $extraProps, bool $withSearchNote = true): array
    {
        $props = array_merge($extraProps, [
            'limit'  => ['type' => 'integer', 'description' => 'Page size (1-100, default 25).', 'minimum' => 1, 'maximum' => 100],
            'offset' => ['type' => 'integer', 'description' => 'Records to skip.', 'minimum' => 0],
        ]);

        return [
            'type'                 => 'object',
            'properties'           => $props,
            'additionalProperties' => false,
        ];
    }

    /** id schema, optionally with extra optional properties (e.g. render flags). */
    private static function idSchema(string $description, array $extraProps = []): array
    {
        return [
            'type'                 => 'object',
            'properties'           => array_merge(['id' => ['type' => 'integer', 'description' => $description]], $extraProps),
            'required'             => ['id'],
            'additionalProperties' => false,
        ];
    }

    private static function str(string $description): array
    {
        return ['type' => 'string', 'description' => $description];
    }

    private static function int(string $description): array
    {
        return ['type' => 'integer', 'description' => $description];
    }

    private static function bool(string $description): array
    {
        return ['type' => 'boolean', 'description' => $description];
    }

    /** Currency as ISO code or id (the handler parameter stays untyped for the union). */
    private static function currencySchema(string $defaultNote): array
    {
        return ['type' => ['string', 'integer'], 'description' => 'Currency as ISO code (e.g. EUR) or currency id; ' . $defaultNote . '.'];
    }

    /** Line items shared by every sales document create tool. */
    private static function itemsSchema(): array
    {
        return [
            'type'        => 'array',
            'description' => 'Line items.',
            'items'       => self::obj([
                'description'      => self::str('Item description (required).'),
                'qty'              => ['type' => 'number', 'description' => 'Quantity (default 1).'],
                'rate'             => ['type' => 'number', 'description' => 'Unit price (default 0).'],
                'long_description' => self::str('Optional long description.'),
                'unit'             => self::str('Optional unit label.'),
            ], ['description']),
        ];
    }

    /** An object schema with the given properties and required list. */
    private static function obj(array $props, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $props, 'additionalProperties' => false];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /** Create-tool schema: a single required "fields" object. */
    private static function fieldsSchema(array $props, array $required): array
    {
        return [
            'type'                 => 'object',
            'properties'           => ['fields' => self::obj($props, $required)],
            'required'             => ['fields'],
            'additionalProperties' => false,
        ];
    }

    /** Update-tool schema: id + a "fields" object of updatable properties. */
    /**
     * Payment modes an invoice offers: offline mode ids from Setup > Payment
     * Modes and/or gateway ids such as "stripe". Ids are validated against the
     * install. Offline modes can print their bank/payment details on the
     * invoice, so the selection is deliberate, never implied.
     */
    private static function modesSchema(): array
    {
        return [
            'type'        => 'array',
            'description' => 'Allowed payment modes: offline mode ids (Setup > Payment Modes) and/or gateway ids like "stripe". Empty array = none offered. Offline modes may print their bank details on the invoice.',
            'items'       => ['type' => 'string'],
        ];
    }

    private static function updateSchema(array $props): array
    {
        return [
            'type'                 => 'object',
            'properties'           => ['id' => self::int('Record id.'), 'fields' => self::obj($props)],
            'required'             => ['id', 'fields'],
            'additionalProperties' => false,
        ];
    }

    /** id + status schema for status-change tools. */
    private static function idStatusSchema(string $idDesc): array
    {
        return [
            'type'                 => 'object',
            'properties'           => ['id' => self::int($idDesc), 'status' => self::int('New status id.')],
            'required'             => ['id', 'status'],
            'additionalProperties' => false,
        ];
    }

    /** Invoice/estimate create schema: customer + line items + dates. */
    private static function salesDocSchema(string $dateField, string $dateDesc): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'customer_id' => self::int('Customer id.'),
                'items'       => self::itemsSchema(),
                'date'      => self::str('Document date (Y-m-d; default today).'),
                $dateField  => self::str($dateDesc),
                'currency'  => self::int('Currency id (0 = base currency).'),
            ],
            'required'             => ['customer_id', 'items'],
            'additionalProperties' => false,
        ];
    }

    /** Record-payment schema. */
    private static function paymentSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'invoice_id'     => self::int('Invoice id.'),
                'amount'         => ['type' => 'number', 'description' => 'Payment amount.'],
                'payment_mode'   => self::str('Payment mode id (see CRM payment modes).'),
                'date'           => self::str('Payment date (Y-m-d; default today).'),
                'transaction_id' => self::str('Optional transaction id.'),
                'note'           => self::str('Optional note.'),
                'send_email'     => ['type' => 'boolean', 'description' => 'Email the payment receipt (default false).'],
            ],
            'required'             => ['invoice_id', 'amount', 'payment_mode'],
            'additionalProperties' => false,
        ];
    }
}

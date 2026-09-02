# MCP Connector for Perfex CRM

Turn a [Perfex CRM](https://www.perfexcrm.com/) install into an
[MCP](https://modelcontextprotocol.io/) server, so Claude and any other MCP
client can read and manage the CRM in plain language - *"list overdue
invoices for ACME"*, *"open a support ticket for this customer"*, *"book this
hosting invoice as an expense and attach the PDF"*.

Every API key acts as a specific staff member and can do only what that
staff member may do in Perfex, enforced by Perfex's own permission system
and mirrored down to row visibility. Writes and deletes sit behind
independent kill-switches, and every call is audit-logged.

Free and open source, MIT licensed.

## What you get

- **110 tools** across customers, contacts, leads, proposals, estimates,
  invoices, payments, credit notes, contracts, subscriptions, expenses, the
  items catalog, tasks, tickets, projects, knowledge base, announcements,
  notes, a staff directory and custom-field discovery - list/get,
  create/update, and (optional) delete for each, plus conversions
  (lead → customer, estimate → invoice, proposal → invoice), sending,
  payment recording, credit-note application and refunds, expense receipt
  uploads, ticket replies, task status and comments.
- **Built on the official MCP PHP SDK** (`mcp/sdk`), bundled - no Composer,
  no shell access needed to install.
- **Real permission enforcement.** Every call runs as the key's staff member
  through Perfex's `staff_can()`. Every read is scoped exactly as Perfex
  scopes it for that person (see [Visibility](#visibility)).
- **Three-layer write safety.** Global switches for writes and deletes, a
  per-key "allow destructive" flag, and `confirm: true` on every delete.
- **Audit log.** Tool, staff member, arguments, result, duration and IP for
  every call - successful, failed or denied. Secrets and file payloads are
  redacted.
- **Per-key IP whitelist** and rate limit.

## Requirements

- Perfex CRM 3.0 or newer (developed and tested against 3.4.1)
- PHP 8.1 or newer (tested on 8.3 and 8.4)
- An MCP client: Claude Code, claude.ai custom connectors, or anything that
  speaks MCP over HTTP (both the handshake era and the 2026-07-28 modern era
  are served)

## Install

1. Download `mcp_connector.zip` from the latest
   [release](../../releases) (or build it: `bash packaging/build-zip.sh`).
2. In Perfex go to **Setup → Modules → Upload Module**, choose the zip,
   then click **Activate** next to "MCP Connector".
3. A **Setup → MCP Connector** menu item appears. Create your first API key
   there.

Without a browser: copy `mcp_connector/` into Perfex's `modules/` directory
and run, from the Perfex document root:

```
php index.php mcp_connector mcp_cli activate
php index.php mcp_connector mcp_cli status
```

**Upgrading**: replace the module files (upload the new zip, or copy the
folder over) and run **Upgrade Database** on the Modules page, or
`php index.php mcp_connector mcp_cli upgrade`.

## API keys

On **Setup → MCP Connector**:

1. Enter a **label** (e.g. "Claude desktop").
2. Choose the **staff member** the key acts as. The key can only do what
   that staff member is allowed to do. Only administrators can create keys
   for other staff; everyone else creates keys for themselves.
3. Optionally tick **Allow destructive tools** to let this key delete
   records (also requires the global switch, below), set a rate limit, and
   restrict the key to source IPs or CIDR ranges.
4. Click **Create API key** and **copy the key now** - it is shown once;
   only its SHA-256 hash is stored.

Headless: `php index.php mcp_connector mcp_cli create_key <staff_id> <label>`
mints a key the same way and prints it once.

A good pattern for agents is a dedicated staff member with exactly the
permissions the agent needs; the connector shows that staff member what
Perfex would show them, nothing more.

## Connecting a client

**Claude Code**

```
claude mcp add --transport http perfex \
  https://your-crm.example.com/mcp_connector/mcp \
  --header "Authorization: Bearer YOUR_API_KEY"
```

**claude.ai** (custom connector): Settings → Connectors → Add custom
connector, remote MCP server URL `https://your-crm.example.com/mcp_connector/mcp`,
authentication header `Authorization: Bearer YOUR_API_KEY`.

The exact endpoint URL for your install is shown on the MCP Connector admin
page.

## Permissions and safety

| Layer | What it does |
|---|---|
| Staff permissions | Every call runs as the key's staff member. Perfex's capability checks (`view`, `view_own`, `create`, `edit`, `delete`) apply to each tool; a call the staff member may not make returns an error and changes nothing. |
| Visibility | Reads are filtered to the rows Perfex itself would show that staff member (below). |
| Global switches | On the admin page: enable/disable the whole endpoint, all write tools, and all destructive (delete) tools independently. Disabled tools do not appear to the client at all. |
| Per-key destructive flag + confirmation | Delete tools require the global destructive switch ON, the key's "allow destructive" flag ON, and `confirm: true` at call time. |
| Per-key IP whitelist | One IP or CIDR per line (IPv4 or IPv6). Requests from other addresses are rejected (403) and logged. |
| Audit log | Every tool call, with arguments (secrets and file payloads redacted), result, duration and IP. |

By default, write tools are enabled and destructive tools are disabled.

If the CRM sits behind a reverse proxy, make sure Perfex sees the real
client IP before relying on the IP whitelist.

### Visibility

Perfex has no central "may this staff member see this row" function; each
module decides in its own controller, model or list script. The connector
carries all of those rules in one table (`src/Auth/Visibility.php`) and
applies them to every list, re-checking every single-record read with the
same predicate. A feature missing from the table is refused rather than
served unfiltered. For a staff member **without** global view:

| Module | What they see |
|---|---|
| Invoices, estimates, proposals | Documents they created (`view_own`), plus documents they are sale agent / assignee of when the "allow staff to view assigned …" settings are on (Perfex's defaults) - the latter even without `view_own`, as in Perfex |
| Expenses, contracts, credit notes, subscriptions | Documents they created |
| Leads | Assigned to them, created by them, or public |
| Customers, contacts | Customers they are a customer admin of |
| Projects | Projects they are a member of |
| Tasks | Assigned to, followed by or created by them, public tasks, and (setting) tasks of their projects |
| Tickets | Tickets of their departments when "staff access only assigned departments" is on; none without a department |
| Payments | Follow the invoices rule |
| Staff | Names and active flag for everyone; the full directory only with `view` on staff |

Writes stay capability-gated as in Perfex, with Perfex's own exceptions
mirrored: editing or converting a lead follows lead visibility, task status
changes are open to the assignee and creator, ticket replies and status
changes follow department access, a ticket can only be opened in a visible
department, a customer's admin may edit it, and a creator without global
view becomes admin of the customer they create. Notes follow their parent
record. Administrators see everything.

## Tool catalog

Read tools (`*_list`, `*_get`) are always available; write and delete tools
depend on the switches above. Lists paginate with `limit` (1-100) and
`offset`.

| Entity | Read | Write | Delete |
|---|---|---|---|
| Customers | list, get | create, update | delete |
| Contacts | list, get | create, update | delete |
| Leads | list, get | create, update, convert_to_customer | delete |
| Proposals | list, get | create (draft), update, change_status, send, convert_to_invoice | delete |
| Estimates | list, get | create (draft), update, send, convert_to_invoice | delete |
| Invoices | list, get | create (draft), update, send, record_payment | delete |
| Payments | list, get | (record via invoices_record_payment) | delete |
| Credit notes | list, get | create, update, void, apply_to_invoice, refund_create | delete, unapply |
| Contracts | list, get, types_list | create, update, types_create | delete |
| Subscriptions | list, get | create, update | - (see notes) |
| Expenses | list, get, categories_list | create, update, attach_receipt, categories_create | delete |
| Items (catalog) | list, get | create, update | delete |
| Tasks | list, get | create, update, change_status, comment_add | delete |
| Tickets | list, get | create, reply, change_status | delete |
| Projects | list, get | create, update | delete |
| Knowledge base | list, get, groups_list | create, update, groups_create | delete |
| Announcements | list, get | create, update (admin only) | delete (admin only) |
| Notes | list | add | delete |
| Staff | list, get | - | - |
| Custom fields | list (discovery) | - | - |

### Notes per module

**Invoices and estimates.** Creation produces a **draft** with line items
(description, quantity, rate); draft-first avoids number collisions - finalize
or send separately. Invoices can be created or updated as recurring (every
1-12 months, optional cycle cap) and with explicit payment modes (offline
mode ids and/or gateway ids such as `stripe`, validated against the install).
Offline modes may print bank details on the invoice, so the selection is
never implied. Per-item taxes and discounts are not set by the create tools.

**Expenses.** Amounts are net, per currency - totals only make sense within
one currency; expense taxes are not exposed. Recurrence follows Perfex's
cron: the recurring expense is the template and a real occurrence; generated
occurrences link back via `recurring_from`. `expenses_attach_receipt` takes
the file as base64 (PDF, PNG, JPG or WebP; 5 MiB decoded cap - needs
`post_max_size` ≥ 8M and the `fileinfo` extension), verifies the type from
the content, and stores it where Perfex keeps receipts; one receipt per
expense, `replace=true` to swap. Deleting an expense removes its attachment
folder, related tasks and reminders; it is refused while billed on an
invoice.

**Proposals and contracts.** Both carry a body Perfex renders through merge
fields. `*_get` returns the raw template; `render_merge_fields=true` returns
it rendered and flags the response `content_is_rendered` - never write a
rendered value back. A proposal's body template is fixed at creation and
edited in Perfex; a contract's `content` is editable. Proposal totals are
computed from the line items you pass; line items are not editable after
creation. Contracts have no status, currency or line items -
`contract_value` is in the base currency - and once signed their customer,
value and dates are frozen. Deleting a proposal or contract also deletes
every task linked to it; the result reports the counts.

**Credit notes.** Remaining credit is never stored; `credit_notes_get`
derives it as total − applied − refunded. `credit_notes_apply_to_invoice`
checks customer, currency, remaining credit and what is left to pay, then
updates the invoice status; applying to a **draft** invoice makes Perfex
assign it the next invoice number permanently, so that requires
`acknowledge_draft_numbering=true`. Updates never change status (voiding is
its own tool; reopening stays in Perfex); a void note is refused unless
`allow_voided_edit=true`. Deletion is refused while credits are applied or
the note is closed, and may decrement Perfex's next credit note number.

**Subscriptions** are CRUD only and never call Stripe: subscribing,
cancelling, resuming and invoice generation stay in Perfex, and fields Stripe
owns are frozen once the customer has subscribed. There is deliberately no
`subscriptions_delete`: Perfex 3.4.1's own delete detaches every
subscription-linked invoice in the install.

**Catalog and content.** Items are the reusable sales-item library
(base-currency rates; documents that used an item keep their own copy).
Knowledge base articles and announcements carry HTML bodies returned
verbatim; article slugs are generated on create and kept stable on update.
Expense categories, contract types and knowledge-base groups are
create/list-only (rename/delete stay in Perfex Setup), and creating one is
allowed for any key that can create the parent record - a small delta from
Perfex's admin-only Setup area so a fresh install can be bootstrapped in
one call. Announcements are administrator-only. `custom_fields_list` is
discovery only: it shows an entity's custom fields with the exact `fieldto`
value Perfex uses (inconsistently pluralized upstream) so a client can read
them back from `*_get` results; custom field values are not written.

**Nothing emails a customer as a side effect.** Sending is always its own
named tool (`invoices_send`, `estimates_send`, `proposals_send`, ticket
replies); Perfex's "save and send" flags are never forwarded.

## Troubleshooting

**"Missing bearer token" / 401 with a correct key.** Some Apache/FastCGI
setups strip the `Authorization` header before PHP sees it. Add one of:

```
# Apache 2.4.13+
CGIPassAuth On

# or, in .htaccess / vhost
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
</IfModule>
```

"Missing bearer token" means the header never reached PHP (server problem);
"Invalid API key" / "revoked" / "expired" means it did and the key is the
problem; a rejected IP whitelist returns 403, never 401.

**403 "Invalid Host header".** The request's Host does not match Perfex's
configured Site URL.

**A WAF or mod_security blocks requests.** Whitelist the
`/mcp_connector/mcp` path; if you use fail2ban, add your AI provider's egress
range to `ignoreip`.

**Not all tools appear.** Write and destructive tools only appear when
enabled in Settings, and clients must follow `tools/list` pagination (the
server returns the whole catalog in one page).

## Development

```
mcp_connector/          the module (vendor/ is committed and shipped)
  src/Tools/            one class per entity + ToolRegistrar (the catalog)
  src/Auth/             key service, Visibility table, ReadScope
  src/Support/          audit log, session store
  controllers/          Mcp (endpoint), Mcp_connector (admin), Mcp_cli
  migrations/           ONE cumulative file, numbered for the header version
dev/                    local harness, not shipped
packaging/build-zip.sh  builds dist/mcp_connector.zip
```

Run the protocol harness (real `mcp/sdk`, Perfex stubbed; both protocol
eras, every tool's schema against its handler, the visibility predicates):

```
php dev/protocol_test.php
```

Adding a tool: a method on the entity's `*Tools` class wrapped in
`guard()`, an entry in `ToolRegistrar` (read, write or destructive tier),
and - for a new entity - a rule in `Visibility::rules()`. Handler parameter
names must match the schema's property names (the SDK binds by name), and
the harness fails the run for any list tool without a visibility rule.

Releasing: bump the version in `mcp_connector/mcp_connector.php` (header and
constant) and `dev/protocol_test.php`, rename the migration file to the new
number (Perfex aborts when two pending migrations are more than one apart,
which is why exactly one cumulative file ships), then
`bash packaging/build-zip.sh`.

## License

[MIT](LICENSE). The bundled MCP PHP SDK and its dependencies carry their own
MIT licenses under `mcp_connector/vendor/`.

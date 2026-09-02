# Changelog

## 1.0.0 - 2026-09-02

First public release, MIT licensed. Everything below was built and run
privately against Perfex CRM 3.4.1 before this point and ships together:

- 110 tools across customers, contacts, leads, proposals, estimates,
  invoices, payments, credit notes, contracts, subscriptions, expenses, the
  items catalog, tasks, tickets, projects, knowledge base, announcements,
  notes, a staff directory and custom-field discovery.
- Built on the official MCP PHP SDK (`mcp/sdk` 0.8.1), bundled; both the
  handshake protocol era and the 2026-07-28 modern era are served.
- Permission enforcement through Perfex's own `staff_can()` for every call,
  and visibility parity: every read is scoped exactly as Perfex scopes it
  for the key's staff member, from one table (`src/Auth/Visibility.php`),
  fail-closed for anything not in it.
- Three-tier write safety (global write and destructive switches, per-key
  destructive flag, `confirm: true`), per-key IP whitelist and rate limit,
  full audit log with secret and payload redaction.
- Draft-first invoice and estimate creation with line items, recurring
  invoices, explicit payment modes, customer billing details; expense
  tracking with base64 receipt attachments; credit notes with
  apply-to-invoice, refunds, void and unapply; proposals and contracts with
  merge-field rendering kept read-only; subscriptions CRUD without Stripe
  side effects.
- Nothing emails a customer as a side effect; sending is always its own
  named tool.
- `mcp_cli` for headless installs: activate, deactivate, status, upgrade,
  create_key.

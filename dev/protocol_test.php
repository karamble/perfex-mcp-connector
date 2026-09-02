<?php

/*
 * Local end-to-end test of the MCP protocol layer against the real mcp/sdk,
 * using the Perfex stubs. Validates: server builds, initialize handshake,
 * tools/list reflects the registrar, tools/call routes to CustomerTools and
 * returns structured content, and DbSessionStore round-trips the session.
 *
 * Run: php8.3 dev/protocol_test.php
 */

require __DIR__ . '/perfex_stubs.php';
require __DIR__ . '/../mcp_connector/vendor/autoload.php';

define('MCP_CONNECTOR_VERSION', '1.0.0');

use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PerfexMcp\McpServerFactory;

// Seed canned customer data into the fake DB + model.
$ci = get_instance();
$ci->db->canned['clients'] = [
    ['userid' => 1, 'company' => 'Acme Ltd', 'vat' => 'GB123', 'phonenumber' => '+441234', 'country' => 0, 'city' => 'London', 'state' => '', 'zip' => 'EC1', 'datecreated' => '2026-01-01 10:00:00', 'active' => 1],
    ['userid' => 2, 'company' => 'Globex', 'vat' => '', 'phonenumber' => '', 'country' => 0, 'city' => 'Berlin', 'state' => '', 'zip' => '10115', 'datecreated' => '2026-02-01 09:00:00', 'active' => 1],
];
$ci->clients_model->canned[1] = (object) ['userid' => 1, 'company' => 'Acme Ltd', 'vat' => 'GB123'];

// Expense fixtures: two occurrences of a monthly bill plus the lookup tables
// expenses_list decorates from (both may be empty on a real greenfield
// install - the canned rows here exercise the decorated path).
$ci->db->canned['expenses_categories'] = [
    ['id' => 1, 'name' => 'Hosting', 'description' => ''],
];
$ci->db->canned['currencies'] = [
    ['id' => 1, 'name' => 'USD', 'symbol' => '$', 'isdefault' => 1],
    ['id' => 2, 'name' => 'EUR', 'symbol' => "\u{20AC}", 'isdefault' => 0],
];
$ci->db->canned['expenses'] = [
    ['id' => 1, 'category' => 1, 'currency' => 2, 'amount' => '71.00', 'tax' => 0, 'tax2' => 0, 'reference_no' => 'HZ-1', 'expense_name' => 'Root server', 'date' => '2026-08-01', 'billable' => 0, 'invoiceid' => null, 'clientid' => 0, 'project_id' => 0, 'paymentmode' => '1', 'recurring' => 1, 'repeat_every' => 1, 'recurring_type' => 'month', 'cycles' => 0, 'total_cycles' => 1, 'last_recurring_date' => '2026-09-01', 'recurring_from' => null, 'dateadded' => '2026-08-01 09:00:00', 'addedfrom' => 1],
    ['id' => 2, 'category' => 1, 'currency' => 2, 'amount' => '71.00', 'tax' => 0, 'tax2' => 0, 'reference_no' => 'HZ-2', 'expense_name' => 'Root server', 'date' => '2026-09-01', 'billable' => 0, 'invoiceid' => null, 'clientid' => 0, 'project_id' => 0, 'paymentmode' => '1', 'recurring' => 0, 'repeat_every' => 0, 'recurring_type' => null, 'cycles' => 0, 'total_cycles' => 0, 'last_recurring_date' => null, 'recurring_from' => 1, 'dateadded' => '2026-09-01 09:00:00', 'addedfrom' => 1],
];

// v1.5.0 fixtures: one row per new list tool plus the lookup tables their
// decoration reads. FakeDb ignores WHERE, so each list returns every row.
$ci->db->canned['proposals'] = [
    ['id' => 1, 'subject' => 'Website redesign', 'rel_type' => 'customer', 'rel_id' => 1, 'proposal_to' => 'Acme Ltd', 'email' => 'cfo@acme.test', 'phone' => '', 'date' => '2026-09-01', 'open_till' => '2026-09-15', 'currency' => 1, 'subtotal' => '1500.00', 'total_tax' => '0.00', 'total' => '1500.00', 'discount_total' => '0.00', 'status' => 6, 'assigned' => 0, 'project_id' => null, 'estimate_id' => null, 'invoice_id' => null, 'date_converted' => null, 'allow_comments' => 0, 'addedfrom' => 1, 'datecreated' => '2026-09-01 10:00:00'],
];
$ci->db->canned['contracts_types'] = [
    ['id' => 1, 'name' => 'Retainer'],
];
$ci->db->canned['contracts'] = [
    ['id' => 1, 'subject' => 'Monthly retainer', 'client' => 1, 'contract_type' => 1, 'datestart' => '2026-01-01', 'dateend' => '2026-12-31', 'contract_value' => '12000.00', 'project_id' => null, 'trash' => 0, 'not_visible_to_client' => 0, 'signed' => 0, 'marked_as_signed' => 0, 'last_sent_at' => null, 'addedfrom' => 1, 'dateadded' => '2026-01-01 09:00:00'],
];
$ci->db->canned['creditnotes'] = [
    ['id' => 1, 'clientid' => 1, 'number' => 1, 'prefix' => 'CN-', 'formatted_number' => 'CN-000001', 'date' => '2026-08-15', 'datecreated' => '2026-08-15 12:00:00', 'currency' => 1, 'subtotal' => '200.00', 'total_tax' => '0.00', 'total' => '200.00', 'discount_total' => '0.00', 'status' => 1, 'project_id' => 0, 'reference_no' => 'RMA-7', 'addedfrom' => 1],
];
$ci->db->canned['subscriptions'] = [
    ['id' => 1, 'name' => 'Hosting plan', 'clientid' => 1, 'project_id' => 0, 'currency' => 1, 'date' => '2026-09-01', 'status' => null, 'quantity' => 1, 'tax_id' => 0, 'tax_id_2' => 0, 'stripe_plan_id' => 'price_123', 'stripe_subscription_id' => '', 'next_billing_cycle' => null, 'ends_at' => null, 'date_subscribed' => null, 'created' => '2026-09-01 08:00:00', 'created_from' => 1, 'in_test_environment' => null, 'last_sent_at' => null, 'hash' => 'secret-hash'],
];
$ci->db->canned['items_groups'] = [
    ['id' => 1, 'name' => 'Services'],
];
$ci->db->canned['items'] = [
    ['id' => 1, 'description' => 'Consulting hour', 'long_description' => '', 'rate' => '100.00', 'tax' => null, 'tax2' => null, 'unit' => 'h', 'group_id' => 1],
];
$ci->db->canned['knowledge_base_groups'] = [
    ['groupid' => 1, 'name' => 'FAQ', 'group_slug' => 'faq', 'description' => '', 'active' => 1, 'color' => '#28B8DA', 'group_order' => 0],
];
$ci->db->canned['knowledge_base'] = [
    ['articleid' => 1, 'articlegroup' => 1, 'subject' => 'How to pay', 'slug' => 'how-to-pay', 'active' => 1, 'datecreated' => '2026-05-01 10:00:00', 'article_order' => 0, 'staff_article' => 0],
];
$ci->db->canned['announcements'] = [
    ['announcementid' => 1, 'name' => 'Office closed Friday', 'showtousers' => 0, 'showtostaff' => 1, 'showname' => 1, 'dateadded' => '2026-09-01 07:00:00', 'userid' => 'Admin User'],
];
$ci->db->canned['customfields'] = [
    ['id' => 1, 'fieldto' => 'proposal', 'name' => 'Region', 'slug' => 'proposal_region', 'type' => 'select', 'options' => "EU\nUS\n", 'required' => 0, 'active' => 1, 'only_admin' => 0, 'show_on_pdf' => 1, 'show_on_client_portal' => 0, 'disalow_client_to_edit' => 0, 'default_value' => '', 'field_order' => 0],
];

$psr17 = new Psr17Factory();

$sessionId       = null;
$protocolVersion = '2025-06-18';

function mcp_call(string $method, ?array $params, bool $isNotification = false): array
{
    global $psr17, $sessionId, $protocolVersion;

    $payload = ['jsonrpc' => '2.0', 'method' => $method];
    if (! $isNotification) {
        static $id = 0;
        $payload['id'] = ++$id;
    }
    if ($params !== null) {
        $payload['params'] = $params;
    }
    // The modern (2026-07-28) era carries the revision in params._meta rather
    // than in the MCP-Protocol-Version header, and rejects the request without it.
    if ($protocolVersion === '2026-07-28' && $method !== 'initialize') {
        $payload['params'] ??= [];
        $payload['params']['_meta']['io.modelcontextprotocol/protocolVersion']   = $protocolVersion;
        $payload['params']['_meta']['io.modelcontextprotocol/clientCapabilities'] = (object) [];
    }
    $body = json_encode($payload);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $request = $psr17->createServerRequest('POST', 'http://localhost/mcp_connector/mcp')
        ->withHeader('Host', 'localhost')
        ->withHeader('Content-Type', 'application/json')
        ->withHeader('Accept', 'application/json, text/event-stream')
        ->withBody($psr17->createStream($body));

    if ($sessionId !== null) {
        $request = $request->withHeader('Mcp-Session-Id', $sessionId);
    }
    if ($method !== 'initialize') {
        $request = $request->withHeader('MCP-Protocol-Version', $protocolVersion);
    }
    // Modern era routes on explicit headers rather than the body: Mcp-Method
    // always, plus Mcp-Name for subject-addressed calls such as tools/call.
    if ($protocolVersion === '2026-07-28' && $method !== 'initialize') {
        $request = $request->withHeader('Mcp-Method', $method);
        if (isset($params['name'])) {
            $request = $request->withHeader('Mcp-Name', (string) $params['name']);
        }
    }

    $server    = McpServerFactory::create();
    $transport = new StreamableHttpTransport($request, $psr17, $psr17, null, McpServerFactory::middleware('localhost'));
    $response  = $server->run($transport);

    // Capture negotiated session id from the initialize response.
    if ($response->hasHeader('Mcp-Session-Id')) {
        $sessionId = $response->getHeaderLine('Mcp-Session-Id');
    }

    $raw  = (string) $response->getBody();
    $json = decode_mcp_body($response->getHeaderLine('Content-Type'), $raw);

    return [
        'status'  => $response->getStatusCode(),
        'session' => $response->getHeaderLine('Mcp-Session-Id'),
        'json'    => $json,
        'raw'     => $raw,
    ];
}

// The streamable transport may answer as application/json or as an SSE stream.
function decode_mcp_body(string $contentType, string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (stripos($contentType, 'text/event-stream') !== false) {
        // Extract the last `data:` line from the SSE frame(s).
        $data = null;
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (str_starts_with($line, 'data:')) {
                $data = trim(substr($line, 5));
            }
        }
        $raw = $data ?? '';
    }

    return $raw === '' ? null : json_decode($raw, true);
}

function show(string $label, array $res): void
{
    echo "\n=== {$label} ===\n";
    echo 'HTTP ' . $res['status'] . '  session=' . ($res['session'] ?: '(none)') . "\n";
    echo json_encode($res['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

$assertFail = 0;
function assert_true(string $what, bool $cond): void
{
    global $assertFail;
    echo ($cond ? '  [PASS] ' : '  [FAIL] ') . $what . "\n";
    if (! $cond) { $assertFail++; }
}

// 1) initialize
$r = mcp_call('initialize', [
    'protocolVersion' => $protocolVersion,
    'capabilities'    => new stdClass(),
    'clientInfo'      => ['name' => 'harness', 'version' => '1.0'],
]);
show('initialize', $r);
assert_true('initialize returns 200', $r['status'] === 200);
assert_true('session id issued', $r['session'] !== '');
assert_true('serverInfo present', isset($r['json']['result']['serverInfo']));

// 2) notifications/initialized
$r = mcp_call('notifications/initialized', null, true);
echo "\n=== notifications/initialized ===\nHTTP " . $r['status'] . "\n";
assert_true('initialized accepted (2xx)', $r['status'] >= 200 && $r['status'] < 300);

// 3) tools/list
$r = mcp_call('tools/list', []);
show('tools/list', $r);
$names = array_map(fn ($t) => $t['name'], $r['json']['result']['tools'] ?? []);
assert_true('customers_list advertised', in_array('customers_list', $names, true));
assert_true('customers_get advertised', in_array('customers_get', $names, true));
foreach (['contacts_list', 'leads_list', 'invoices_list', 'estimates_list', 'payments_list', 'expenses_list', 'expense_categories_list', 'tasks_list', 'tickets_list', 'projects_list', 'notes_list', 'staff_list',
    'proposals_list', 'contracts_list', 'contract_types_list', 'credit_notes_list', 'subscriptions_list', 'items_list', 'knowledge_base_list', 'knowledge_base_groups_list', 'announcements_list', 'custom_fields_list'] as $expected) {
    assert_true($expected . ' advertised', in_array($expected, $names, true));
}
// Stub enables writes, disables destructive.
assert_true('write tool advertised (customers_create)', in_array('customers_create', $names, true));
assert_true('write action advertised (leads_convert_to_customer)', in_array('leads_convert_to_customer', $names, true));
assert_true('write tool advertised (expenses_attach_receipt)', in_array('expenses_attach_receipt', $names, true));
assert_true('write tool advertised (proposals_create)', in_array('proposals_create', $names, true));
assert_true('write action advertised (credit_notes_apply_to_invoice)', in_array('credit_notes_apply_to_invoice', $names, true));
assert_true('destructive tool NOT advertised (destructive off)', ! in_array('customers_delete', $names, true));
assert_true('destructive tool NOT advertised (expenses_delete)', ! in_array('expenses_delete', $names, true));
assert_true('destructive tool NOT advertised (credit_notes_unapply)', ! in_array('credit_notes_unapply', $names, true));
assert_true('subscriptions_delete never advertised (upstream raw-SQL bug)', ! in_array('subscriptions_delete', $names, true));
assert_true('read+write set = 92 tools', count($names) === 92);
// Every advertised tool binds to an existing handler method with parameters
// matching its schema properties - the SDK maps arguments by name, so a
// rename on either side silently breaks the call.
$schemaMismatch = [];
foreach (\PerfexMcp\Tools\ToolRegistrar::all() as $tool) {
    [$class, $method] = $tool['handler'];
    if (! method_exists($class, $method)) {
        $schemaMismatch[] = $tool['name'] . ' -> missing ' . $class . '::' . $method;
        continue;
    }
    $params = array_map(fn ($p) => $p->getName(), (new ReflectionMethod($class, $method))->getParameters());
    $props  = array_keys($tool['inputSchema']['properties'] ?? []);
    if (array_diff($params, $props) !== [] || array_diff($props, $params) !== []) {
        $schemaMismatch[] = $tool['name'] . ' params [' . implode(',', $params) . '] vs schema [' . implode(',', $props) . ']';
    }
}
assert_true('every tool handler matches its schema properties' . ($schemaMismatch ? ': ' . implode('; ', $schemaMismatch) : ''), $schemaMismatch === []);

// 4) tools/call customers_list
$r = mcp_call('tools/call', ['name' => 'customers_list', 'arguments' => ['limit' => 10]]);
show('tools/call customers_list', $r);
$structured = $r['json']['result']['structuredContent'] ?? null;
assert_true('customers_list returned structured content', is_array($structured));
assert_true('customers_list total = 2', ($structured['total'] ?? null) === 2);
assert_true('customers_list data has 2 rows', count($structured['data'] ?? []) === 2);

// 5) tools/call customers_get
$r = mcp_call('tools/call', ['name' => 'customers_get', 'arguments' => ['id' => 1]]);
show('tools/call customers_get', $r);
$structured = $r['json']['result']['structuredContent'] ?? null;
assert_true('customers_get returned customer', isset($structured['customer']));

// 5b) tools/call expenses_list - exercises the union currency schema
// (string ISO code), paginate over the canned table, and null-safe
// decoration with category_name/currency_code.
$r = mcp_call('tools/call', ['name' => 'expenses_list', 'arguments' => ['currency' => 'EUR', 'limit' => 10]]);
show('tools/call expenses_list', $r);
$structured = $r['json']['result']['structuredContent'] ?? null;
assert_true('expenses_list returned structured content', is_array($structured));
assert_true('expenses_list total = 2', ($structured['total'] ?? null) === 2);
assert_true('expenses_list rows decorated with category_name', ($structured['data'][0]['category_name'] ?? null) === 'Hosting');
assert_true('expenses_list rows decorated with currency_code', ($structured['data'][0]['currency_code'] ?? null) === 'EUR');
assert_true('expenses_list omits note from list rows', ! array_key_exists('note', $structured['data'][0] ?? []));

// 5c) v1.5.0 list tools: each routes, paginates over its canned table and
// decorates null-safely. [tool, args, [key => expected value on row 0]]
$listSmokes = [
    ['proposals_list', ['limit' => 5], ['currency_code' => 'USD', 'status_name' => 'draft']],
    ['contracts_list', ['limit' => 5], ['type_name' => 'Retainer', 'customer_name' => 'Acme Ltd']],
    ['contract_types_list', [], ['name' => 'Retainer']],
    ['credit_notes_list', ['status' => 1], ['status_name' => 'open', 'customer_name' => 'Acme Ltd', 'currency_code' => 'USD']],
    ['subscriptions_list', [], ['subscribed_on_stripe' => false, 'customer_name' => 'Acme Ltd']],
    ['items_list', [], ['group_name' => 'Services']],
    ['knowledge_base_list', ['active' => true], ['id' => 1, 'group_name' => 'FAQ']],
    ['knowledge_base_groups_list', [], ['id' => 1, 'name' => 'FAQ']],
    ['announcements_list', [], ['id' => 1, 'name' => 'Office closed Friday']],
    ['custom_fields_list', ['fieldto' => 'proposal'], ['slug' => 'proposal_region', 'options' => ['EU', 'US']]],
];
foreach ($listSmokes as [$tool, $args, $expect]) {
    $r = mcp_call('tools/call', ['name' => $tool, 'arguments' => $args]);
    show('tools/call ' . $tool, $r);
    $structured = $r['json']['result']['structuredContent'] ?? null;
    assert_true($tool . ' returned structured content', is_array($structured) && ($r['json']['result']['isError'] ?? true) === false);
    assert_true($tool . ' total = 1', ($structured['total'] ?? null) === 1);
    foreach ($expect as $key => $value) {
        assert_true($tool . ' row decorated: ' . $key, ($structured['data'][0][$key] ?? null) === $value);
    }
}
$r = mcp_call('tools/call', ['name' => 'subscriptions_list', 'arguments' => []]);
assert_true('subscriptions_list strips hash from rows', ! array_key_exists('hash', $r['json']['result']['structuredContent']['data'][0] ?? ['hash' => 1]));
$r = mcp_call('tools/call', ['name' => 'custom_fields_list', 'arguments' => ['fieldto' => 'proposals']]);
assert_true('custom_fields_list rejects an unknown fieldto at the schema', ($r['json']['result']['isError'] ?? false) === true || isset($r['json']['error']));

// 8) v1.5.1 visibility parity. With a restricted (non-admin) staff member,
// every list applies Perfex's own row predicate - asserted as the exact raw
// WHERE string the Visibility table emits - gates deny what Perfex denies,
// single reads re-check the same predicate, and every advertised list tool
// must land in a classification (denied / scoped / open), so a future list
// tool cannot ship without a rule.
echo "\n=== visibility parity ===\n";
$ci->db->canned['staff'] = [
    ['staffid' => 7, 'firstname' => 'Res', 'lastname' => 'Tricted', 'email' => 'r@example.test', 'phonenumber' => '', 'admin' => 0, 'role' => 0, 'active' => 1, 'datecreated' => '2026-01-01 00:00:00', 'last_login' => null],
    ['staffid' => 1, 'firstname' => 'Ad', 'lastname' => 'Min', 'email' => 'a@example.test', 'phonenumber' => '', 'admin' => 1, 'role' => 0, 'active' => 1, 'datecreated' => '2026-01-01 00:00:00', 'last_login' => null],
];
$ci->db->canned['tickets']     = [['ticketid' => 1, 'subject' => 'Help', 'status' => 1, 'priority' => 2, 'department' => 5, 'userid' => 1, 'contactid' => 0, 'assigned' => 0, 'date' => '2026-09-01 10:00:00', 'lastreply' => null, 'service' => null]];
$ci->db->canned['departments'] = [['departmentid' => 5, 'name' => 'Support']];
$ci->db->canned['tasks']       = [['id' => 1, 'name' => 'Do it', 'priority' => 2, 'status' => 1, 'startdate' => '2026-09-01', 'duedate' => null, 'datefinished' => null, 'rel_id' => null, 'rel_type' => null, 'billable' => 0, 'billed' => 0, 'milestone' => 0, 'addedfrom' => 1, 'is_added_from_contact' => 0]];

function raw_wheres(): array
{
    global $ci;
    $out = [];
    foreach ($ci->db->whereLog as $w) {
        if ($w[2] === false) { $out[] = $w[0]; }
    }
    return $out;
}
function vis_reset(?array $perms, array $options = [], int $staff = 7): void
{
    global $ci;
    $GLOBALS['__perms']    = $perms;
    $GLOBALS['__options']  = $options;
    $GLOBALS['__staff_id'] = $staff;
    $ci->db->whereLog      = [];
    $ci->db->scopedCount   = [];
    $ci->db->projectSelect = false;
}
function vis_call(string $tool, array $args): array
{
    global $ci;
    $ci->db->whereLog = [];
    $r   = mcp_call('tools/call', ['name' => $tool, 'arguments' => $args]);
    $res = $r['json']['result'] ?? [];
    $err = ($res['isError'] ?? false) === true || isset($r['json']['error']);
    $text = isset($r['json']['error'])
        ? (string) ($r['json']['error']['message'] ?? '')
        : implode('', array_map(fn ($c) => $c['text'] ?? '', $res['content'] ?? []));
    return ['error' => $err, 'text' => $text, 'sc' => $res['structuredContent'] ?? null];
}
function expect_scoped(string $what, string $tool, array $args, array $expectedRaw): void
{
    $c = vis_call($tool, $args);
    assert_true($what . ' succeeds', ! $c['error'] && is_array($c['sc']));
    assert_true($what . ' predicate', raw_wheres() === $expectedRaw, json_encode(raw_wheres()));
    if (raw_wheres() !== $expectedRaw) { echo '      got: ' . json_encode(raw_wheres()) . "\n"; }
}
function expect_denied(string $what, string $tool, array $args, string $needle): void
{
    $c = vis_call($tool, $args);
    assert_true($what . ' denied', $c['error'] && stripos($c['text'], $needle) !== false);
    if (! ($c['error'] && stripos($c['text'], $needle) !== false)) { echo '      got: ' . $c['text'] . "\n"; }
}

// -- sales documents: view_own, the assigned option, global view, no rights
vis_reset(['invoices' => ['view_own']]);
expect_scoped('invoices_list view_own', 'invoices_list', [], ['(tblinvoices.addedfrom = 7)']);
vis_reset(['invoices' => ['view_own']], ['allow_staff_view_invoices_assigned' => '1']);
expect_scoped('invoices_list view_own + assigned option', 'invoices_list', [], ['(tblinvoices.addedfrom = 7 OR tblinvoices.sale_agent = 7)']);
vis_reset(['invoices' => []], ['allow_staff_view_invoices_assigned' => '1']);
expect_scoped('invoices_list sale agent only (no view_own, option on)', 'invoices_list', [], ['(tblinvoices.sale_agent = 7)']);
vis_reset(['invoices' => ['view']]);
expect_scoped('invoices_list global view = no predicate', 'invoices_list', [], []);
vis_reset(['invoices' => []]);
expect_denied('invoices_list no rights', 'invoices_list', [], 'cannot read invoices');
vis_reset(['estimates' => ['view_own']], ['allow_staff_view_estimates_assigned' => '1']);
expect_scoped('estimates_list', 'estimates_list', [], ['(tblestimates.addedfrom = 7 OR tblestimates.sale_agent = 7)']);
vis_reset(['proposals' => ['view_own']], ['allow_staff_view_proposals_assigned' => '1']);
expect_scoped('proposals_list (assigned column)', 'proposals_list', [], ['(tblproposals.addedfrom = 7 OR tblproposals.assigned = 7)']);
vis_reset(['subscriptions' => ['view_own']]);
expect_scoped('subscriptions_list (created_from)', 'subscriptions_list', [], ['(tblsubscriptions.created_from = 7)']);
vis_reset(['contracts' => ['view_own']]);
expect_scoped('contracts_list', 'contracts_list', [], ['(tblcontracts.addedfrom = 7)']);
vis_reset(['credit_notes' => ['view_own']]);
expect_scoped('credit_notes_list', 'credit_notes_list', [], ['(tblcreditnotes.addedfrom = 7)']);

// -- single reads re-run the same predicate (count 0 => denied, 1 => ok)
vis_reset(['expenses' => ['view_own']]);
$ci->db->scopedCount['expenses'] = 0;
expect_denied('expenses_get outside scope', 'expenses_get', ['id' => 1], 'Expense 1 is not visible');
assert_true('expenses_get re-checked with the list predicate', in_array('(tblexpenses.addedfrom = 7)', raw_wheres(), true));
vis_reset(['expenses' => ['view_own']]);
$ci->db->scopedCount['expenses'] = 1;
$c = vis_call('expenses_get', ['id' => 1]);
assert_true('expenses_get inside scope succeeds', ! $c['error'] && (int) ($c['sc']['expense']['id'] ?? 0) === 1);

// -- assignment-scoped modules (gate widens to any staff member, predicate narrows)
vis_reset(['leads' => []]);
expect_scoped('leads_list without view', 'leads_list', [], ['(tblleads.assigned = 7 OR tblleads.addedfrom = 7 OR tblleads.is_public = 1)']);
vis_reset(['customers' => []]);
expect_scoped('customers_list without view', 'customers_list', [], ['(tblclients.userid IN (SELECT customer_id FROM tblcustomer_admins WHERE staff_id = 7))']);
expect_scoped('contacts_list without view', 'contacts_list', [], ['(tblcontacts.userid IN (SELECT customer_id FROM tblcustomer_admins WHERE staff_id = 7))']);
$ci->db->scopedCount['clients'] = 0;
expect_denied('customers_get not a customer admin', 'customers_get', ['id' => 1], 'Customer 1 is not visible');
vis_reset(['projects' => []]);
expect_scoped('projects_list without view', 'projects_list', [], ['(tblprojects.id IN (SELECT project_id FROM tblproject_members WHERE staff_id = 7))']);
$tasks4 = '(tbltasks.id IN (SELECT taskid FROM tbltask_assigned WHERE staffid = 7) OR tbltasks.id IN (SELECT taskid FROM tbltask_followers WHERE staffid = 7) OR (tbltasks.addedfrom = 7 AND tbltasks.is_added_from_contact = 0) OR tbltasks.is_public = 1)';
$tasks5 = str_replace(' OR tbltasks.is_public = 1)', ' OR (tbltasks.rel_type = "project" AND tbltasks.rel_id IN (SELECT project_id FROM tblproject_members WHERE staff_id = 7)) OR tbltasks.is_public = 1)', $tasks4);
vis_reset(['tasks' => []]);
expect_scoped('tasks_list without view (4 clauses)', 'tasks_list', [], [$tasks4]);
vis_reset(['tasks' => []], ['show_all_tasks_for_project_member' => '1']);
expect_scoped('tasks_list with project-member option (5 clauses)', 'tasks_list', [], [$tasks5]);
vis_reset(['tasks' => []]);
$ci->db->scopedCount['tasks'] = 1;
expect_denied('tasks_change_status: visible but neither edit, assignee nor creator', 'tasks_change_status', ['id' => 1, 'status' => 5], 'assignee or creator');

// -- tickets: department option on/off, admin bypass, writes gated like reads
vis_reset([], ['staff_access_only_assigned_departments' => '1']);
expect_scoped('tickets_list department option on', 'tickets_list', [], ['(tbltickets.department IN (SELECT departmentid FROM tblstaff_departments WHERE staffid = 7))']);
vis_reset([], ['staff_access_only_assigned_departments' => '0']);
expect_scoped('tickets_list department option off', 'tickets_list', [], []);
vis_reset(['__is_admin' => true], ['staff_access_only_assigned_departments' => '1']);
expect_scoped('tickets_list admin bypass', 'tickets_list', [], []);
vis_reset([], ['staff_access_only_assigned_departments' => '1']);
$ci->db->scopedCount['tickets'] = 0;
expect_denied('tickets_reply outside department', 'tickets_reply', ['id' => 1, 'message' => 'x'], 'Ticket 1 is not visible');
vis_reset([], ['staff_access_only_assigned_departments' => '1']);
$ci->db->scopedCount['tickets'] = 0;
expect_denied('tickets_change_status outside department', 'tickets_change_status', ['id' => 1, 'status' => 5], 'Ticket 1 is not visible');
vis_reset([], ['staff_access_only_assigned_departments' => '1']);
$ci->db->scopedCount['departments'] = 0;
expect_denied('tickets_create in a foreign department', 'tickets_create', ['fields' => ['subject' => 's', 'message' => 'm', 'department' => 5, 'email' => 'c@example.test']], 'Department 5 is not visible');
assert_true('tickets_create checked the department predicate', in_array('(tbldepartments.departmentid IN (SELECT departmentid FROM tblstaff_departments WHERE staffid = 7))', raw_wheres(), true));

// -- payments follow the invoices rule
vis_reset(['payments' => [], 'invoices' => []]);
expect_denied('payments_list with no invoice rights', 'payments_list', [], 'cannot read payments');
vis_reset(['invoices' => ['view_own']]);
expect_scoped('payments_list via invoices view_own', 'payments_list', [], ['(tblinvoicepaymentrecords.invoiceid IN (SELECT id FROM tblinvoices WHERE addedfrom = 7))']);
vis_reset(['invoices' => []], ['allow_staff_view_invoices_assigned' => '1']);
expect_scoped('payments_list via sale agent option', 'payments_list', [], ['(tblinvoicepaymentrecords.invoiceid IN (SELECT id FROM tblinvoices WHERE sale_agent = 7))']);
vis_reset(['invoices' => ['view_own']], ['allow_staff_view_invoices_assigned' => '1']);
expect_scoped('payments_list both', 'payments_list', [], ['(tblinvoicepaymentrecords.invoiceid IN (SELECT id FROM tblinvoices WHERE addedfrom = 7) OR tblinvoicepaymentrecords.invoiceid IN (SELECT id FROM tblinvoices WHERE sale_agent = 7))']);
vis_reset(['payments' => ['view']]);
expect_scoped('payments_list global view', 'payments_list', [], []);

// -- staff directory: names for everyone, the directory only with view on staff
vis_reset(['staff' => []]);
$ci->db->projectSelect = true;
$c = vis_call('staff_list', []);
assert_true('staff_list without view is names-only', ! $c['error'] && ($c['sc']['fields'] ?? '') === 'names' && array_keys($c['sc']['data'][0] ?? []) === ['staffid', 'firstname', 'lastname', 'active']);
$c = vis_call('staff_get', ['id' => 7]);
assert_true('staff_get without view is names-only (own record too)', ! $c['error'] && ($c['sc']['fields'] ?? '') === 'names' && ! array_key_exists('email', $c['sc']['staff'] ?? ['email' => 1]));
vis_reset(['staff' => ['view']]);
$ci->db->projectSelect = true;
$c = vis_call('staff_list', []);
assert_true('staff_list with view is the directory', ! $c['error'] && ($c['sc']['fields'] ?? '') === 'directory' && array_key_exists('email', $c['sc']['data'][0] ?? []));

// -- notes follow the parent's rule; unknown features fail closed
vis_reset(['invoices' => ['view_own']]);
$ci->db->scopedCount['invoices'] = 0;
expect_denied('notes_list on a hidden invoice', 'notes_list', ['rel_type' => 'invoice', 'rel_id' => 1], 'Invoice 1 is not visible');
try {
    \PerfexMcp\Auth\Visibility::scope('nonexistent');
    assert_true('Visibility::scope(nonexistent) throws', false);
} catch (\PerfexMcp\Auth\PermissionDenied $e) {
    assert_true('Visibility::scope(nonexistent) throws PermissionDenied', true);
}

// -- fail-closed sweep: every advertised list tool must be classified
$classify = [
    'denied' => ['invoices_list', 'estimates_list', 'payments_list', 'expenses_list', 'expense_categories_list', 'proposals_list', 'contracts_list', 'credit_notes_list', 'subscriptions_list', 'items_list', 'knowledge_base_list', 'knowledge_base_groups_list'],
    'scoped' => ['leads_list', 'customers_list', 'contacts_list', 'projects_list', 'tasks_list', 'tickets_list'],
    'open'   => ['staff_list', 'announcements_list', 'custom_fields_list', 'contract_types_list'],
];
$listed = mcp_call('tools/list', []);
foreach ($listed['json']['result']['tools'] ?? [] as $tool) {
    if (! str_ends_with($tool['name'], '_list') || ! empty($tool['inputSchema']['required'])) { continue; }
    $kind = null;
    foreach ($classify as $k => $names_) { if (in_array($tool['name'], $names_, true)) { $kind = $k; } }
    if ($kind === null) { assert_true('unclassified list tool ' . $tool['name'] . ' - add it to the Visibility table and this map', false); continue; }
    vis_reset([], ['staff_access_only_assigned_departments' => '1']);
    $c = vis_call($tool['name'], []);
    $ok = match ($kind) {
        'denied' => $c['error'] && stripos($c['text'], 'cannot read') !== false,
        'scoped' => ! $c['error'] && raw_wheres() !== [],
        'open'   => ! $c['error'] && raw_wheres() === [],
    };
    assert_true('sweep: ' . $tool['name'] . ' is ' . $kind . ' for a staff member with no capabilities', $ok);
}
vis_reset(null, [], 1);

// 6) session persisted in the store
echo "\n=== session store ===\n";
assert_true('session row persisted in DbSessionStore', ! empty($ci->db->sessions));

// 7) modern era (2026-07-28): no initialize, no session header, server/discover.
// SDK 0.8.0 serves this from the same endpoint alongside the handshake era, so
// the one thing worth proving is that a modern request is not rejected by our
// custom middleware list -- leaving ProtocolVersionMiddleware in it would fail
// this with "unsupported protocol version".
echo "\n=== modern era (2026-07-28) ===\n";
$sessionId       = null;
$protocolVersion = '2026-07-28';

$r = mcp_call('server/discover', []);
show('server/discover', $r);
assert_true('server/discover answered 200', $r['status'] === 200);
assert_true(
    'server/discover was not rejected as unsupported protocol version',
    ! isset($r['json']['error']['message'])
        || stripos((string) $r['json']['error']['message'], 'protocol version') === false
);

$r = mcp_call('tools/call', ['name' => 'customers_get', 'arguments' => ['id' => 1]]);
show('modern tools/call customers_get', $r);
assert_true('modern era tools/call succeeded', ($r['json']['result']['isError'] ?? true) === false);

echo "\n" . ($assertFail === 0 ? "ALL ASSERTIONS PASSED\n" : "{$assertFail} ASSERTION(S) FAILED\n");
exit($assertFail === 0 ? 0 : 1);

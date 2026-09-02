<?php

/*
 * Minimal Perfex/CodeIgniter environment stubs for LOCAL testing of the MCP
 * protocol layer without a full CRM install. NOT shipped. Provides just enough
 * of the global helpers + a fake DB (with a working in-memory session table and
 * canned read results) to drive the real mcp/sdk end to end.
 */

defined('BASEPATH') or define('BASEPATH', __DIR__);
defined('FCPATH') or define('FCPATH', sys_get_temp_dir() . '/perfex-stub-fcpath/');

// ---- Fake DB query builder -------------------------------------------------

class FakeResult
{
    public function __construct(private array $rows) {}

    public function row()
    {
        return isset($this->rows[0]) ? (object) $this->rows[0] : null;
    }

    public function result()
    {
        return array_map(fn ($r) => (object) $r, $this->rows);
    }

    public function result_array(): array
    {
        return $this->rows;
    }

    public function row_array(): ?array
    {
        return $this->rows[0] ?? null;
    }
}

class FakeDb
{
    public array $sessions = [];          // in-memory sessions table
    public array $canned   = [];          // table => rows for reads
    public int $rateCount  = 0;           // count_all_results for audit (rate limit)
    // Visibility-parity hooks: every where() call is logged ([key, value,
    // escape]); a raw (escape === false) predicate marks the query "scoped",
    // and count_all_results() then answers from scopedCount[table] when set,
    // so a test can make assertVisible() see 0 or 1 rows. projectSelect
    // (opt-in) trims canned rows to the select()ed columns for lite reads.
    public array $whereLog     = [];
    public array $scopedCount  = [];
    public bool $projectSelect = false;
    private bool $rawWhere     = false;
    private ?string $selectCols = null;
    private array $where    = [];
    private ?string $from   = null;

    public function reset(): void
    {
        $this->where      = [];
        $this->from       = null;
        $this->rawWhere   = false;
        $this->selectCols = null;
    }

    public function select($x)            { $this->selectCols = (string) $x; return $this; }
    public function select_sum($x)        { return $this; }
    public function from($t)              { $this->from = $this->strip($t); return $this; }
    public function join($a, $b, $c = '') { return $this; }
    public function order_by($a, $b = '') { return $this; }
    public function group_start()         { return $this; }
    public function group_end()           { return $this; }
    public function like($a, $b)          { return $this; }
    public function or_like($a, $b)       { return $this; }
    public function limit($a, $b = 0)     { return $this; }

    public function where($k, $v = null, $escape = null)
    {
        $this->whereLog[] = [$k, $v, $escape];
        if ($escape === false) {
            $this->rawWhere = true;
        }
        if (is_array($k)) {
            $this->where = array_merge($this->where, $k);
        } else {
            $this->where[$k] = $v;
        }

        return $this;
    }

    public function or_where($k, $v = null) { return $this->where($k, $v); }

    public function get($table = null)
    {
        $t = $table ? $this->strip($table) : $this->from;

        if ($t === 'mcp_connector_sessions') {
            $id   = $this->where['id'] ?? null;
            $rows = [];
            if ($id !== null && isset($this->sessions[$id])) {
                $row = $this->sessions[$id];
                if (($row['expires_at'] ?? 0) >= time()) {
                    $rows[] = $row;
                }
            }
            $this->reset();

            return new FakeResult($rows);
        }

        $rows = $this->canned[$t] ?? [];
        if ($this->projectSelect && $this->selectCols !== null && preg_match('/^[a-z0-9_, ]+$/i', $this->selectCols)) {
            $cols = array_flip(array_map('trim', explode(',', $this->selectCols)));
            $rows = array_map(fn ($r) => array_intersect_key($r, $cols), $rows);
        }
        $this->reset();

        return new FakeResult($rows);
    }

    public function count_all_results($table = null, $reset = true)
    {
        $t = $table ? $this->strip($table) : $this->from;

        if ($t === 'mcp_connector_sessions') {
            $id  = $this->where['id'] ?? null;
            $cnt = ($id !== null && isset($this->sessions[$id])) ? 1 : 0;
            if ($reset) { $this->reset(); }

            return $cnt;
        }
        if ($t === 'mcp_connector_audit') {
            if ($reset) { $this->reset(); }

            return $this->rateCount;
        }

        $cnt = ($this->rawWhere && array_key_exists($t, $this->scopedCount))
            ? $this->scopedCount[$t]
            : count($this->canned[$t] ?? []);
        if ($reset) { $this->reset(); }

        return $cnt;
    }

    public function insert($table, $data)
    {
        $t = $this->strip($table);
        if ($t === 'mcp_connector_sessions') {
            $this->sessions[$data['id']] = $data;
        }
        $this->reset();

        return true;
    }

    public function update($table, $data)
    {
        $t = $this->strip($table);
        if ($t === 'mcp_connector_sessions') {
            $id = $this->where['id'] ?? null;
            if ($id !== null) {
                $this->sessions[$id] = array_merge($this->sessions[$id] ?? ['id' => $id], $data);
            }
        }
        $this->reset();

        return true;
    }

    public function delete($table)
    {
        $t = $this->strip($table);
        if ($t === 'mcp_connector_sessions') {
            $id = $this->where['id'] ?? null;
            if ($id !== null) { unset($this->sessions[$id]); }
        }
        $this->reset();

        return true;
    }

    public string $char_set = 'utf8mb4';

    private function strip($t): string
    {
        return preg_replace('/^tbl/', '', (string) $t);
    }
}

// ---- Fake input ------------------------------------------------------------

class FakeInput
{
    public array $headers = [];

    public function get_request_header($name, $xss = false)
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) { return $v; }
        }

        return null;
    }

    public function ip_address() { return '127.0.0.1'; }
    public function method($upper = false) { return $_SERVER['REQUEST_METHOD'] ?? 'GET'; }
    public function post($k = null, $xss = false) { return $k === null ? $_POST : ($_POST[$k] ?? null); }
    public function server($k) { return $_SERVER[$k] ?? null; }
}

// ---- Fake loader + models --------------------------------------------------

class FakeClientsModel
{
    public array $canned = [];

    public function get($id = '', $where = [])
    {
        return $this->canned[$id] ?? null;
    }

    public function get_contacts($id = '', $where = ['active' => 1], $whereIn = [])
    {
        return [];
    }
}

class FakeLoad
{
    private $ci;
    public function __construct($ci) { $this->ci = $ci; }
    public function model($m) { /* models pre-attached on CI */ }
    public function library($l) {}
    public function view($v, $d = []) {}
    public function helper($h) {}
    public function config($c) {}
}

class FakeCI
{
    public $db;
    public $input;
    public $load;
    public $clients_model;

    public function __construct()
    {
        $this->db            = new FakeDb();
        $this->input         = new FakeInput();
        $this->load          = new FakeLoad($this);
        $this->clients_model = new FakeClientsModel();
    }
}

$GLOBALS['__CI'] = new FakeCI();

// ---- Global helper stubs ---------------------------------------------------

function &get_instance()            { return $GLOBALS['__CI']; }
function db_prefix()                { return 'tbl'; }

// Permission simulation. __perms === null grants everything (the default, so
// the pre-existing assertions run as an admin). Otherwise it is
// feature => [capabilities], with '__is_admin' for the admin flag; callable
// capabilities (is_staff_member) are always true for a stubbed staff row.
$GLOBALS['__perms']    = null;
$GLOBALS['__options']  = [];
$GLOBALS['__staff_id'] = 1;

function get_option($k)
{
    $defaults = [
        'mcp_connector_enabled'                  => 1,
        'mcp_connector_enable_write_tools'       => 1,
        'mcp_connector_enable_destructive_tools' => 0,
        'mcp_connector_default_rate_limit'       => 120,
        'mcp_connector_session_ttl'              => 3600,
    ];

    return $GLOBALS['__options'][$k] ?? ($defaults[$k] ?? '');
}
function update_option($k, $v)      { return true; }
function log_message($level, $msg)  { /* silent */ }
function staff_can($cap, $feature = null, $staff = '')
{
    $perms = $GLOBALS['__perms'];
    if ($perms === null) { return true; }
    if ($feature === null) {
        if ($cap === 'is_staff_member') { return true; }
        if ($cap === 'is_admin') { return (bool) ($perms['__is_admin'] ?? false); }
        return false;
    }
    if (! empty($perms['__is_admin'])) { return true; }
    return in_array($cap, $perms[$feature] ?? [], true);
}
function is_admin($staff = '')      { $p = $GLOBALS['__perms']; return $p === null ? true : (bool) ($p['__is_admin'] ?? false); }
function get_staff_user_id()        { return $GLOBALS['__staff_id']; }

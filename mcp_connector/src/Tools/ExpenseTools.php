<?php

namespace PerfexMcp\Tools;

use Mcp\Exception\ToolCallException;
use PerfexMcp\Auth\ReadScope;

/**
 * Expense tools: the outgoing side of the books, plus receipt attachments.
 *
 * Perfex's Expenses module has several traps this class exists to absorb so
 * the MCP caller never can:
 *  - Expenses_model::add() sets billable/create_invoice_billable/
 *    send_invoice_to_customer to 1 when the KEY merely exists ('billable' => 0
 *    still becomes 1). Flags are therefore included only when true, and the
 *    invoice-automation pair is not exposed at all (send_invoice_to_customer
 *    EMAILS the customer as a checkbox side effect).
 *  - Expenses_model::get() INNER JOINs the category, so a dangling category id
 *    makes the row invisible in the whole admin UI - and its SELECT * aliases
 *    the CATEGORY's name/description over the expense row. Reads here go to
 *    the table directly and categories are validated on every write.
 *  - Expenses_model::update() is form-shaped and resets absent fields;
 *    updates here write columns directly (the InvoiceTools idiom).
 *  - to_sql_date() parses the install's DISPLAY date format, so create
 *    validates ISO and hands the model _d($iso) (display form) to round-trip
 *    on any configured dateformat; update writes validated ISO straight to
 *    the DATE column.
 *
 * Never writable through this class: invoiceid, addedfrom, dateadded,
 * recurring_from, total_cycles, last_recurring_date, custom_recurring, tax,
 * tax2, create_invoice_billable, send_invoice_to_customer.
 */
final class ExpenseTools extends AbstractTools
{
    /**
     * Decoded receipt cap. The FPM post_max_size floor this module documents
     * is 8M; 5 MiB decoded is ~6.9 MB base64, leaving JSON-RPC envelope room.
     */
    private const MAX_ATTACHMENT_BYTES = 5242880;

    /** Base64 length ceiling matching MAX_ATTACHMENT_BYTES (4/3 + padding). */
    private const MAX_B64_LEN = 7200000;

    /**
     * Receipt types. Detected from the DECODED BYTES via finfo - the caller's
     * filename extension is checked against the detection, never trusted.
     * No gif (polyglot vector, receipts are never gifs), no heic (server-side
     * detection varies and Perfex cannot preview it - convert first).
     */
    private const ALLOWED_MIME = [
        'application/pdf' => ['ext' => 'pdf', 'alt' => []],
        'image/png'       => ['ext' => 'png', 'alt' => []],
        'image/jpeg'      => ['ext' => 'jpg', 'alt' => ['jpeg']],
        'image/webp'      => ['ext' => 'webp', 'alt' => []],
    ];

    private const EXPENSE_COLUMNS = 'id, category, currency, amount, tax, tax2, reference_no, expense_name, date, '
        . 'billable, invoiceid, clientid, project_id, paymentmode, recurring, repeat_every, recurring_type, '
        . 'cycles, total_cycles, last_recurring_date, recurring_from, dateadded, addedfrom';

    private const RECURRING_TYPES = ['day', 'week', 'month', 'year'];

    /**
     * The admin form's fixed recurrence dropdown. Anything else is only
     * representable as a "custom" recurrence; storing it with
     * custom_recurring=0 makes the edit form show a blank select, and a
     * routine admin save would then silently switch the series off.
     */
    private const STANDARD_CYCLES = ['1-week', '2-week', '1-month', '2-month', '3-month', '6-month', '1-year'];

    private function isStandardCycle(int $repeatEvery, string $recurringType): bool
    {
        return in_array($repeatEvery . '-' . $recurringType, self::STANDARD_CYCLES, true);
    }

    // ------------------------------------------------------------------ reads

    /**
     * Amounts are per-currency; never sum rows across currencies. Every row is
     * a real expense occurrence, including a recurring parent; generated
     * occurrences reference it via recurring_from and are not duplicates.
     */
    public function expenses_list(
        ?int $category = null,
        $currency = null,
        ?string $date_from = null,
        ?string $date_to = null,
        ?int $year = null,
        ?string $search = null,
        bool $recurring_only = false,
        ?int $recurring_from = null,
        int $limit = 25,
        int $offset = 0
    ): array {
        $args = compact('category', 'currency', 'date_from', 'date_to', 'year', 'search', 'recurring_only', 'recurring_from', 'limit', 'offset');

        return $this->guard('expenses_list', ['read', 'expenses'], $args, function (ReadScope $scope) use (
            $category, $currency, $date_from, $date_to, $year, $search, $recurring_only, $recurring_from, $limit, $offset
        ) {
            if ($year !== null && ($date_from !== null || $date_to !== null)) {
                $this->fail('Use either year or a date range (date_from/date_to), not both.');
            }
            foreach (['date_from' => $date_from, 'date_to' => $date_to] as $name => $value) {
                if ($value !== null) {
                    $this->isoDate($value, $name);
                }
            }
            $currencyId = ($currency === null || $currency === '') ? null : $this->normalizeCurrency($currency);

            $result = $this->paginate(
                $scope,
                db_prefix() . 'expenses',
                self::EXPENSE_COLUMNS,
                function ($db) use ($category, $currencyId, $date_from, $date_to, $year, $search, $recurring_only, $recurring_from) {
                    if ($category !== null) {
                        $db->where('category', $category);
                    }
                    if ($currencyId !== null) {
                        $db->where('currency', $currencyId);
                    }
                    if ($date_from !== null) {
                        $db->where('date >=', $date_from);
                    }
                    if ($date_to !== null) {
                        $db->where('date <=', $date_to);
                    }
                    if ($year !== null) {
                        $db->where('YEAR(date)', $year);
                    }
                    if ($recurring_only) {
                        $db->where('recurring', 1);
                    }
                    if ($recurring_from !== null) {
                        $db->where('recurring_from', $recurring_from);
                    }
                    if ($search !== null && $search !== '') {
                        $db->group_start()->like('reference_no', $search)->or_like('expense_name', $search)->group_end();
                    }
                },
                // Same-date rows are the norm for monthly recurring bills; the
                // id tiebreak keeps LIMIT/OFFSET pages stable. The direction
                // is embedded per field and the direction argument left empty:
                // CI3's order_by() only parses per-field ASC/DESC when the
                // direction argument is '' - a non-empty direction makes it
                // treat 'date desc' as column-plus-alias and emit invalid SQL.
                'date desc, id desc',
                '',
                $limit,
                $offset
            );

            $result['data'] = $this->decorateLookup($result['data'], 'expenses_categories', 'category', 'name', 'category_name');
            $result['data'] = $this->decorateLookup($result['data'], 'currencies', 'currency', 'name', 'currency_code');

            return $result;
        });
    }

    public function expenses_get(int $id): array
    {
        return $this->guard('expenses_get', ['read', 'expenses'], compact('id'), function (ReadScope $scope) use ($id) {
            // Deliberately NOT Expenses_model::get(): its INNER JOIN hides
            // rows with a dangling category, and SELECT * aliases the
            // category's name/description over the expense's own columns.
            $expense = $this->requireVisibleRow($scope, 'expenses', $id, 'Expense');

            $this->CI->db->where('id', (int) $expense['category']);
            $category = $this->CI->db->get(db_prefix() . 'expenses_categories')->row_array();
            $expense['category'] = $category
                ? ['id' => (int) $category['id'], 'name' => $category['name'], 'description' => $category['description']]
                : null;
            if ($category === null) {
                $expense['warning'] = 'Category no longer exists; this expense is INVISIBLE in the Perfex UI until the category is fixed via expenses_update.';
            }

            $this->CI->db->where('id', (int) $expense['currency']);
            $cur = $this->CI->db->get(db_prefix() . 'currencies')->row_array();
            $expense['currency'] = $cur
                ? ['id' => (int) $cur['id'], 'name' => $cur['name'], 'symbol' => $cur['symbol']]
                : null;

            if ((int) $expense['project_id'] > 0) {
                $this->CI->db->where('id', (int) $expense['project_id']);
                $project = $this->CI->db->get(db_prefix() . 'projects')->row_array();
                if ($project) {
                    $expense['project'] = ['id' => (int) $project['id'], 'name' => $project['name']];
                }
            }

            // Clean text for the caller; nl2br() happens again on write.
            if (isset($expense['note'])) {
                $expense['note'] = $this->decodeBreaks($expense['note']);
            }

            $expense['attachments'] = $this->attachmentRows($id);

            if ((int) $expense['recurring'] === 1) {
                $this->CI->db->where('recurring_from', $id);
                $this->CI->db->from(db_prefix() . 'expenses');
                $expense['children_count'] = $this->CI->db->count_all_results();
            }

            return ['expense' => $expense];
        });
    }

    public function expense_categories_list(?string $search = null, int $limit = 25, int $offset = 0): array
    {
        return $this->guard('expense_categories_list', ['read', 'expense_categories'], compact('search', 'limit', 'offset'), function (ReadScope $scope) use ($search, $limit, $offset) {
            return $this->paginate(
                $scope,
                db_prefix() . 'expenses_categories',
                'id, name, description',
                function ($db) use ($search) {
                    if ($search !== null && $search !== '') {
                        $db->like('name', $search);
                    }
                },
                'name',
                'asc',
                $limit,
                $offset
            );
        });
    }

    // ----------------------------------------------------------------- writes

    public function expenses_create(array $fields): array
    {
        return $this->guard('expenses_create', ['create', 'expenses'], compact('fields'), function () use ($fields) {
            $this->requireFields($fields, ['category', 'amount', 'date']);

            $category = (int) $fields['category'];
            $this->requireCategory($category);

            $amount = (float) $fields['amount'];
            if ($amount <= 0) {
                $this->fail('amount must be greater than 0 (net amount in the expense currency).');
            }

            $iso        = $this->isoDate((string) $fields['date'], 'date');
            $currencyId = $this->normalizeCurrency($fields['currency'] ?? null, true);

            $customerId = isset($fields['customer_id']) ? (int) $fields['customer_id'] : 0;
            $billable   = ! empty($fields['billable']);
            if ($billable && $customerId <= 0) {
                $this->fail('billable requires customer_id: an expense without a customer can never be billed.');
            }

            // Expenses_model::add() reads date and note unconditionally and
            // MySQL requires clientid/currency/category/amount, so every one
            // of these keys is always present.
            $data = [
                'category' => $category,
                'currency' => $currencyId,
                'amount'   => $amount,
                // The model runs to_sql_date(), which parses the install's
                // DISPLAY format - hand it the display form of our validated
                // ISO date so the conversion round-trips on any dateformat.
                'date'     => _d($iso),
                'note'     => isset($fields['note']) ? (string) $fields['note'] : '',
                'clientid' => $customerId,
            ];

            foreach (['expense_name', 'reference_no'] as $optional) {
                if (isset($fields[$optional]) && $fields[$optional] !== '') {
                    $data[$optional] = (string) $fields[$optional];
                }
            }
            if (isset($fields['project_id'])) {
                $data['project_id'] = (int) $fields['project_id'];
            }
            if (isset($fields['payment_mode']) && $fields['payment_mode'] !== '') {
                $data['paymentmode'] = $this->requirePaymentModeId((string) $fields['payment_mode']);
            }

            // CHECKBOX PRESENCE SEMANTICS: the model turns mere key existence
            // into 1 ('billable' => 0 still becomes billable). The key is
            // therefore included only when the flag is actually true.
            $data = $this->setCheckboxFlag($data, 'billable', $billable);

            $repeatEvery   = isset($fields['repeat_every']) ? (int) $fields['repeat_every'] : null;
            $recurringType = isset($fields['recurring_type']) ? (string) $fields['recurring_type'] : null;
            $cycles        = isset($fields['cycles']) ? (int) $fields['cycles'] : null;
            if ($repeatEvery !== null || $recurringType !== null) {
                $this->validateRecurringPair($repeatEvery, $recurringType, 1);
                if ($this->isStandardCycle($repeatEvery, $recurringType)) {
                    // The model's POST encoding: composite "N-type" both flips
                    // recurring=1 and carries the schedule.
                    $data['repeat_every'] = $repeatEvery . '-' . $recurringType;
                } else {
                    // Outside the form's fixed dropdown -> the model's custom
                    // path, so the admin edit form round-trips the schedule.
                    $data['repeat_every']        = 'custom';
                    $data['repeat_every_custom'] = $repeatEvery;
                    $data['repeat_type_custom']  = $recurringType;
                }
                if ($cycles !== null) {
                    if ($cycles < 0) {
                        $this->fail('cycles must be 0 (no limit) or a positive count.');
                    }
                    $data['cycles'] = $cycles;
                }
            } elseif ($cycles !== null) {
                $this->fail('cycles only applies together with repeat_every and recurring_type.');
            }

            $this->CI->load->model('expenses_model');
            $id = $this->CI->expenses_model->add($data);
            if (! $id) {
                $this->fail('Failed to create expense.');
            }

            return ['created' => true, 'id' => (int) $id];
        });
    }

    public function expenses_update(int $id, array $fields): array
    {
        return $this->guard('expenses_update', ['edit', 'expenses'], compact('id', 'fields'), function () use ($id, $fields) {
            $allowed = ['category', 'amount', 'date', 'currency', 'expense_name', 'note', 'reference_no',
                'payment_mode', 'customer_id', 'project_id', 'billable', 'repeat_every', 'recurring_type', 'cycles'];
            $data = $this->pickAllowed($fields, $allowed);
            if ($data === []) {
                $this->fail('No updatable fields provided (allowed: ' . implode(', ', $allowed) . ').');
            }

            // Direct read, not the model: dangling-category rows must remain
            // reachable here - this tool is how they get repaired.
            $expense = $this->requireRow('expenses', $id, 'Expense');

            if ($expense['invoiceid'] !== null) {
                foreach (['amount', 'currency', 'billable', 'customer_id', 'category'] as $frozen) {
                    if (array_key_exists($frozen, $data)) {
                        $this->fail('Expense ' . $id . ' is billed on invoice ' . (int) $expense['invoiceid']
                            . '; ' . $frozen . ' cannot change. Unlink or delete the invoice first.');
                    }
                }
            }

            if (array_key_exists('category', $data)) {
                $data['category'] = (int) $data['category'];
                $this->requireCategory($data['category']);
            }
            if (array_key_exists('amount', $data)) {
                $data['amount'] = (float) $data['amount'];
                if ($data['amount'] <= 0) {
                    $this->fail('amount must be greater than 0.');
                }
            }
            if (array_key_exists('date', $data)) {
                // Direct column write: the validated ISO string IS the SQL
                // date. No to_sql_date, no _d - those are for the model path.
                $data['date'] = $this->isoDate((string) $data['date'], 'date');
            }
            if (array_key_exists('currency', $data)) {
                $data['currency'] = $this->normalizeCurrency($data['currency']);
            }
            if (array_key_exists('payment_mode', $data)) {
                $data['paymentmode'] = $this->requirePaymentModeId((string) $data['payment_mode']);
                unset($data['payment_mode']);
            }
            if (array_key_exists('customer_id', $data)) {
                $data['clientid'] = (int) $data['customer_id'];
                unset($data['customer_id']);
                // Detaching the customer from a billable expense would create
                // the billable=1/clientid=0 state that create refuses.
                $billableAfter = array_key_exists('billable', $data)
                    ? (int) ! empty($data['billable'])
                    : (int) $expense['billable'];
                if ($data['clientid'] <= 0 && $billableAfter === 1) {
                    $this->fail('This expense is billable; set billable=false in the same call before detaching the customer.');
                }
            }
            if (array_key_exists('project_id', $data)) {
                $data['project_id'] = (int) $data['project_id'];
            }
            if (array_key_exists('note', $data)) {
                // Encoded exactly once, matching what the model does on create.
                $data['note'] = $this->encodeBreaks((string) $data['note']);
            }
            if (array_key_exists('billable', $data)) {
                // Unlike create (presence semantics inside the model), this is
                // a direct column write, so a literal 0/1 is stored. Do not
                // "unify" the two paths - they feed different sinks.
                $data['billable'] = empty($data['billable']) ? 0 : 1;
                $clientAfter      = array_key_exists('clientid', $data) ? (int) $data['clientid'] : (int) $expense['clientid'];
                if ($data['billable'] === 1 && $clientAfter <= 0) {
                    $this->fail('billable requires a customer; set customer_id in the same call or on the expense first.');
                }
            }

            $repeatTouched = array_key_exists('repeat_every', $data) || array_key_exists('recurring_type', $data);
            if ($repeatTouched) {
                $repeatEvery = array_key_exists('repeat_every', $data) ? (int) $data['repeat_every'] : null;
                if ($repeatEvery === 0) {
                    // Off-switch: clear ALL cycle state. A stale
                    // total_cycles >= cycles would make the cron treat any
                    // future re-enabled series as already complete.
                    $data = array_merge($data, [
                        'recurring'           => 0,
                        'repeat_every'        => 0,
                        'recurring_type'      => null,
                        'custom_recurring'    => 0,
                        'cycles'              => 0,
                        'total_cycles'        => 0,
                        'last_recurring_date' => null,
                    ]);
                } else {
                    $recurringType = array_key_exists('recurring_type', $data) ? (string) $data['recurring_type'] : null;
                    $this->validateRecurringPair($repeatEvery, $recurringType, 1);
                    $data['recurring']        = 1;
                    $data['repeat_every']     = $repeatEvery;
                    $data['recurring_type']   = $recurringType;
                    $data['custom_recurring'] = $this->isStandardCycle($repeatEvery, $recurringType) ? 0 : 1;
                    if (array_key_exists('cycles', $data)) {
                        $data['cycles'] = max(0, (int) $data['cycles']);
                    }
                }
            } elseif (array_key_exists('cycles', $data)) {
                if ((int) $expense['recurring'] !== 1) {
                    $this->fail('cycles only applies to a recurring expense; set repeat_every and recurring_type first.');
                }
                $data['cycles'] = max(0, (int) $data['cycles']);
            }

            $this->CI->db->where('id', $id);
            $ok = $this->CI->db->update(db_prefix() . 'expenses', $data);

            return ['updated' => (bool) $ok, 'id' => $id];
        });
    }

    public function expenses_attach_receipt(int $id, string $filename, string $content_base64, bool $replace = false): array
    {
        // The payload NEVER enters the guard args: AuditLogger json_encodes
        // the args BEFORE any truncation, so a base64 body would balloon every
        // audit row. Metadata only - and the key deliberately avoids "base64",
        // which AuditLogger::redact() now blanks as a backstop.
        $auditArgs = ['id' => $id, 'filename' => $filename, 'payload_chars' => strlen($content_base64), 'replace' => $replace];

        return $this->guard('expenses_attach_receipt', ['edit', 'expenses'], $auditArgs, function () use ($id, $filename, $content_base64, $replace) {
            if (! extension_loaded('fileinfo')) {
                $this->fail('Server is missing the fileinfo PHP extension; attachment uploads are disabled.');
            }

            $this->CI->db->where('id', $id);
            $expense = $this->CI->db->get(db_prefix() . 'expenses')->row_array();
            if (! $expense) {
                throw new ToolCallException('Expense ' . $id . ' not found.');
            }

            $b64 = $content_base64;
            if (preg_match('#^data:[^;,]*;base64,#i', $b64)) {
                $b64 = (string) preg_replace('#^data:[^;,]*;base64,#i', '', $b64);
            } elseif (str_starts_with($b64, 'data:')) {
                $this->fail('Only plain base64 or a data:*;base64, payload is accepted.');
            }
            if (strlen($b64) > self::MAX_B64_LEN) {
                $this->fail('Encoded payload exceeds the ' . self::MAX_B64_LEN . ' character limit.');
            }
            $bytes = base64_decode($b64, true);
            if ($bytes === false) {
                $this->fail('content_base64 is not valid base64.');
            }
            $size = strlen($bytes);
            if ($size < 1 || $size > self::MAX_ATTACHMENT_BYTES) {
                $this->fail('Decoded file is ' . $size . ' bytes; allowed range is 1..' . self::MAX_ATTACHMENT_BYTES
                    . ' (5 MiB cap - sized to the documented post_max_size floor).');
            }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'unknown';
            if (! isset(self::ALLOWED_MIME[$mime])) {
                $this->fail('Detected type "' . $mime . '" is not allowed; accepted: pdf, png, jpg, webp.');
            }

            $name = $this->sanitizeReceiptFilename($filename, $mime);

            $existing = $this->attachmentRows($id, false);
            $replaced = false;
            if ($existing !== [] && ! $replace) {
                $this->fail('Expense ' . $id . ' already has a receipt (' . $existing[0]['file_name']
                    . '). Pass replace=true to replace it.');
            }
            if ($existing !== [] && $replace) {
                // Native whole-dir + all-rows removal, then a fresh directory:
                // core-parity cleanup that also sweeps any orphaned files.
                $this->CI->load->model('expenses_model');
                $this->CI->expenses_model->delete_expense_attachment($id);
                $replaced = true;
            }

            $dir = $this->expenseUploadDir($id);
            if (function_exists('unique_filename')) {
                $name = unique_filename($dir, $name);
            } elseif (is_file($dir . $name)) {
                $name = preg_replace('/(\.[a-z0-9]+)$/i', '-1$1', $name);
            }

            // Containment, verified for real: the resolved per-expense dir
            // must live under the resolved expenses root, and the (already
            // sanitized) name must carry no separator - a second barrier that
            // still holds if the sanitizer is ever loosened.
            $rootReal = realpath(function_exists('get_upload_path_by_type') ? get_upload_path_by_type('expense') : FCPATH . 'uploads/expenses/');
            $dirReal  = realpath($dir);
            if ($rootReal === false || $dirReal === false
                || ! str_starts_with($dirReal . DIRECTORY_SEPARATOR, $rootReal . DIRECTORY_SEPARATOR)
                || strpbrk($name, '/\\') !== false || str_contains($name, '..')) {
                $this->fail('Refusing to write outside the expense upload directory.');
            }
            $path = $dirReal . DIRECTORY_SEPARATOR . $name;

            $written = file_put_contents($path, $bytes, LOCK_EX);
            if ($written !== $size) {
                @unlink($path);
                $this->fail('Failed to store the file (short write); nothing was saved.');
            }

            $this->CI->load->model('misc_model');
            try {
                $this->CI->misc_model->add_attachment_to_database($id, 'expense', [[
                    'file_name' => $name,
                    'filetype'  => $mime,
                ]]);
            } catch (\Throwable $e) {
                @unlink($path);
                log_message('error', 'expenses_attach_receipt DB insert failed: ' . $e->getMessage());
                $this->fail('Database insert for the attachment failed; the stored file was rolled back.');
            }

            // With db_debug off CI3 reports a failed insert by RETURN VALUE,
            // not by throwing - so the re-queried row, not the absence of an
            // exception, is the proof the attachment exists.
            $this->CI->db->where(['rel_id' => $id, 'rel_type' => 'expense', 'file_name' => $name]);
            $this->CI->db->order_by('id', 'desc');
            $row = $this->CI->db->get(db_prefix() . 'files')->row_array();
            if (! $row) {
                @unlink($path);
                log_message('error', 'expenses_attach_receipt: tblfiles row missing after insert for expense ' . $id);
                $this->fail('Database insert for the attachment failed; the stored file was rolled back.');
            }

            return [
                'attached'   => true,
                'id'         => $id,
                'file_id'    => (int) $row['id'],
                'file_name'  => $name,
                'filetype'   => $mime,
                'size_bytes' => $size,
                'replaced'   => $replaced,
            ];
        });
    }

    public function expense_categories_create(array $fields): array
    {
        return $this->guard('expense_categories_create', ['create', 'expenses'], compact('fields'), function () use ($fields) {
            $this->requireFields($fields, ['name']);
            $name = trim((string) $fields['name']);

            // Idempotent bootstrap: "ensure category X exists" must be one
            // call, so an existing name returns its id instead of an error the
            // caller would have to parse ids out of.
            $this->CI->db->where('LOWER(name)', mb_strtolower($name));
            $existing = $this->CI->db->get(db_prefix() . 'expenses_categories')->row_array();
            if ($existing) {
                return ['created' => false, 'existing' => true, 'id' => (int) $existing['id']];
            }

            $this->CI->load->model('expenses_model');
            $id = $this->CI->expenses_model->add_category([
                'name'        => $name,
                'description' => isset($fields['description']) ? (string) $fields['description'] : '',
            ]);
            if (! $id) {
                $this->fail('Failed to create expense category.');
            }

            return ['created' => true, 'id' => (int) $id];
        });
    }

    // ------------------------------------------------------------ destructive

    public function expenses_delete(int $id, bool $confirm = false): array
    {
        return $this->guard('expenses_delete', ['delete', 'expenses'], compact('id', 'confirm'), function () use ($id, $confirm) {
            $this->requireDestructive($confirm);

            $this->CI->load->model('expenses_model');
            $result = $this->CI->expenses_model->delete($id);

            // The model's return is tri-state; a bool cast would report an
            // invoiced refusal as a successful delete.
            if (is_array($result) && ! empty($result['invoiced'])) {
                $this->fail('Expense ' . $id . ' is billed on an invoice and cannot be deleted; delete or unlink the invoice first.');
            }

            return ['deleted' => $result === true, 'id' => $id];
        });
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A dangling category FK makes the expense invisible in the Perfex UI
     * (INNER JOIN), so every write validates it.
     */
    private function requireCategory(int $id): void
    {
        $this->CI->db->where('id', $id);
        if ($this->CI->db->get(db_prefix() . 'expenses_categories')->row_array()) {
            return;
        }
        $rows = $this->CI->db->order_by('name', 'asc')->get(db_prefix() . 'expenses_categories')->result_array();
        $list = array_map(static fn ($c) => $c['id'] . '=' . $c['name'], array_slice($rows, 0, 20));
        $tail = count($rows) > 20 ? ' (more: use expense_categories_list)' : '';
        $hint = $rows === []
            ? 'No categories exist yet - create one with expense_categories_create.'
            : 'Available: ' . implode(', ', $list) . $tail . '.';
        $this->fail('Unknown expense category ' . $id . '. ' . $hint);
    }

    private function validateRecurringPair(?int $repeatEvery, ?string $recurringType, int $min): void
    {
        if ($repeatEvery === null || $recurringType === null) {
            $this->fail('repeat_every and recurring_type must be provided together.');
        }
        if ($repeatEvery < $min) {
            $this->fail('repeat_every must be at least ' . $min . '.');
        }
        if (! in_array($recurringType, self::RECURRING_TYPES, true)) {
            $this->fail('recurring_type must be one of: ' . implode(', ', self::RECURRING_TYPES) . '.');
        }
    }

    /** All tblfiles rows for the expense; enriched with on-disk state when asked. */
    private function attachmentRows(int $expenseId, bool $withDisk = true): array
    {
        $this->CI->db->where(['rel_id' => $expenseId, 'rel_type' => 'expense']);
        $this->CI->db->order_by('id', 'asc');
        $rows = $this->CI->db->get(db_prefix() . 'files')->result_array();

        $out = [];
        $dir = $withDisk ? $this->expenseUploadDir($expenseId, false) : null;
        foreach ($rows as $row) {
            $entry = [
                'id'        => (int) $row['id'],
                'file_name' => $row['file_name'],
                'filetype'  => $row['filetype'],
                'dateadded' => $row['dateadded'],
                'staffid'   => (int) $row['staffid'],
            ];
            if ($withDisk) {
                $path                = $dir . $row['file_name'];
                $entry['on_disk']    = is_file($path);
                $entry['size_bytes'] = $entry['on_disk'] ? (int) filesize($path) : null;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /** Canonical Perfex path for an expense's files, optionally created. */
    private function expenseUploadDir(int $expenseId, bool $create = true): string
    {
        $base = function_exists('get_upload_path_by_type')
            ? get_upload_path_by_type('expense')
            : FCPATH . 'uploads/expenses/';
        $dir = rtrim($base, '/\\') . '/' . $expenseId . '/';

        if ($create && ! is_dir($dir)) {
            if (function_exists('_maybe_create_upload_path')) {
                _maybe_create_upload_path($dir);
            } else {
                mkdir($dir, 0755, true);
                @file_put_contents($dir . 'index.html', '');
            }
        }
        if ($create && ! is_dir($dir)) {
            $this->fail('Could not create the expense upload directory.');
        }

        return $dir;
    }

    /**
     * basename + charset whitelist; dots are stripped from the stem (kills
     * double-extension smuggling) and the extension must match the DETECTED
     * type - the client's claim never survives on its own.
     */
    private function sanitizeReceiptFilename(string $filename, string $mime): string
    {
        $spec = self::ALLOWED_MIME[$mime];
        $name = basename(str_replace('\\', '/', trim($filename)));

        $dot  = strrpos($name, '.');
        $stem = $dot === false ? $name : substr($name, 0, $dot);
        $ext  = $dot === false ? '' : strtolower(substr($name, $dot + 1));

        $stem = preg_replace('/[^A-Za-z0-9_ -]+/', '-', str_replace('.', '-', $stem));
        $stem = trim(preg_replace('/-{2,}/', '-', (string) $stem), '- ');
        if ($stem === '') {
            $stem = 'receipt';
        }

        if ($ext === '') {
            $ext = $spec['ext'];
        } elseif ($ext !== $spec['ext'] && ! in_array($ext, $spec['alt'], true)) {
            $this->fail('Filename extension ".' . $ext . '" does not match the detected type ' . $mime
                . ' (expected .' . $spec['ext'] . ').');
        }

        return substr($stem, 0, 150 - strlen($ext) - 1) . '.' . $ext;
    }
}

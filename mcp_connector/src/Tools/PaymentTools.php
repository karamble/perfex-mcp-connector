<?php

namespace PerfexMcp\Tools;

use Mcp\Exception\ToolCallException;
use PerfexMcp\Auth\ReadScope;

/**
 * Payment tools. Perfex marks view_own on payments as not applicable: without
 * global view on payments a staff member sees the payments of invoices they
 * created (with view_own on invoices) or are sale agent of (when the
 * allow_staff_view_invoices_assigned option is on) - Visibility 'payments'.
 */
final class PaymentTools extends AbstractTools
{
    public function payments_list(?int $invoice_id = null, int $limit = 25, int $offset = 0): array
    {
        return $this->guard(
            'payments_list',
            ['read', 'payments'],
            compact('invoice_id', 'limit', 'offset'),
            fn (ReadScope $scope) => $this->paginate(
                $scope,
                db_prefix() . 'invoicepaymentrecords',
                'id, invoiceid, amount, paymentmode, date, daterecorded, transactionid, note',
                function ($db) use ($invoice_id) {
                    if ($invoice_id !== null) {
                        $db->where('invoiceid', (int) $invoice_id);
                    }
                },
                'daterecorded',
                'desc',
                $limit,
                $offset
            )
        );
    }

    public function payments_get(int $id): array
    {
        return $this->guard(
            'payments_get',
            ['read', 'payments'],
            compact('id'),
            function (ReadScope $scope) use ($id) {
                $this->CI->load->model('payments_model');
                $payment = $this->CI->payments_model->get($id);
                if (! $payment) {
                    throw new ToolCallException('Payment ' . $id . ' not found.');
                }
                $this->assertVisible($scope, 'invoicepaymentrecords', $id, 'Payment');

                return ['payment' => $payment];
            }
        );
    }

    public function payments_record(int $invoice_id, float $amount, string $payment_mode, ?string $date = null, ?string $transaction_id = null, ?string $note = null, bool $send_email = false): array
    {
        return $this->guard('payments_record', ['create', 'payments'], compact('invoice_id', 'amount', 'payment_mode', 'date', 'transaction_id', 'note', 'send_email'), function () use ($invoice_id, $amount, $payment_mode, $date, $transaction_id, $note, $send_email) {
            $this->CI->load->model('payments_model');
            $data = [
                'invoiceid'   => $invoice_id,
                'amount'      => $amount,
                'paymentmode' => $payment_mode,
                'date'        => $date !== null ? to_sql_date($date) : date('Y-m-d'),
                'daterecorded' => date('Y-m-d H:i:s'),
            ];
            if ($transaction_id !== null) {
                $data['transactionid'] = $transaction_id;
            }
            if ($note !== null) {
                $data['note'] = $note;
            }
            if (! $send_email) {
                $data['do_not_send_email_template'] = true;
            }
            $id = $this->CI->payments_model->add($data);
            if (! $id) {
                $this->fail('Failed to record payment (invalid invoice or payment mode?).');
            }

            return ['created' => true, 'payment_id' => (int) $id, 'invoice_id' => $invoice_id];
        });
    }

    public function payments_delete(int $id, bool $confirm = false): array
    {
        return $this->guard('payments_delete', ['delete', 'payments'], compact('id', 'confirm'), function () use ($id, $confirm) {
            $this->requireDestructive($confirm);
            $this->CI->load->model('payments_model');
            $ok = $this->CI->payments_model->delete($id);

            return ['deleted' => (bool) $ok, 'id' => $id];
        });
    }
}

<?php

namespace PerfexMcp\Tools;

use Mcp\Exception\ToolCallException;
use PerfexMcp\Auth\ReadScope;

/**
 * Ticket tools. Perfex has no "tickets" permission feature: every staff
 * member is gated in, and when the option staff_access_only_assigned_departments
 * is on (Perfex's default) a non-admin sees only the tickets of their own
 * departments - none at all without a department (Visibility 'tickets').
 * Perfex applies the same check before any reply or status change on a
 * ticket (Tickets.php:205-214), and a ticket can only be opened in a
 * department the staff member may see (Visibility 'departments').
 */
final class TicketTools extends AbstractTools
{
    public function tickets_list(?int $status = null, ?int $customer_id = null, ?string $search = null, int $limit = 25, int $offset = 0): array
    {
        return $this->guard(
            'tickets_list',
            ['read', 'tickets'],
            compact('status', 'customer_id', 'search', 'limit', 'offset'),
            fn (ReadScope $scope) => $this->paginate(
                $scope,
                db_prefix() . 'tickets',
                'ticketid, subject, status, priority, department, userid, contactid, assigned, date, lastreply, service',
                function ($db) use ($status, $customer_id, $search) {
                    if ($status !== null) {
                        $db->where('status', (int) $status);
                    }
                    if ($customer_id !== null) {
                        $db->where('userid', (int) $customer_id);
                    }
                    if ($search !== null && $search !== '') {
                        $db->group_start()->like('subject', $search)->or_like('ticketkey', $search)->group_end();
                    }
                },
                'lastreply',
                'desc',
                $limit,
                $offset
            )
        );
    }

    public function tickets_get(int $id): array
    {
        return $this->guard(
            'tickets_get',
            ['read', 'tickets'],
            compact('id'),
            function (ReadScope $scope) use ($id) {
                $this->CI->load->model('tickets_model');
                $ticket = $this->CI->tickets_model->get($id);
                if (! $ticket) {
                    throw new ToolCallException('Ticket ' . $id . ' not found.');
                }
                $this->assertVisible($scope, 'tickets', $id, 'Ticket', 'ticketid');
                $replies = $this->CI->tickets_model->get_ticket_replies($id);

                return [
                    'ticket'  => $this->stripSecrets($ticket),
                    'replies' => $replies,
                ];
            }
        );
    }

    public function tickets_create(array $fields): array
    {
        return $this->guard('tickets_create', ['read', 'departments'], $fields, function (ReadScope $scope) use ($fields) {
            $this->requireFields($fields, ['subject', 'message', 'department']);
            if (empty($fields['contactid']) && empty($fields['userid']) && empty($fields['email'])) {
                $this->fail('Provide contactid (and userid), or an email, to open a ticket for.');
            }
            $this->requireVisibleRow($scope, 'departments', (int) $fields['department'], 'Department', 'departmentid');
            $data = $this->pickAllowed($fields, [
                'subject', 'message', 'department', 'priority', 'service',
                'contactid', 'userid', 'name', 'email', 'assigned', 'project_id',
            ]);
            $this->CI->load->model('tickets_model');
            $id = $this->CI->tickets_model->add($data, get_staff_user_id());
            if (! $id) {
                $this->fail('Failed to create ticket.');
            }

            return ['created' => true, 'id' => (int) $id];
        });
    }

    public function tickets_reply(int $id, string $message, ?int $status = null): array
    {
        return $this->guard('tickets_reply', ['read', 'tickets'], compact('id', 'message', 'status'), function (ReadScope $scope) use ($id, $message, $status) {
            $this->requireVisibleRow($scope, 'tickets', $id, 'Ticket', 'ticketid');
            $data = ['message' => $message, 'ticketid' => $id];
            if ($status !== null) {
                $data['status'] = $status;
            }
            $this->CI->load->model('tickets_model');
            $replyId = $this->CI->tickets_model->add_reply($data, $id, get_staff_user_id());
            if (! $replyId) {
                $this->fail('Failed to add reply.');
            }

            return ['created' => true, 'reply_id' => (int) $replyId, 'ticket_id' => $id];
        });
    }

    public function tickets_change_status(int $id, int $status): array
    {
        return $this->guard('tickets_change_status', ['read', 'tickets'], compact('id', 'status'), function (ReadScope $scope) use ($id, $status) {
            $this->requireVisibleRow($scope, 'tickets', $id, 'Ticket', 'ticketid');
            $this->CI->load->model('tickets_model');
            $this->CI->tickets_model->change_ticket_status($id, $status);

            return ['updated' => true, 'id' => $id, 'status' => $status];
        });
    }

    public function tickets_delete(int $id, bool $confirm = false): array
    {
        // Ticket deletion is admin-only in Perfex.
        return $this->guard('tickets_delete', ['is_admin'], compact('id', 'confirm'), function () use ($id, $confirm) {
            $this->requireDestructive($confirm);
            $this->CI->load->model('tickets_model');
            $ok = $this->CI->tickets_model->delete($id);

            return ['deleted' => (bool) $ok, 'id' => $id];
        });
    }
}

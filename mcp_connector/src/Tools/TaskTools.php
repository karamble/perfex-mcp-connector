<?php

namespace PerfexMcp\Tools;

use Mcp\Exception\ToolCallException;
use PerfexMcp\Auth\PermissionDenied;
use PerfexMcp\Auth\ReadScope;
use PerfexMcp\Auth\Visibility;

/**
 * Task tools. Without global view a staff member sees the tasks they are
 * assigned to or follow, created (not via a contact), public ones, and - when
 * show_all_tasks_for_project_member is on - tasks of projects they belong to
 * (Visibility 'tasks' = Perfex's get_tasks_where_string()). Status changes
 * follow Tasks.php:847-851: edit capability, assignee, or creator.
 */
final class TaskTools extends AbstractTools
{
    public function tasks_list(?string $search = null, ?int $status = null, ?string $rel_type = null, ?int $rel_id = null, int $limit = 25, int $offset = 0): array
    {
        return $this->guard(
            'tasks_list',
            ['read', 'tasks'],
            compact('search', 'status', 'rel_type', 'rel_id', 'limit', 'offset'),
            fn (ReadScope $scope) => $this->paginate(
                $scope,
                db_prefix() . 'tasks',
                'id, name, priority, status, startdate, duedate, datefinished, rel_id, rel_type, billable, billed, milestone',
                function ($db) use ($search, $status, $rel_type, $rel_id) {
                    if ($status !== null) {
                        $db->where('status', (int) $status);
                    }
                    if ($rel_type !== null && $rel_type !== '') {
                        $db->where('rel_type', $rel_type);
                    }
                    if ($rel_id !== null) {
                        $db->where('rel_id', (int) $rel_id);
                    }
                    if ($search !== null && $search !== '') {
                        $db->like('name', $search);
                    }
                },
                'duedate',
                'desc',
                $limit,
                $offset
            )
        );
    }

    public function tasks_get(int $id): array
    {
        return $this->guard(
            'tasks_get',
            ['read', 'tasks'],
            compact('id'),
            function (ReadScope $scope) use ($id) {
                $this->CI->load->model('tasks_model');
                $task = $this->CI->tasks_model->get($id);
                if (! $task) {
                    throw new ToolCallException('Task ' . $id . ' not found.');
                }
                $this->assertVisible($scope, 'tasks', $id, 'Task');

                return ['task' => $task];
            }
        );
    }

    private const WRITABLE = [
        'name', 'description', 'priority', 'startdate', 'duedate', 'status',
        'milestone', 'billable', 'hourly_rate', 'rel_type', 'rel_id',
        'visible_to_client',
    ];

    public function tasks_create(array $fields): array
    {
        return $this->guard('tasks_create', ['create', 'tasks'], $fields, function () use ($fields) {
            $this->requireFields($fields, ['name', 'startdate']);
            $data = $this->pickAllowed($fields, self::WRITABLE);
            $data['status']   = $data['status'] ?? 1;
            $data['priority'] = $data['priority'] ?? 2;
            $this->CI->load->model('tasks_model');
            $id = $this->CI->tasks_model->add($data);
            if (! $id) {
                $this->fail('Failed to create task.');
            }

            return ['created' => true, 'id' => (int) $id];
        });
    }

    public function tasks_update(int $id, array $fields): array
    {
        return $this->guard('tasks_update', ['edit', 'tasks'], compact('id', 'fields'), function () use ($id, $fields) {
            $data = $this->pickAllowed($fields, self::WRITABLE);
            if ($data === []) {
                $this->fail('No updatable fields provided.');
            }
            // update() runs to_sql_date on start/duedate; supply existing when absent.
            $this->CI->load->model('tasks_model');
            $existing = $this->CI->tasks_model->get($id);
            if (! $existing) {
                $this->fail('Task ' . $id . ' not found.');
            }
            $data['startdate'] = $data['startdate'] ?? $existing->startdate;
            $data['duedate']   = $data['duedate']   ?? $existing->duedate;
            $this->CI->tasks_model->update($data, $id);

            return ['updated' => true, 'id' => $id];
        });
    }

    public function tasks_change_status(int $id, int $status): array
    {
        return $this->guard('tasks_change_status', ['read', 'tasks'], compact('id', 'status'), function (ReadScope $scope) use ($id, $status) {
            $task = $this->requireVisibleRow($scope, 'tasks', $id, 'Task');
            // Tasks.php:847-851 mark_as(): edit capability, assignee or creator.
            $staffId   = (int) get_staff_user_id();
            $isCreator = (int) $task['addedfrom'] === $staffId && (int) ($task['is_added_from_contact'] ?? 0) === 0;
            if (! staff_can('edit', 'tasks') && ! $isCreator && ! $this->isAssignee($id, $staffId)) {
                throw new PermissionDenied('Permission denied: changing the status of task ' . $id . ' requires "edit" on tasks, or being its assignee or creator.');
            }
            $this->CI->load->model('tasks_model');
            $this->CI->tasks_model->mark_as($status, $id);

            return ['updated' => true, 'id' => $id, 'status' => $status];
        });
    }

    public function tasks_comment_add(int $task_id, string $content): array
    {
        return $this->guard('tasks_comment_add', ['create', 'tasks'], compact('task_id', 'content'), function () use ($task_id, $content) {
            $this->assertVisible(Visibility::scope('tasks'), 'tasks', $task_id, 'Task');
            $this->CI->load->model('tasks_model');
            $id = $this->CI->tasks_model->add_task_comment(['taskid' => $task_id, 'content' => $content]);
            if (! $id) {
                $this->fail('Failed to add comment.');
            }

            return ['created' => true, 'comment_id' => (int) $id, 'task_id' => $task_id];
        });
    }

    public function tasks_delete(int $id, bool $confirm = false): array
    {
        return $this->guard('tasks_delete', ['delete', 'tasks'], compact('id', 'confirm'), function () use ($id, $confirm) {
            $this->requireDestructive($confirm);
            $this->CI->load->model('tasks_model');
            $ok = $this->CI->tasks_model->delete_task($id);

            return ['deleted' => (bool) $ok, 'id' => $id];
        });
    }

    private function isAssignee(int $taskId, int $staffId): bool
    {
        $this->CI->db->where(['taskid' => $taskId, 'staffid' => $staffId]);

        return (int) $this->CI->db->count_all_results(db_prefix() . 'task_assigned') > 0;
    }
}

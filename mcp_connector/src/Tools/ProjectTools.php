<?php

namespace PerfexMcp\Tools;

use Mcp\Exception\ToolCallException;
use PerfexMcp\Auth\ReadScope;

/**
 * Project tools. Without global view a staff member sees the projects they
 * are a member of (Visibility 'projects', tblproject_members), as in Perfex.
 */
final class ProjectTools extends AbstractTools
{
    public function projects_list(?int $customer_id = null, ?int $status = null, ?string $search = null, int $limit = 25, int $offset = 0): array
    {
        return $this->guard(
            'projects_list',
            ['read', 'projects'],
            compact('customer_id', 'status', 'search', 'limit', 'offset'),
            fn (ReadScope $scope) => $this->paginate(
                $scope,
                db_prefix() . 'projects',
                'id, name, status, clientid, billing_type, start_date, deadline, progress, project_cost, date_finished',
                function ($db) use ($customer_id, $status, $search) {
                    if ($customer_id !== null) {
                        $db->where('clientid', (int) $customer_id);
                    }
                    if ($status !== null) {
                        $db->where('status', (int) $status);
                    }
                    if ($search !== null && $search !== '') {
                        $db->like('name', $search);
                    }
                },
                'start_date',
                'desc',
                $limit,
                $offset
            )
        );
    }

    public function projects_get(int $id): array
    {
        return $this->guard(
            'projects_get',
            ['read', 'projects'],
            compact('id'),
            function (ReadScope $scope) use ($id) {
                $this->CI->load->model('projects_model');
                $project = $this->CI->projects_model->get($id);
                if (! $project) {
                    throw new ToolCallException('Project ' . $id . ' not found.');
                }
                $this->assertVisible($scope, 'projects', $id, 'Project');

                return ['project' => $project];
            }
        );
    }

    private const WRITABLE = [
        'name', 'description', 'status', 'clientid', 'billing_type',
        'start_date', 'deadline', 'project_cost', 'project_rate_per_hour',
        'estimated_hours', 'progress', 'progress_from_tasks',
    ];

    public function projects_create(array $fields): array
    {
        return $this->guard('projects_create', ['create', 'projects'], $fields, function () use ($fields) {
            $this->requireFields($fields, ['name', 'clientid', 'billing_type', 'start_date']);
            $data = $this->pickAllowed($fields, self::WRITABLE);
            $data['status'] = $data['status'] ?? 2;
            $this->CI->load->model('projects_model');
            $id = $this->CI->projects_model->add($data);
            if (! $id) {
                $this->fail('Failed to create project.');
            }

            return ['created' => true, 'id' => (int) $id];
        });
    }

    public function projects_update(int $id, array $fields): array
    {
        return $this->guard('projects_update', ['edit', 'projects'], compact('id', 'fields'), function () use ($id, $fields) {
            $data = $this->pickAllowed($fields, self::WRITABLE);
            if ($data === []) {
                $this->fail('No updatable fields provided.');
            }
            $this->CI->load->model('projects_model');
            $this->CI->projects_model->update($data, $id);

            return ['updated' => true, 'id' => $id];
        });
    }

    public function projects_delete(int $id, bool $confirm = false): array
    {
        return $this->guard('projects_delete', ['delete', 'projects'], compact('id', 'confirm'), function () use ($id, $confirm) {
            $this->requireDestructive($confirm);
            $this->CI->load->model('projects_model');
            $ok = $this->CI->projects_model->delete($id);

            return ['deleted' => (bool) $ok, 'id' => $id];
        });
    }
}

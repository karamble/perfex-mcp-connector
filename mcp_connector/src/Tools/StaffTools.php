<?php

namespace PerfexMcp\Tools;

use Mcp\Exception\ToolCallException;
use PerfexMcp\Auth\ReadScope;

/**
 * Staff directory (read-only). Only non-sensitive columns are ever returned
 * (no password, 2FA secret, reset keys, IPs).
 *
 * Perfex gates the staff directory page on the staff "view" capability but
 * shows staff NAMES to every staff member (assignee pickers, task and ticket
 * views). The tools mirror that: any staff member gets id, name and active
 * flag (fields: "names"); the full directory - email, phone, role, admin,
 * dates - only with view on staff (fields: "directory"). The names-only mode
 * also searches names only, so an email LIKE cannot reveal that an address
 * exists.
 */
final class StaffTools extends AbstractTools
{
    private const SAFE_COLUMNS = 'staffid, firstname, lastname, email, phonenumber, admin, role, active, datecreated, last_login';
    private const NAME_COLUMNS = 'staffid, firstname, lastname, active';

    public function staff_list(?string $search = null, bool $active_only = true, int $limit = 25, int $offset = 0): array
    {
        return $this->guard(
            'staff_list',
            ['read', 'staff'],
            compact('search', 'active_only', 'limit', 'offset'),
            function (ReadScope $scope) use ($search, $active_only, $limit, $offset) {
                $result = $this->paginate(
                    $scope,
                    db_prefix() . 'staff',
                    $scope->lite ? self::NAME_COLUMNS : self::SAFE_COLUMNS,
                    function ($db) use ($scope, $search, $active_only) {
                        if ($active_only) {
                            $db->where('active', 1);
                        }
                        if ($search !== null && $search !== '') {
                            $db->group_start()->like('firstname', $search)->or_like('lastname', $search);
                            if (! $scope->lite) {
                                $db->or_like('email', $search);
                            }
                            $db->group_end();
                        }
                    },
                    'firstname',
                    'asc',
                    $limit,
                    $offset
                );
                $result['fields'] = $scope->lite ? 'names' : 'directory';

                return $result;
            }
        );
    }

    public function staff_get(int $id): array
    {
        return $this->guard(
            'staff_get',
            ['read', 'staff'],
            compact('id'),
            function (ReadScope $scope) use ($id) {
                $row = $this->CI->db
                    ->select($scope->lite ? self::NAME_COLUMNS : self::SAFE_COLUMNS)
                    ->where('staffid', $id)
                    ->get(db_prefix() . 'staff')
                    ->row_array();

                if (! $row) {
                    throw new ToolCallException('Staff member ' . $id . ' not found.');
                }

                return ['staff' => $row, 'fields' => $scope->lite ? 'names' : 'directory'];
            }
        );
    }
}

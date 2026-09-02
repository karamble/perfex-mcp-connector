<?php

namespace PerfexMcp\Tools;

use PerfexMcp\Auth\PermissionDenied;
use PerfexMcp\Auth\ReadScope;

/**
 * Notes attached to CRM entities. Access follows the parent entity: the
 * parent's Visibility rule decides whether the staff member may see it, and
 * a note on a hidden parent is refused - listing and adding alike.
 */
final class NoteTools extends AbstractTools
{
    /** rel_type => [visibility feature, table (no prefix), primary key] */
    private const REL = [
        'customer' => ['customers', 'clients', 'userid'],
        'lead'     => ['leads', 'leads', 'id'],
        'invoice'  => ['invoices', 'invoices', 'id'],
        'estimate' => ['estimates', 'estimates', 'id'],
        'project'  => ['projects', 'projects', 'id'],
        'task'     => ['tasks', 'tasks', 'id'],
        'ticket'   => ['tickets', 'tickets', 'ticketid'],
    ];

    public function notes_list(string $rel_type, int $rel_id): array
    {
        return $this->guard(
            'notes_list',
            $this->permissionFor($rel_type),
            compact('rel_type', 'rel_id'),
            function (?ReadScope $scope) use ($rel_type, $rel_id) {
                $this->assertParentVisible($scope, $rel_type, $rel_id);
                $this->CI->load->model('misc_model');
                $notes = $this->CI->misc_model->get_notes($rel_id, $rel_type);

                return [
                    'rel_type' => $rel_type,
                    'rel_id'   => $rel_id,
                    'data'     => $notes,
                ];
            }
        );
    }

    public function notes_add(string $rel_type, int $rel_id, string $description): array
    {
        return $this->guard('notes_add', $this->permissionFor($rel_type), compact('rel_type', 'rel_id', 'description'), function (?ReadScope $scope) use ($rel_type, $rel_id, $description) {
            $this->assertParentVisible($scope, $rel_type, $rel_id);
            $this->CI->load->model('misc_model');
            $id = $this->CI->misc_model->add_note(['description' => $description], $rel_type, $rel_id);
            if (! $id) {
                $this->fail('Failed to add note.');
            }

            return ['created' => true, 'note_id' => (int) $id];
        });
    }

    public function notes_delete(int $id, bool $confirm = false): array
    {
        // Note ownership isn't feature-scoped; require admin or the note author.
        return $this->guard('notes_delete', ['is_staff_member'], compact('id', 'confirm'), function () use ($id, $confirm) {
            $this->requireDestructive($confirm);
            $this->CI->load->model('misc_model');
            $note = $this->CI->db->where('id', $id)->get(db_prefix() . 'notes')->row();
            if (! $note) {
                $this->fail('Note ' . $id . ' not found.');
            }
            if (! is_admin() && (int) $note->addedfrom !== (int) get_staff_user_id()) {
                throw new PermissionDenied('You can only delete your own notes.');
            }
            $ok = $this->CI->misc_model->delete_note($id);

            return ['deleted' => (bool) $ok, 'id' => $id];
        });
    }

    /** A known rel_type reads under its parent's rule; an unknown one is refused inside the guard. */
    private function permissionFor(string $rel_type): array
    {
        return isset(self::REL[$rel_type]) ? ['read', self::REL[$rel_type][0]] : ['is_staff_member'];
    }

    private function assertParentVisible(?ReadScope $scope, string $rel_type, int $rel_id): void
    {
        if (! isset(self::REL[$rel_type]) || $scope === null) {
            throw new PermissionDenied('Unknown rel_type "' . $rel_type . '". Valid: ' . implode(', ', array_keys(self::REL)) . '.');
        }
        [, $table, $pk] = self::REL[$rel_type];
        $this->assertVisible($scope, $table, $rel_id, ucfirst($rel_type), $pk);
    }
}

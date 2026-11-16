<?php

namespace Nawasara\Workspace\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * Who is admin Kisara for Workspace (D-03: there is no OPD admin).
 *
 * Admin Kisara = the local Nawasara account whose username is this NIP and
 * that holds `workspace.template.manage`. One rule for both places that ask:
 * the `is_admin` flag of the access API (Workspace Web shows Kelola Templat
 * from it) and the actor check of the template write endpoints. If they
 * disagreed, the menu could show for someone whose writes then fail, or
 * worse, the other way round.
 */
class WorkspaceAdmins
{
    public const PERMISSION = 'workspace.template.manage';

    /** The admin's local account, or null when this NIP is not admin Kisara. */
    public function userForNip(string $nip): ?Model
    {
        if (preg_match('/^\d{18}$/', $nip) !== 1) {
            return null;
        }

        $userModel = config('auth.providers.users.model');
        $user = $userModel::where('username', $nip)->first();

        return $user !== null && $user->can(self::PERMISSION) ? $user : null;
    }

    public function isAdmin(string $nip): bool
    {
        return $this->userForNip($nip) !== null;
    }
}

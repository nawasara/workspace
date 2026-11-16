<?php

namespace Nawasara\Workspace\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permissions for Kisara Workspace administration in Nawasara.
 *
 * There is no "admin OPD" (D-03): OPD members manage their own sub-folder
 * access inside Workspace itself; everything below is for admin Kisara.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'workspace.view',                // Ringkasan + read-only pages
            'workspace.pilot.manage',        // add/remove pilot participants, kill switch (T-05)
            'workspace.quota.manage',        // quota defaults and overrides (T-14)
            'workspace.folder.manage',       // OPD folders; undo a sub-folder ACL (T-12, T-13)
            'workspace.template.manage',     // Kelola Templat (T-31, T-32)
            'workspace.offboarding.manage',  // departed employees' Drive queue (T-33)
            'workspace.audit.view',          // access trail (T-36)
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::where('name', 'developer')->first();
        if ($role) {
            $role->givePermissionTo($permissions);
        }
    }
}

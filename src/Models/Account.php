<?php

namespace Nawasara\Workspace\Models;

use Illuminate\Database\Eloquent\Model;

/** What Nawasara last wrote to Nextcloud for one ASN (see the migration). */
class Account extends Model
{
    protected $table = 'nawasara_workspace_accounts';

    protected $fillable = ['nip', 'keycloak_id', 'display_name', 'email', 'quota', 'opd_id', 'state_hash', 'nc_created_at', 'nc_synced_at', 'last_error'];

    protected $casts = [
        'nc_created_at' => 'datetime',
        'nc_synced_at' => 'datetime',
    ];

    /** Nextcloud group of an OPD. Named by id so an OPD rename breaks nothing. */
    public static function opdGroup(int $opdId): string
    {
        return 'OPD-'.$opdId;
    }
}

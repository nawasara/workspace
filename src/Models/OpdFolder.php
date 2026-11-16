<?php

namespace Nawasara\Workspace\Models;

use Illuminate\Database\Eloquent\Model;

/** The Nextcloud team folder of one OPD (FR-12). */
class OpdFolder extends Model
{
    protected $table = 'nawasara_workspace_opd_folders';

    protected $fillable = ['opd_id', 'nc_folder_id', 'name', 'quota_bytes'];
}

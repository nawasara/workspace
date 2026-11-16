<?php

namespace Nawasara\Workspace\Models;

use Illuminate\Database\Eloquent\Model;

/** Read position of IngestAuditLogJob per source (T-36, see the migration). */
class AuditCursor extends Model
{
    protected $table = 'nawasara_workspace_audit_cursors';

    protected $fillable = ['source', 'offset', 'fingerprint', 'last_read_at', 'last_error'];

    protected $casts = [
        'offset' => 'integer',
        'last_read_at' => 'datetime',
    ];
}

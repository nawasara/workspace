<?php

namespace Nawasara\Workspace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One access event from the Nextcloud admin_audit log (T-36, see the migration). */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'nawasara_workspace_audit_events';

    protected $guarded = ['id'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'file_id' => 'integer',
    ];

    /** Action => label shown to staff (FR-20 AC-1). */
    public const ACTIONS = [
        'login' => 'Masuk',
        'create' => 'Membuat',
        'open' => 'Membuka',
        'update' => 'Mengubah',
        'share' => 'Membagikan',
        'unshare' => 'Mencabut bagian',
        'delete' => 'Menghapus',
        'restore' => 'Memulihkan',
        'acl_change' => 'Ubah akses folder',
    ];

    public const ACTION_COLORS = [
        'login' => 'neutral',
        'create' => 'success',
        'open' => 'info',
        'update' => 'primary',
        'share' => 'warning',
        'unshare' => 'warning',
        'delete' => 'danger',
        'restore' => 'success',
        'acl_change' => 'danger',
    ];

    /** Display name from the account Nawasara provisioned for this NIP. */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'nip', 'nip');
    }

    /**
     * Path as the employee sees it: Nextcloud logs "/<uid>/files/Dokumen/x",
     * the "/<uid>/files" part is noise on screen (the NIP is its own column).
     */
    public function displayPath(): ?string
    {
        if ($this->file_path === null) {
            return null;
        }

        return preg_replace('#^/[^/]+/files(?=/|$)#', '', $this->file_path) ?: '/';
    }
}

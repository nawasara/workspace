<?php

namespace Nawasara\Workspace\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nawasara\Registry\Models\Opd;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** One departure of one employee from Workspace (T-33, see the migration). */
class Offboarding extends Model
{
    use LogsActivity;

    public const STATUS_HELD = 'held';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_DELETED = 'deleted';

    public const STATUSES = [
        self::STATUS_HELD => 'Ditahan',
        self::STATUS_CANCELLED => 'Dibatalkan',
        self::STATUS_DELETED => 'Dihapus',
    ];

    public const CANCEL_REENABLED = 'reenabled';

    public const CANCEL_MANUAL = 'manual';

    protected $table = 'nawasara_workspace_offboarding';

    protected $fillable = [
        'nip', 'display_name', 'opd_id', 'status', 'nc_disabled',
        'disabled_at', 'hold_until', 'warned_at',
        'moved_at', 'moved_by', 'moved_to', 'moved_items',
        'cancelled_at', 'cancelled_by', 'cancel_reason',
        'deleted_at_nc', 'deleted_by', 'last_error',
    ];

    protected $casts = [
        'opd_id' => 'integer',
        'nc_disabled' => 'boolean',
        'moved_items' => 'integer',
        'disabled_at' => 'datetime',
        'hold_until' => 'datetime',
        'warned_at' => 'datetime',
        'moved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'deleted_at_nc' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('workspace')
            ->logOnly(['nip', 'status', 'nc_disabled', 'hold_until', 'warned_at', 'moved_to', 'cancel_reason', 'deleted_at_nc'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Pegawai keluar: akun Workspace ditahan',
                'deleted' => 'Catatan pegawai keluar dihapus',
                default => 'Pegawai keluar: status diubah',
            });
    }

    public function opd(): BelongsTo
    {
        return $this->belongsTo(Opd::class, 'opd_id');
    }

    public function mover(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'moved_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'cancelled_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'deleted_by');
    }

    public function scopeHeld(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_HELD);
    }

    public function isHeld(): bool
    {
        return $this->status === self::STATUS_HELD;
    }

    /** Whole days left before deletion (0 when due). */
    public function daysLeft(): int
    {
        return max(0, (int) ceil(now()->diffInSeconds($this->hold_until, false) / 86400));
    }
}

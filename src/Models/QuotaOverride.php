<?php

namespace Nawasara\Workspace\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nawasara\Registry\Models\Opd;
use Nawasara\Workspace\Support\QuotaSize;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A quota exception for one employee (Drive) or one OPD (Folder OPD).
 * Without a row the default from config applies (FR-13 AC-1).
 */
class QuotaOverride extends Model
{
    use LogsActivity;

    public const TYPE_EMPLOYEE = 'pegawai';

    public const TYPE_OPD = 'opd';

    public const TYPES = [
        self::TYPE_EMPLOYEE => 'Pegawai',
        self::TYPE_OPD => 'Folder OPD',
    ];

    protected $table = 'nawasara_workspace_quota_overrides';

    protected $fillable = ['target_type', 'nip', 'opd_id', 'name', 'quota_bytes', 'reason', 'created_by', 'updated_by'];

    protected $casts = [
        'opd_id' => 'integer',
        'quota_bytes' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('workspace')
            ->logOnly(['target_type', 'nip', 'opd_id', 'name', 'quota_bytes', 'reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Penyimpangan kuota ditambahkan',
                'deleted' => 'Penyimpangan kuota dihapus',
                default => 'Penyimpangan kuota diubah',
            });
    }

    public function opd(): BelongsTo
    {
        return $this->belongsTo(Opd::class, 'opd_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'updated_by');
    }

    public function scopeEmployees(Builder $query): Builder
    {
        return $query->where('target_type', self::TYPE_EMPLOYEE);
    }

    public function scopeOpds(Builder $query): Builder
    {
        return $query->where('target_type', self::TYPE_OPD);
    }

    /** "5 GB" */
    public function quotaLabel(): string
    {
        return QuotaSize::format($this->quota_bytes);
    }

    /** Default quota for this kind of target, as written in config. */
    public static function defaultFor(string $type): string
    {
        return $type === self::TYPE_OPD
            ? (string) config('nawasara-workspace.quota.opd_folder_default', '50 GB')
            : (string) config('nawasara-workspace.quota.drive_default', '3 GB');
    }
}

<?php

namespace Nawasara\Workspace\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nawasara\Registry\Models\Opd;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A pilot participant (FR-02). Active = `removed_at` is null.
 */
class PilotMember extends Model
{
    use LogsActivity;

    public const STAGES = [
        0 => 'Tahap 0: pemilik',
        1 => 'Tahap 1: Diskominfo',
        2 => 'Tahap 2: OPD pilot',
    ];

    protected $table = 'nawasara_workspace_pilot_members';

    protected $fillable = ['nip', 'name', 'keycloak_id', 'stage', 'opd_id', 'note', 'added_by', 'removed_at', 'removed_by'];

    protected $casts = [
        'stage' => 'integer',
        'removed_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('workspace')
            ->logOnly(['nip', 'name', 'stage', 'opd_id', 'note', 'removed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Peserta pilot ditambahkan',
                'deleted' => 'Peserta pilot dihapus',
                default => 'Peserta pilot diubah',
            });
    }

    public function opd(): BelongsTo
    {
        return $this->belongsTo(Opd::class, 'opd_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    public function isActive(): bool
    {
        return $this->removed_at === null;
    }
}

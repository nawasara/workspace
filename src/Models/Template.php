<?php

namespace Nawasara\Workspace\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nawasara\Registry\Models\Opd;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A template in the "Mulai ... baru" ribbon (FR-15, FR-16). Metadata only;
 * the file is `file_name` in the team folder "Templat Kisara".
 *
 * Lifecycle (FSD §6): draft -> active <-> inactive -> (deleted).
 */
class Template extends Model
{
    use HasUuids;
    use LogsActivity;

    public const APP_DOCS = 'docs';

    public const APP_SHEETS = 'sheets';

    public const APP_SLIDES = 'slides';

    /** app => file extension. One format per app: what OnlyOffice opens natively. */
    public const EXTENSIONS = [
        self::APP_DOCS => 'docx',
        self::APP_SHEETS => 'xlsx',
        self::APP_SLIDES => 'pptx',
    ];

    public const MIME = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public const SCOPE_ALL = 'all';

    public const SCOPE_OPD = 'opd';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $table = 'nawasara_workspace_templates';

    protected $fillable = ['name', 'description', 'app', 'scope', 'opd_id', 'status', 'sort_order',
        'file_name', 'file_id', 'size_bytes', 'builtin_key', 'created_by', 'updated_by'];

    protected $casts = [
        'opd_id' => 'integer',
        'sort_order' => 'integer',
        'file_id' => 'integer',
        'size_bytes' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('workspace')
            ->logOnly(['name', 'description', 'app', 'scope', 'opd_id', 'status', 'sort_order', 'file_name', 'size_bytes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Templat ditambahkan',
                'deleted' => 'Templat dihapus',
                default => 'Templat diubah',
            });
    }

    public function opd(): BelongsTo
    {
        return $this->belongsTo(Opd::class, 'opd_id');
    }

    /** One of the 14 Kisara templates shipped with the package (T-21). */
    public function isBuiltin(): bool
    {
        return $this->builtin_key !== null;
    }

    public function extension(): string
    {
        return self::EXTENSIONS[$this->app] ?? strtolower(pathinfo($this->file_name, PATHINFO_EXTENSION));
    }

    /** Path relative to a Nextcloud user's root, the same for admin and employee. */
    public function path(): string
    {
        return self::folder().'/'.$this->file_name;
    }

    public static function folder(): string
    {
        return (string) config('nawasara-workspace.templates.folder', 'Templat Kisara');
    }

    public static function appForExtension(string $ext): ?string
    {
        $app = array_search(strtolower($ext), self::EXTENSIONS, true);

        return $app === false ? null : $app;
    }

    /**
     * What an employee sees in the ribbon (FR-16 AC-4): active, for every
     * OPD or for their own. Ordered by sort_order, then name.
     */
    public function scopeVisibleTo(Builder $query, ?int $opdId): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(function (Builder $q) use ($opdId) {
                $q->where('scope', self::SCOPE_ALL);
                if ($opdId !== null) {
                    $q->orWhere(fn (Builder $o) => $o->where('scope', self::SCOPE_OPD)->where('opd_id', $opdId));
                }
            });
    }

    /** Ribbon order: app (docs, sheets, slides), then sort_order, then name. */
    public function scopeRibbonOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE app WHEN 'docs' THEN 1 WHEN 'sheets' THEN 2 WHEN 'slides' THEN 3 ELSE 4 END")
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}

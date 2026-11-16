<?php

namespace Nawasara\Workspace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Workspace key/value settings (kill switch, pilot_open).
 *
 * Read through a 30-second cache: the access API (T-06) asks on every new
 * Workspace session, and a flip from the panel must still reach both doors
 * well within the one minute FR-02 AC-3 allows.
 */
class Setting extends Model
{
    public const KILL_SWITCH = 'kill_switch';

    public const PILOT_OPEN = 'pilot_open';

    protected $table = 'nawasara_workspace_settings';

    protected $fillable = ['key', 'value', 'updated_by'];

    public static function flag(string $key): bool
    {
        return Cache::remember("nawasara-workspace:setting:{$key}", 30, fn () => static::where('key', $key)->value('value') === '1');
    }

    public static function setFlag(string $key, bool $on, ?int $userId = null): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $on ? '1' : '0', 'updated_by' => $userId]);
        Cache::forget("nawasara-workspace:setting:{$key}");
    }
}

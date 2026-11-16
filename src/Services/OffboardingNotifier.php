<?php

namespace Nawasara\Workspace\Services;

use Illuminate\Support\Facades\Log;
use Nawasara\Workspace\Models\Offboarding;

/**
 * The H-7 warning before a departed employee's Drive is deleted (FR-18 AC-4).
 *
 * Goes through nawasara/alerting (Telegram channel of admin Kisara) when it
 * is installed; it is optional for this package, so without it the warning
 * is a log line plus the "Peringatan terkirim" mark on the Pegawai Keluar
 * page (`warned_at`).
 *
 * One alert per departure: target id = the offboarding row id, which never
 * changes for that departure, and the caller fires once (warned_at). The
 * alert is resolved when the departure ends (deleted or cancelled), so it
 * does not stay "firing" forever.
 */
class OffboardingNotifier
{
    public const RULE = 'workspace.offboarding.deletion-due';

    public const TARGET_TYPE = 'WorkspaceOffboarding';

    /** Register the alert rule. Called from the service provider. */
    public static function registerRule(): void
    {
        if (! class_exists(\Nawasara\Alerting\Facades\Alerter::class)) {
            return;
        }

        \Nawasara\Alerting\Facades\Alerter::registerOrReplaceRule(\Nawasara\Alerting\Models\AlertRule::make([
            'key' => self::RULE,
            'severity' => 'warning',
            'category' => 'workspace',
            // Fired once per departure; the cooldown only guards a re-fire.
            'cooldown_minutes' => 10080,
            'description' => 'Drive Saya pegawai keluar akan dihapus dalam 7 hari',
            'subject_template' => '[{severity}] Workspace: Drive {context.name} ({context.nip}) dihapus {context.date}. Pindahkan ke Folder OPD bila perlu.',
        ]));
    }

    public function warn(Offboarding $row): void
    {
        Log::info('workspace offboarding: deletion due in 7 days', ['offboarding_id' => $row->id, 'hold_until' => $row->hold_until->toIso8601String()]);

        if (! $this->alerting()) {
            return;
        }

        \Nawasara\Alerting\Facades\Alerter::fire(self::RULE, self::TARGET_TYPE, (string) $row->id, [
            'nip' => $row->nip,
            'name' => $row->display_name,
            'date' => $row->hold_until->timezone('Asia/Jakarta')->format('d M Y'),
        ]);
    }

    public function clear(Offboarding $row): void
    {
        if ($row->warned_at === null || ! $this->alerting()) {
            return;
        }

        \Nawasara\Alerting\Facades\Alerter::resolve(self::RULE, self::TARGET_TYPE, (string) $row->id);
    }

    private function alerting(): bool
    {
        return class_exists(\Nawasara\Alerting\Facades\Alerter::class)
            && \Nawasara\Alerting\Facades\Alerter::hasRule(self::RULE);
    }
}

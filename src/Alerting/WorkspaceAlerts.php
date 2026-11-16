<?php

namespace Nawasara\Workspace\Alerting;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Nawasara\Sync\Events\SyncJobFinalFailed;
use Nawasara\Sync\Events\SyncJobSucceeded;

/**
 * Workspace alerts to the Nawasara Telegram channel (T-35, NFR-07) and job
 * lifecycle logs (NFR-06).
 *
 * nawasara/alerting is optional for this package. Without it the rules are
 * not registered, the monitors still run and log, and nothing throws.
 *
 * Every rule keys its alert on WHAT is broken (a job name, OnlyOffice, one
 * LXC), never on a run id: a key that changes per attempt makes a new alert
 * per failure that can never be resolved (see SyncJobFinalFailedListener in
 * nawasara/alerting for what that did in production).
 */
class WorkspaceAlerts
{
    public const RULE_PROVISIONING = 'workspace.provisioning.failed';

    public const RULE_ONLYOFFICE = 'workspace.onlyoffice.down';

    public const RULE_DISK = 'workspace.disk.high';

    /** Facade used for every call. A seam so tests can simulate "not installed". */
    public static string $alerter = \Nawasara\Alerting\Facades\Alerter::class;

    public static function available(): bool
    {
        return class_exists(static::$alerter);
    }

    /** Rules, listeners. Called from the service provider's boot(). */
    public static function boot(Application $app): void
    {
        static::registerRules();

        Event::listen(SyncJobFinalFailed::class, [ProvisioningFailureMonitor::class, 'onFailed']);
        Event::listen(SyncJobSucceeded::class, [ProvisioningFailureMonitor::class, 'onSucceeded']);

        Event::listen(JobProcessing::class, [JobLifecycleLog::class, 'onProcessing']);
        Event::listen(JobExceptionOccurred::class, [JobLifecycleLog::class, 'onException']);
        Event::listen(JobProcessed::class, [JobLifecycleLog::class, 'onDone']);
        Event::listen(JobFailed::class, [JobLifecycleLog::class, 'onDone']);
        Event::listen(SyncJobSucceeded::class, [JobLifecycleLog::class, 'onSucceeded']);
        Event::listen(SyncJobFinalFailed::class, [JobLifecycleLog::class, 'onFailed']);
    }

    /**
     * Registered in every process (web, scheduler, queue workers): the rule
     * registry lives in memory only, and a worker that never registered a
     * rule throws UnknownAlertRule on fire/resolve.
     */
    public static function registerRules(): void
    {
        if (! static::available()) {
            return;
        }

        $rules = [
            [
                'key' => self::RULE_PROVISIONING,
                'severity' => 'warning',
                // Re-notify while it keeps failing, but at most every 6 hours.
                'cooldown_minutes' => 360,
                'description' => 'Job provisioning Workspace gagal beberapa kali berturut-turut',
                'subject_template' => '[{severity}] Workspace: {context.job} gagal {context.failures}x berturut-turut: {context.error_short}',
            ],
            [
                'key' => self::RULE_ONLYOFFICE,
                'severity' => 'critical',
                'cooldown_minutes' => 60,
                'description' => 'OnlyOffice Kisara Workspace tidak menjawab',
                'subject_template' => '[{severity}] Workspace: OnlyOffice tidak menjawab sejak {context.down_since} ({context.minutes} menit)',
            ],
            [
                'key' => self::RULE_DISK,
                'severity' => 'warning',
                // A full disk does not change by the hour; daily is enough.
                'cooldown_minutes' => 1440,
                'description' => 'Disk server Kisara Workspace di atas ambang',
                'subject_template' => '[{severity}] Workspace: disk {context.label} {context.percent}% terpakai, sisa {context.free_gb} GB',
            ],
        ];

        foreach ($rules as $rule) {
            static::$alerter::registerOrReplaceRule(
                \Nawasara\Alerting\Models\AlertRule::make($rule + ['category' => 'workspace'])
            );
        }
    }

    public static function schedule(Schedule $schedule): void
    {
        if (config('nawasara-workspace.monitoring.onlyoffice.enabled', true)) {
            $schedule->call(fn () => app(OnlyOfficeHealthMonitor::class)->check())
                ->name('nawasara-workspace:onlyoffice-health')
                ->everyMinute()
                ->withoutOverlapping(2);
        }

        if (config('nawasara-workspace.monitoring.disk.enabled', true)) {
            $schedule->call(fn () => app(DiskUsageMonitor::class)->check())
                ->name('nawasara-workspace:disk-usage')
                ->everyFifteenMinutes()
                ->withoutOverlapping(10);
        }
    }

    /** Fire, never throwing: a broken alert path must not break the job that called it. */
    public static function fire(string $rule, string $targetType, string $targetId, array $context): void
    {
        if (! static::available()) {
            return;
        }

        try {
            static::$alerter::fire($rule, $targetType, $targetId, $context);
        } catch (\Throwable $e) {
            Log::warning('workspace.alert.fire_failed', ['rule' => $rule, 'target' => $targetId, 'error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }

    /** Resolve; idempotent in nawasara/alerting (no state, or already ok, is a no-op). */
    public static function resolve(string $rule, string $targetType, string $targetId): void
    {
        if (! static::available()) {
            return;
        }

        try {
            static::$alerter::resolve($rule, $targetType, $targetId);
        } catch (\Throwable $e) {
            Log::warning('workspace.alert.resolve_failed', ['rule' => $rule, 'target' => $targetId, 'error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }
}

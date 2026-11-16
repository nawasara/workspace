<?php

namespace Nawasara\Workspace\Alerting;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nawasara\Sync\Events\SyncJobFinalFailed;
use Nawasara\Sync\Events\SyncJobSucceeded;
use Nawasara\Sync\Models\SyncJob;

/**
 * "Provisioning gagal 2x berturut" (NFR-07).
 *
 * Counted from the nawasara/sync run history, not from an in-memory counter:
 * runs happen in different worker processes, and a run killed by its timeout
 * never emits SyncJobFinalFailed but still leaves a `failed` row, so the
 * history is the only place where "the last two runs" is true.
 *
 * Only finished runs count (success / failed). A single failure is left to
 * the generic sync alert of nawasara/alerting; this rule is the one that
 * says "it is not a blip".
 *
 * Target id = the job name. Stable for the life of the job, so a third
 * failure re-notifies the same alert (after its cooldown) and the next
 * success resolves it.
 */
class ProvisioningFailureMonitor
{
    public const SERVICE = 'workspace';

    public const TARGET_TYPE = 'WorkspaceJob';

    /** sync action => job name (the alert target). */
    public const JOBS = [
        'sync_accounts' => 'SyncWorkspaceAccountsJob',
        'sync_opd_folders' => 'SyncWorkspaceFoldersJob',
        'sync_pilot_group' => 'SyncPilotGroupJob',
        'sync_offboarding' => 'SyncWorkspaceOffboardingJob',
        'ingest_audit_log' => 'IngestAuditLogJob',
    ];

    public static function onFailed(SyncJobFinalFailed $event): void
    {
        try {
            (new static)->failed($event->tracker, $event->errorMessage);
        } catch (\Throwable $e) {
            Log::warning('workspace.alert.provisioning_check_failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }

    public static function onSucceeded(SyncJobSucceeded $event): void
    {
        try {
            (new static)->succeeded($event->tracker);
        } catch (\Throwable $e) {
            // Never let the alert path fail a sync that just succeeded.
            Log::warning('workspace.alert.provisioning_check_failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }

    public function failed(SyncJob $tracker, string $error = ''): bool
    {
        $job = $this->jobName($tracker);
        if ($job === null) {
            return false;
        }

        $threshold = max(1, (int) config('nawasara-workspace.monitoring.provisioning.consecutive_failures', 2));
        $failures = $this->consecutiveFailures($tracker, $threshold);

        if ($failures < $threshold) {
            return false;
        }

        Log::warning('workspace.alert.provisioning_failed', [
            'job' => $job, 'failures' => $failures, 'sync_job_id' => $tracker->id,
        ]);

        WorkspaceAlerts::fire(WorkspaceAlerts::RULE_PROVISIONING, self::TARGET_TYPE, $job, [
            'job' => $job,
            'action' => $tracker->action,
            'failures' => $failures,
            'sync_job_id' => $tracker->id,
            'error_short' => Str::limit($error !== '' ? $error : (string) $tracker->error, 120, '...'),
        ]);

        return true;
    }

    public function succeeded(SyncJob $tracker): void
    {
        $job = $this->jobName($tracker);
        if ($job !== null) {
            WorkspaceAlerts::resolve(WorkspaceAlerts::RULE_PROVISIONING, self::TARGET_TYPE, $job);
        }
    }

    /**
     * Failed runs in a row, counting back from this run, capped at $limit.
     * Queued / running / skipped / conflict rows are not finished runs.
     */
    public function consecutiveFailures(SyncJob $tracker, int $limit): int
    {
        $statuses = SyncJob::query()
            ->where('service', self::SERVICE)
            ->where('action', $tracker->action)
            ->whereIn('status', [SyncJob::STATUS_SUCCESS, SyncJob::STATUS_FAILED])
            ->where('id', '<=', $tracker->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('status');

        $n = 0;
        foreach ($statuses as $status) {
            if ($status !== SyncJob::STATUS_FAILED) {
                break;
            }
            $n++;
        }

        return $n;
    }

    private function jobName(SyncJob $tracker): ?string
    {
        if ($tracker->service !== self::SERVICE) {
            return null;
        }

        return self::JOBS[$tracker->action] ?? null;
    }
}

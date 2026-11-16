<?php

namespace Nawasara\Workspace\Alerting;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nawasara\Sync\Events\SyncJobFinalFailed;
use Nawasara\Sync\Events\SyncJobSucceeded;
use Nawasara\Sync\Models\SyncJob;

/**
 * Lifecycle log lines for every Workspace job (NFR-06): started, attempt
 * failed, finished (counts + duration), failed. All lines of one attempt
 * share a request id, the queue job uuid, plus the sync_job_id that links
 * the line to the Riwayat Sinkronisasi page.
 *
 * Counts only: the result array of a run is filtered to scalar numbers, so
 * names, NIPs, file paths and tokens never reach the log.
 *
 * The request id is held in a static for the attempt that is running: a
 * queue worker runs one job at a time, and the sync events are dispatched
 * synchronously inside that job's handle().
 */
class JobLifecycleLog
{
    public const JOB_NAMESPACE = 'Nawasara\\Workspace\\Jobs\\';

    public static ?string $requestId = null;

    public static function onProcessing(JobProcessing $event): void
    {
        $name = $event->job->resolveName();
        if (! str_starts_with($name, self::JOB_NAMESPACE)) {
            return;
        }

        static::$requestId = $event->job->uuid() ?: (string) Str::uuid();

        Log::info('workspace.job.started', [
            'request_id' => static::$requestId,
            'job' => class_basename($name),
            'attempt' => $event->job->attempts(),
        ]);
    }

    /**
     * Clear after the attempt, so a sync run outside the queue never borrows
     * an old id. Only for Workspace jobs: a queued listener of another
     * package that runs inline (sync queue) raises JobProcessed in the middle
     * of our job, and clearing on that would blank the id of our own lines.
     */
    public static function onDone(JobProcessed|JobFailed $event): void
    {
        if (str_starts_with($event->job->resolveName(), self::JOB_NAMESPACE)) {
            static::$requestId = null;
        }
    }

    public static function onException(JobExceptionOccurred $event): void
    {
        $name = $event->job->resolveName();
        if (! str_starts_with($name, self::JOB_NAMESPACE)) {
            return;
        }

        // The queue uuid, not the static: on the last attempt JobFailed (which
        // clears the static) is raised before this event.
        Log::warning('workspace.job.attempt_failed', [
            'request_id' => $event->job->uuid() ?: static::$requestId,
            'job' => class_basename($name),
            'attempt' => $event->job->attempts(),
            'error' => mb_substr($event->exception->getMessage(), 0, 300),
        ]);
    }

    public static function onSucceeded(SyncJobSucceeded $event): void
    {
        $t = $event->tracker;
        if ($t->service !== ProvisioningFailureMonitor::SERVICE) {
            return;
        }

        Log::info('workspace.job.finished', [
            'request_id' => static::$requestId,
            'sync_job_id' => $t->id,
            'action' => $t->action,
            'trigger' => $t->trigger_source,
            'duration_ms' => $t->duration_ms,
            'counts' => static::counts($t),
        ]);
    }

    public static function onFailed(SyncJobFinalFailed $event): void
    {
        $t = $event->tracker;
        if ($t->service !== ProvisioningFailureMonitor::SERVICE) {
            return;
        }

        Log::error('workspace.job.failed', [
            'request_id' => static::$requestId,
            'sync_job_id' => $t->id,
            'action' => $t->action,
            'trigger' => $t->trigger_source,
            'attempts' => $t->attempts,
            'duration_ms' => $t->duration_ms,
            'error' => mb_substr($event->errorMessage, 0, 300),
        ]);
    }

    /** @return array<string, int|float|bool> */
    public static function counts(SyncJob $t): array
    {
        return array_filter(
            is_array($t->result) ? $t->result : [],
            fn ($v, $k) => is_string($k) && (is_int($v) || is_float($v) || is_bool($v)),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}

<?php

namespace Nawasara\Workspace\Jobs;

use Illuminate\Support\Facades\Log;
use Nawasara\Sync\Jobs\AbstractSyncJob;
use Nawasara\Workspace\Models\AuditCursor;
use Nawasara\Workspace\Services\Audit\AuditIngestor;
use Nawasara\Workspace\Services\Audit\AuditLogSource;
use Nawasara\Workspace\Services\Audit\AuditSourceFactory;

/**
 * Jejak Akses (T-36, FR-20): read new lines of the Nextcloud admin_audit
 * log into nawasara_workspace_audit_events. Resumable by byte offset,
 * idempotent by line hash (see AuditIngestor).
 */
class IngestAuditLogJob extends AbstractSyncJob
{
    public int $timeout = 600;

    protected function service(): string { return 'workspace'; }

    protected function action(): string { return 'ingest_audit_log'; }

    protected function targetType(): ?string { return 'AuditLog'; }

    protected function targetId(): ?string { return null; }

    protected function execute(): array
    {
        $source = app()->bound(AuditLogSource::class) ? app(AuditLogSource::class) : AuditSourceFactory::make();
        if ($source === null || ! $source->isConfigured()) {
            Log::info('workspace audit: no source configured, nothing to read');

            return ['configured' => false];
        }

        $cfg = (array) config('nawasara-workspace.audit', []);
        $started = microtime(true);
        Log::info('workspace audit: ingest started', ['source' => $source->name(), 'trigger' => $this->triggerSource]);

        try {
            $stats = app(AuditIngestor::class)->run(
                $source,
                (int) ($cfg['max_bytes_per_run'] ?? 32 * 1024 * 1024),
                (int) ($cfg['chunk_bytes'] ?? 1024 * 1024),
            );
        } catch (\Throwable $e) {
            AuditCursor::where('source', $source->name())->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
            Log::error('workspace audit: ingest failed', ['source' => $source->name(), 'error' => $e->getMessage(), 'ms' => (int) ((microtime(true) - $started) * 1000)]);
            throw $e;
        }

        Log::info('workspace audit: ingest finished', $stats + ['source' => $source->name(), 'ms' => (int) ((microtime(true) - $started) * 1000)]);

        return $stats;
    }
}

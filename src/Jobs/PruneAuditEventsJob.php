<?php

namespace Nawasara\Workspace\Jobs;

use Illuminate\Support\Facades\Log;
use Nawasara\Sync\Jobs\AbstractSyncJob;
use Nawasara\Workspace\Services\Audit\AuditIngestor;

/** Jejak Akses is kept one year (FR-20 AC-3); older events are deleted daily. */
class PruneAuditEventsJob extends AbstractSyncJob
{
    public int $timeout = 600;

    protected function service(): string { return 'workspace'; }

    protected function action(): string { return 'prune_audit_events'; }

    protected function targetType(): ?string { return 'AuditEvent'; }

    protected function targetId(): ?string { return null; }

    protected function execute(): array
    {
        $days = (int) config('nawasara-workspace.audit.retention_days', 365);
        $deleted = app(AuditIngestor::class)->prune($days);
        Log::info('workspace audit: pruned', ['deleted' => $deleted, 'retention_days' => $days]);

        return ['deleted' => $deleted, 'retention_days' => $days];
    }
}

<?php

namespace Nawasara\Workspace\Jobs;

use Illuminate\Support\Facades\Log;
use Nawasara\Sync\Jobs\AbstractSyncJob;
use Nawasara\Workspace\Services\NextcloudAdminClient;
use Nawasara\Workspace\Services\OffboardingManager;

/**
 * Departed employees (T-33, FR-18): disable, hold 90 days, warn at H-7,
 * delete. Daily. Run history and counts are kept by nawasara/sync; the
 * lifecycle lines below make a run readable in the log as well.
 */
class SyncWorkspaceOffboardingJob extends AbstractSyncJob
{
    public int $timeout = 900;

    protected function service(): string { return 'workspace'; }

    protected function action(): string { return 'sync_offboarding'; }

    protected function targetType(): ?string { return 'NextcloudUser'; }

    protected function targetId(): ?string { return null; }

    protected function execute(): array
    {
        if (! app(NextcloudAdminClient::class)->isConfigured()) {
            throw new \RuntimeException('Vault group `workspace` belum diisi.');
        }

        $started = microtime(true);
        Log::info('workspace offboarding: run started', ['trigger' => $this->triggerSource]);

        try {
            $stats = app(OffboardingManager::class)->run();
        } catch (\Throwable $e) {
            Log::error('workspace offboarding: run failed', ['error' => $e->getMessage(), 'ms' => (int) ((microtime(true) - $started) * 1000)]);
            throw $e;
        }

        Log::info('workspace offboarding: run finished', $stats + ['ms' => (int) ((microtime(true) - $started) * 1000)]);

        return $stats;
    }
}

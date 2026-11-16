<?php

namespace Nawasara\Workspace\Jobs;

use Nawasara\Sync\Jobs\AbstractSyncJob;
use Nawasara\Workspace\Services\PilotGroupSync;

/**
 * Pilot list → Keycloak group `workspace-pilot` (T-07). Dispatched right after
 * a participant or the kill switch changes (FR-02 AC-3: within a minute) and
 * every 15 minutes as a safety net for anything changed outside Nawasara.
 *
 * Run history, durations and failures are recorded by nawasara/sync
 * (NFR-06); a failure raises the usual sync alert.
 */
class SyncPilotGroupJob extends AbstractSyncJob
{
    public int $timeout = 120;

    protected function service(): string
    {
        return 'workspace';
    }

    protected function action(): string
    {
        return 'sync_pilot_group';
    }

    protected function targetType(): ?string
    {
        return 'KeycloakGroup';
    }

    protected function targetId(): ?string
    {
        return (string) config('nawasara-workspace.keycloak_pilot_group', 'workspace-pilot');
    }

    protected function execute(): array
    {
        return app(PilotGroupSync::class)->run();
    }
}

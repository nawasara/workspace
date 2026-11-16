<?php

namespace Nawasara\Workspace\Jobs;

use Nawasara\Sync\Jobs\AbstractSyncJob;
use Nawasara\Workspace\Services\FolderProvisioner;

/** One team folder per OPD with members (T-12). Queued after each account sync. */
class SyncWorkspaceFoldersJob extends AbstractSyncJob
{
    public int $timeout = 300;

    protected function service(): string { return 'workspace'; }

    protected function action(): string { return 'sync_opd_folders'; }

    protected function targetType(): ?string { return 'NextcloudTeamFolder'; }

    protected function targetId(): ?string { return null; }

    protected function execute(): array
    {
        return app(FolderProvisioner::class)->run();
    }
}

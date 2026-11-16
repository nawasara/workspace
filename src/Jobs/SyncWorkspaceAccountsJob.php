<?php

namespace Nawasara\Workspace\Jobs;

use Nawasara\Sync\Jobs\AbstractSyncJob;
use Nawasara\Workspace\Services\AccountProvisioner;
use Nawasara\Workspace\Services\NextcloudAdminClient;

/**
 * Nextcloud accounts for every active ASN (T-10). Every sync_interval minutes;
 * at most provision_batch writes per run. Ends by queueing the OPD folder
 * sync, since new OPD members may need their OPD's folder. Run history and
 * counts are kept by nawasara/sync (FR-17 AC-4, NFR-06).
 */
class SyncWorkspaceAccountsJob extends AbstractSyncJob
{
    public int $timeout = 900;

    protected function service(): string { return 'workspace'; }

    protected function action(): string { return 'sync_accounts'; }

    protected function targetType(): ?string { return 'NextcloudUser'; }

    protected function targetId(): ?string { return null; }

    protected function execute(): array
    {
        if (! app(NextcloudAdminClient::class)->isConfigured()) {
            throw new \RuntimeException('Vault group `workspace` belum diisi.');
        }

        $stats = app(AccountProvisioner::class)->run((int) config('nawasara-workspace.provision_batch', 1000));
        SyncWorkspaceFoldersJob::dispatch(triggerSource: 'event');

        return $stats;
    }
}

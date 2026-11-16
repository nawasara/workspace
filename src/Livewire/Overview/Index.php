<?php

namespace Nawasara\Workspace\Livewire\Overview;

use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Nawasara\Workspace\Services\NextcloudAdminClient;

/**
 * Ringkasan Kisara Workspace: is Nawasara connected to the Workspace
 * Nextcloud, which version, is the OnlyOffice connector on. Grows with the
 * pilot (participants, accounts, folders) as those tasks land.
 */
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('workspace.view');
    }

    /** Forget the cached status and ask Nextcloud again. */
    public function refresh(): void
    {
        $this->authorize('workspace.view');
        Cache::forget('nawasara-workspace:status');
    }

    /** @return array{configured: bool, ok: bool, version: ?string, onlyoffice: ?bool, error: ?string} */
    protected function status(): array
    {
        $client = app(NextcloudAdminClient::class);

        if (! $client->isConfigured()) {
            return ['configured' => false, 'ok' => false, 'version' => null, 'onlyoffice' => null, 'error' => null];
        }

        // One minute: the page may be open on several screens, and each
        // render would otherwise call Nextcloud.
        return Cache::remember('nawasara-workspace:status', 60, function () use ($client) {
            try {
                $caps = $client->capabilities();

                return ['configured' => true, 'ok' => true, 'version' => $caps['version'], 'onlyoffice' => $caps['onlyoffice'], 'error' => null];
            } catch (\RuntimeException $e) {
                return ['configured' => true, 'ok' => false, 'version' => null, 'onlyoffice' => null, 'error' => $e->getMessage()];
            }
        });
    }

    public function render()
    {
        return view('nawasara-workspace::livewire.pages.overview.index', ['status' => $this->status()])
            ->layout('nawasara-ui::components.layouts.app');
    }
}

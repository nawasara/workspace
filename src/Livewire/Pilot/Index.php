<?php

namespace Nawasara\Workspace\Livewire\Pilot;

use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Nawasara\Workspace\Support\KisaraAccessBridge;

/**
 * Peserta Pilot: page shell only. Controls (kill switch), the table and the
 * add/edit form are their own components (AGENTS.md §1b).
 *
 * With nawasara/asn-portal installed and migrated, the list lives in Akses
 * Layanan Kisara and this page only redirects there (filtered to Workspace),
 * so there are never two editable lists.
 */
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('workspace.view');

        if (app(KisaraAccessBridge::class)->active() && Route::has('nawasara-asn-portal.access.index')) {
            $this->redirect(route('nawasara-asn-portal.access.index', ['app' => KisaraAccessBridge::APP]), navigate: true);
        }
    }

    public function render()
    {
        return view('nawasara-workspace::livewire.pages.pilot.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}

<?php

namespace Nawasara\Workspace\Livewire\Pilot;

use Livewire\Component;

/**
 * Peserta Pilot: page shell only. Controls (kill switch), the table and the
 * add/edit form are their own components (AGENTS.md §1b).
 */
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('workspace.view');
    }

    public function render()
    {
        return view('nawasara-workspace::livewire.pages.pilot.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}

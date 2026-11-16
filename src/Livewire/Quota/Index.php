<?php

namespace Nawasara\Workspace\Livewire\Quota;

use Livewire\Component;

/**
 * Penyimpangan Kuota: page shell only. The table and the add/edit form are
 * their own components (AGENTS.md §1b).
 */
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('workspace.quota.view');
    }

    public function render()
    {
        return view('nawasara-workspace::livewire.pages.quota.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}

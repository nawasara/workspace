<?php

namespace Nawasara\Workspace\Livewire\Audit;

use Livewire\Component;

/** Jejak Akses: page shell only; the list is its own component (AGENTS §1b). */
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('workspace.audit.view');
    }

    public function render()
    {
        return view('nawasara-workspace::livewire.pages.audit.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}

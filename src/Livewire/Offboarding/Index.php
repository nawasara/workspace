<?php

namespace Nawasara\Workspace\Livewire\Offboarding;

use Livewire\Component;

/** Pegawai Keluar: page shell only; the list is its own component (AGENTS §1b). */
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('workspace.offboarding.view');
    }

    public function render()
    {
        return view('nawasara-workspace::livewire.pages.offboarding.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}

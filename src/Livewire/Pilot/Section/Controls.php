<?php

namespace Nawasara\Workspace\Livewire\Pilot\Section;

use Livewire\Component;
use Nawasara\AuthPrimitives\Traits\WithSudo;
use Nawasara\Workspace\Models\PilotMember;
use Nawasara\Workspace\Models\Setting;

/**
 * Pilot status and the kill switch (FR-02 AC-4).
 *
 * Kill switch on = nobody enters Workspace, whatever the participant list
 * says. Files are untouched; switching it off again restores access. Both
 * doors pick it up within a minute (Setting cache 30 s, Workspace Web 60 s).
 */
class Controls extends Component
{
    use WithSudo;

    public function toggleKillSwitch(): void
    {
        $this->authorize('workspace.pilot.manage');

        $turningOn = ! Setting::flag(Setting::KILL_SWITCH);

        // Turning Workspace off for everyone is a destructive action (AGENTS
        // §6). Turning it back on is not, so only that direction needs sudo.
        if ($turningOn && ! $this->requireSudo('mematikan Kisara Workspace untuk semua pegawai')) {
            return;
        }

        Setting::setFlag(Setting::KILL_SWITCH, $turningOn, auth()->id());

        activity('workspace')
            ->causedBy(auth()->user())
            ->withProperties(['kill_switch' => $turningOn])
            ->log($turningOn ? 'Kill switch Workspace dinyalakan' : 'Kill switch Workspace dimatikan');

        $this->dispatch('toast', ['type' => $turningOn ? 'warning' : 'success', 'message' => $turningOn
            ? 'Kisara Workspace dimatikan untuk semua pegawai. Berkas tidak tersentuh.'
            : 'Kisara Workspace dibuka kembali untuk peserta pilot.']);
    }

    public function render()
    {
        return view('nawasara-workspace::livewire.pages.pilot.section.controls', [
            'killSwitch' => Setting::flag(Setting::KILL_SWITCH),
            'pilotOpen' => Setting::flag(Setting::PILOT_OPEN),
            'activeCount' => PilotMember::active()->count(),
        ]);
    }
}

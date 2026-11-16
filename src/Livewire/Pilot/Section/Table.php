<?php

namespace Nawasara\Workspace\Livewire\Pilot\Section;

use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nawasara\AuthPrimitives\Traits\WithSudo;
use Nawasara\Workspace\Models\PilotMember;

/**
 * Pilot participant list (AGENTS §1a): filter + search toolbar, three-dot row
 * actions, two empty states, and both "Cabut" (revoke, kept in history) and
 * "Hapus" (wrong entry).
 */
class Table extends Component
{
    use WithPagination, WithSudo;

    #[Url]
    public string $search = '';

    #[Url]
    public string $stageFilter = '';

    #[Url]
    public string $statusFilter = 'active';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'stageFilter', 'statusFilter'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function stageOptions(): array
    {
        return collect(PilotMember::STAGES)->mapWithKeys(fn ($label, $k) => [(string) $k => $label])->all();
    }

    #[Computed]
    public function statusOptions(): array
    {
        return ['active' => 'Aktif', 'removed' => 'Dicabut'];
    }

    #[Computed]
    public function rows()
    {
        return PilotMember::query()
            ->with('opd:id,name')
            ->when($this->statusFilter === 'active', fn ($q) => $q->whereNull('removed_at'))
            ->when($this->statusFilter === 'removed', fn ($q) => $q->whereNotNull('removed_at'))
            ->when($this->stageFilter !== '', fn ($q) => $q->where('stage', (int) $this->stageFilter))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->where('nip', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->orderBy('stage')->orderBy('name')
            ->paginate(25);
    }

    #[Computed]
    public function totalActive(): int
    {
        return PilotMember::active()->count();
    }

    /** Opens the shared form in edit mode. */
    public function edit(int $id): void
    {
        $this->authorize('workspace.pilot.manage');
        $this->dispatch('workspace-pilot-edit', id: $id);
    }

    /** Revoke: the person can no longer enter; row, history and files stay. */
    public function revoke(int $id): void
    {
        $this->authorize('workspace.pilot.manage');
        PilotMember::findOrFail($id)->update(['removed_at' => now(), 'removed_by' => auth()->id()]);
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Akses pilot dicabut. Berkasnya tidak tersentuh.']);
    }

    public function restore(int $id): void
    {
        $this->authorize('workspace.pilot.manage');
        PilotMember::findOrFail($id)->update(['removed_at' => null, 'removed_by' => null]);
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Peserta diaktifkan kembali.']);
    }

    /** Delete a wrong entry. Sudo-gated (AGENTS §6). */
    public function delete(int $id): void
    {
        $this->authorize('workspace.pilot.manage');

        if (! $this->requireSudo('menghapus peserta pilot')) {
            return;
        }

        PilotMember::findOrFail($id)->delete();
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Peserta dihapus dari daftar.']);
    }

    #[On('workspace-pilot-saved')]
    public function refreshRows(): void
    {
        unset($this->rows, $this->totalActive);
    }

    public function render()
    {
        return view('nawasara-workspace::livewire.pages.pilot.section.table');
    }
}

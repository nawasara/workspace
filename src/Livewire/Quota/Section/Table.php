<?php

namespace Nawasara\Workspace\Livewire\Quota\Section;

use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nawasara\AuthPrimitives\Traits\WithSudo;
use Nawasara\Workspace\Models\QuotaOverride;

/**
 * Quota exceptions list (AGENTS §1a): filter + search toolbar, three-dot row
 * actions, two empty states.
 *
 * No "nonaktifkan" next to "Hapus": an exception that stops applying is
 * simply removed, the default comes back on the next sync, and the activity
 * log keeps what it was, why and who removed it. A disabled row would only
 * be a second way of saying "default".
 */
class Table extends Component
{
    use WithPagination, WithSudo;

    #[Url]
    public string $search = '';

    #[Url]
    public string $typeFilter = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'typeFilter'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function typeOptions(): array
    {
        return QuotaOverride::TYPES;
    }

    #[Computed]
    public function defaults(): array
    {
        return [
            QuotaOverride::TYPE_EMPLOYEE => QuotaOverride::defaultFor(QuotaOverride::TYPE_EMPLOYEE),
            QuotaOverride::TYPE_OPD => QuotaOverride::defaultFor(QuotaOverride::TYPE_OPD),
        ];
    }

    #[Computed]
    public function rows()
    {
        return QuotaOverride::query()
            ->with(['opd:id,name', 'updater:id,name'])
            ->when(array_key_exists($this->typeFilter, QuotaOverride::TYPES), fn ($q) => $q->where('target_type', $this->typeFilter))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->where('nip', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->orderBy('target_type')->orderBy('name')
            ->paginate(25);
    }

    #[Computed]
    public function total(): int
    {
        return QuotaOverride::count();
    }

    /** Opens the shared form in edit mode. */
    public function edit(int $id): void
    {
        $this->authorize('workspace.quota.manage');
        $this->dispatch('workspace-quota-edit', id: $id);
    }

    /** Remove an exception: the default applies again on the next sync. Sudo-gated (AGENTS §6). */
    public function delete(int $id): void
    {
        $this->authorize('workspace.quota.manage');

        if (! $this->requireSudo('menghapus penyimpangan kuota')) {
            return;
        }

        QuotaOverride::findOrFail($id)->delete();
        unset($this->rows, $this->total);
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Penyimpangan dihapus. Kuota kembali ke bawaan pada sinkron berikutnya.']);
    }

    #[On('workspace-quota-saved')]
    public function refreshRows(): void
    {
        unset($this->rows, $this->total);
    }

    public function render()
    {
        return view('nawasara-workspace::livewire.pages.quota.section.table');
    }
}

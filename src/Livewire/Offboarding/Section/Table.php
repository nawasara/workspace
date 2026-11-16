<?php

namespace Nawasara\Workspace\Livewire\Offboarding\Section;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nawasara\AuthPrimitives\Attributes\RequiresSudo;
use Nawasara\AuthPrimitives\Traits\WithSudo;
use Nawasara\Workspace\Models\Offboarding;
use Nawasara\Workspace\Services\NextcloudException;
use Nawasara\Workspace\Services\OffboardingManager;

/**
 * Antrean Pegawai Keluar (T-33, FR-18), list page per AGENTS §1a: filter
 * by status, search, three-dot row actions, two empty states.
 *
 * Rows are never removed from here: a departure is a record (who moved the
 * files where, who deleted the account). "Hapus sekarang" deletes the
 * Nextcloud account and Drive Saya, not the row; "Batalkan" is the
 * counterpart that stops the deletion.
 */
class Table extends Component
{
    use WithPagination, WithSudo;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function statusOptions(): array
    {
        return Offboarding::STATUSES;
    }

    #[Computed]
    public function rows()
    {
        return Offboarding::query()
            ->with(['opd:id,name', 'mover:id,name', 'deleter:id,name', 'canceller:id,name'])
            ->when(array_key_exists($this->statusFilter, Offboarding::STATUSES), fn ($q) => $q->where('status', $this->statusFilter))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->where('nip', 'like', $term)->orWhere('display_name', 'like', $term));
            })
            // Held first, nearest deadline first: what needs attention on top.
            ->orderByRaw("case when status = 'held' then 0 else 1 end")
            ->orderBy('hold_until')
            ->paginate(25);
    }

    #[Computed]
    public function heldCount(): int
    {
        return Offboarding::held()->count();
    }

    #[Computed]
    public function holdDays(): int
    {
        return (int) config('nawasara-workspace.offboarding.hold_days', 90);
    }

    /** Move Drive Saya into the employee's Folder OPD; who/when/where is recorded (AC-3). */
    public function moveToOpd(int $id): void
    {
        $this->authorize('workspace.offboarding.manage');

        $this->act($id, function (OffboardingManager $m, Offboarding $row) {
            $r = $m->moveToOpdFolder($row, (int) auth()->id());

            return $r['failed']
                ? ['warning', "{$r['moved']} item dipindah ke {$r['to']}. ".count($r['failed']).' item tidak terpindah karena namanya sudah ada.']
                : ['success', "{$r['moved']} item dipindah ke {$r['to']}."];
        });
    }

    /** Stop the deletion; the account stays disabled while Keycloak's is (AC-5 by hand). */
    public function cancel(int $id): void
    {
        $this->authorize('workspace.offboarding.manage');

        $this->act($id, function (OffboardingManager $m, Offboarding $row) {
            $m->cancel($row, (int) auth()->id());

            return ['success', 'Penghapusan dibatalkan. Akun tetap nonaktif selama akun Kisara-nya nonaktif.'];
        });
    }

    /**
     * Delete the account and Drive Saya now, without waiting for the
     * deadline. Folder OPD is not touched. Sudo-gated (AGENTS §6); the
     * imperative guard as well, in case the method is ever reached as an
     * event listener, where the attribute does not run.
     */
    #[RequiresSudo(reason: 'menghapus akun dan Drive Saya pegawai keluar')]
    public function deleteNow(int $id): void
    {
        $this->authorize('workspace.offboarding.manage');

        if (! $this->requireSudo('menghapus akun dan Drive Saya pegawai keluar')) {
            return;
        }

        $this->act($id, function (OffboardingManager $m, Offboarding $row) {
            $m->delete($row, (int) auth()->id());

            return ['success', 'Akun dan Drive Saya dihapus. Folder OPD tidak tersentuh.'];
        });
    }

    private function act(int $id, callable $fn): void
    {
        $row = Offboarding::findOrFail($id);

        try {
            [$type, $message] = $fn(app(OffboardingManager::class), $row);
        } catch (\DomainException $e) {
            [$type, $message] = ['error', $e->getMessage()];
        } catch (NextcloudException $e) {
            $row->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
            [$type, $message] = ['error', 'Nextcloud gagal: '.$e->getMessage()];
        }

        unset($this->rows, $this->heldCount);
        $this->dispatch('toast', ['type' => $type, 'message' => $message]);
    }

    public function render()
    {
        return view('nawasara-workspace::livewire.pages.offboarding.section.table');
    }
}

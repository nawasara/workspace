<?php

namespace Nawasara\Workspace\Livewire\Audit\Section;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nawasara\Workspace\Models\Account;
use Nawasara\Workspace\Models\AuditCursor;
use Nawasara\Workspace\Models\AuditEvent;

/**
 * Jejak Akses (T-36, FR-20): who did what to which file, from the
 * Nextcloud admin_audit log. List page per AGENTS §1a.
 *
 * Read-only on purpose: an audit trail that an admin can edit or delete
 * from proves nothing. Rows leave only by the one-year prune (AC-3). The
 * row menu only narrows the list to that file or that employee (AC-2).
 */
class Table extends Component
{
    use WithPagination;

    public const SCOPES = ['pegawai' => 'Pegawai (NIP atau nama)', 'berkas' => 'Berkas (nama, jalur, atau id)'];

    #[Url]
    public string $search = '';

    #[Url]
    public string $scope = '';

    #[Url]
    public string $actionFilter = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public function mount(): void
    {
        $this->authorize('workspace.audit.view');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'scope', 'actionFilter', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function actionOptions(): array
    {
        return AuditEvent::ACTIONS;
    }

    #[Computed]
    public function scopeOptions(): array
    {
        return self::SCOPES;
    }

    public function isFiltered(): bool
    {
        return trim($this->search) !== '' || $this->actionFilter !== '' || $this->dateFrom !== '' || $this->dateTo !== '';
    }

    #[Computed]
    public function rows()
    {
        $query = AuditEvent::query()->with('account:nip,display_name');

        if (array_key_exists($this->actionFilter, AuditEvent::ACTIONS)) {
            $query->where('action', $this->actionFilter);
        }

        // Dates are typed in WIB; occurred_at is stored in UTC.
        if ($from = $this->day($this->dateFrom)) {
            $query->where('occurred_at', '>=', $from->startOfDay()->utc());
        }
        if ($to = $this->day($this->dateTo)) {
            $query->where('occurred_at', '<=', $to->endOfDay()->utc());
        }

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(function (Builder $w) use ($term) {
                if ($this->scope !== 'berkas') {
                    $this->whereEmployee($w, $term);
                }
                if ($this->scope !== 'pegawai') {
                    $w->orWhere(fn (Builder $f) => $this->whereFile($f, $term));
                }
            });
        }

        return $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate(50);
    }

    /** NIP exact or prefix, or a name from the accounts Nawasara provisioned. */
    private function whereEmployee(Builder $w, string $term): void
    {
        $w->orWhere('nip', 'like', $term.'%');

        if (! ctype_digit($term)) {
            $nips = Account::where('display_name', 'like', '%'.$term.'%')->limit(200)->pluck('nip');
            if ($nips->isNotEmpty()) {
                $w->orWhereIn('nip', $nips);
            }
        }
    }

    /** File id exact (as shown in Nextcloud), or name / path contains. */
    private function whereFile(Builder $f, string $term): void
    {
        if (ctype_digit($term) && strlen($term) < 16) {
            $f->where('file_id', (int) $term);

            return;
        }

        $f->where('file_name', 'like', '%'.$term.'%')->orWhere('file_path', 'like', '%'.$term.'%');
    }

    private function day(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value, 'Asia/Jakarta');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Last successful read of the log, for the line under the header. */
    #[Computed]
    public function lastRead(): ?AuditCursor
    {
        return AuditCursor::orderByDesc('last_read_at')->first();
    }

    /** Row menu: show everything about this file (AC-2). */
    public function traceFile(int $id): void
    {
        $row = AuditEvent::findOrFail($id);
        $this->scope = 'berkas';
        $this->search = $row->file_id !== null ? (string) $row->file_id : (string) $row->file_name;
        $this->actionFilter = '';
        $this->resetPage();
    }

    /** Row menu: show everything this employee did (AC-2). */
    public function traceEmployee(int $id): void
    {
        $row = AuditEvent::findOrFail($id);
        $this->scope = 'pegawai';
        $this->search = (string) $row->nip;
        $this->actionFilter = '';
        $this->resetPage();
    }

    public function render()
    {
        return view('nawasara-workspace::livewire.pages.audit.section.table');
    }
}

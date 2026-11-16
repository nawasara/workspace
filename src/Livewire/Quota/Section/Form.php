<?php

namespace Nawasara\Workspace\Livewire\Quota\Section;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Nawasara\Keycloak\Concerns\SearchesKeycloakDirectory;
use Nawasara\Registry\Models\Opd;
use Nawasara\Workspace\Models\QuotaOverride;
use Nawasara\Workspace\Support\QuotaSize;

/**
 * Add / edit a quota exception for one employee or one OPD folder.
 *
 * The employee is picked from the Keycloak directory by index (same as the
 * pilot form), never typed: a NIP typed by hand can belong to nobody. The
 * target cannot change in edit mode; a different target is a different row.
 * A reason is required: an exception nobody can explain is the first thing
 * someone "cleans up" later.
 */
class Form extends Component
{
    use SearchesKeycloakDirectory;

    public const UNITS = ['GB' => 'GB', 'MB' => 'MB'];

    /** Upper bound per row: well above anything Workspace storage can hold. */
    private const MAX_BYTES = 5 * 1024 ** 4;

    #[Locked]
    public ?int $editingId = null;

    public string $targetType = QuotaOverride::TYPE_EMPLOYEE;

    public string $userSearch = '';

    /** Chosen employee, shown before saving. */
    #[Locked]
    public ?array $picked = null;

    public ?int $opdId = null;

    public ?int $size = null;

    public string $unit = 'GB';

    public ?string $reason = null;

    #[Computed]
    public function typeOptions(): array
    {
        return QuotaOverride::TYPES;
    }

    #[Computed]
    public function unitOptions(): array
    {
        return self::UNITS;
    }

    /** OPDs that do not have an exception yet (plus the one being edited). */
    #[Computed]
    public function opdOptions(): array
    {
        $taken = QuotaOverride::opds()->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))->pluck('opd_id');

        return Opd::whereNotIn('id', $taken)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Directory results, without people who already have an exception. */
    #[Computed]
    public function userResults(): array
    {
        return $this->keycloakSearchResults($this->userSearch, $this->existingNips());
    }

    #[On('workspace-quota-create')]
    public function create(): void
    {
        $this->authorize('workspace.quota.manage');
        $this->resetForm();
        $this->dispatch('modal-open:workspace-quota-form');
    }

    #[On('workspace-quota-edit')]
    public function edit(int $id): void
    {
        $this->authorize('workspace.quota.manage');
        $this->resetForm();

        $o = QuotaOverride::findOrFail($id);
        $this->editingId = $o->id;
        $this->targetType = $o->target_type;
        $this->picked = $o->target_type === QuotaOverride::TYPE_EMPLOYEE ? ['nip' => $o->nip, 'name' => $o->name] : null;
        $this->opdId = $o->opd_id;
        // Back to the form's units: whole GB when it divides evenly, else MB.
        $gb = 1024 ** 3;
        $this->unit = $o->quota_bytes % $gb === 0 ? 'GB' : 'MB';
        $this->size = intdiv($o->quota_bytes, $this->unit === 'GB' ? $gb : 1024 ** 2);
        $this->reason = $o->reason;
        $this->dispatch('modal-open:workspace-quota-form');
    }

    public function pick(int $index): void
    {
        $this->authorize('workspace.quota.manage');

        $kc = $this->keycloakUserAt($index, $this->userSearch, $this->existingNips());
        $nip = (string) ($kc?->nip ?: $kc?->username);

        if (! $kc || preg_match('/^\d{18}$/', $nip) !== 1) {
            $this->dispatch('toast', ['type' => 'error', 'message' => 'Hanya akun ASN ber-NIP yang punya Drive Kisara.']);

            return;
        }

        $this->picked = ['nip' => $nip, 'name' => $kc->full_name ?: $nip];
        $this->userSearch = '';
    }

    public function unpick(): void
    {
        if ($this->editingId === null) {
            $this->picked = null;
        }
    }

    public function save(): void
    {
        $this->authorize('workspace.quota.manage');

        $this->validate([
            'targetType' => ['required', 'in:'.implode(',', array_keys(QuotaOverride::TYPES))],
            'opdId' => [$this->targetType === QuotaOverride::TYPE_OPD ? 'required' : 'nullable', 'integer', 'exists:nawasara_registry_opd,id'],
            'size' => ['required', 'integer', 'min:1'],
            'unit' => ['required', 'in:'.implode(',', array_keys(self::UNITS))],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['targetType' => 'jenis', 'opdId' => 'OPD', 'size' => 'kuota', 'unit' => 'satuan', 'reason' => 'alasan']);

        $bytes = QuotaSize::bytes($this->size.' '.$this->unit);
        if ($bytes > self::MAX_BYTES) {
            $this->addError('size', 'Kuota terlalu besar (maks. 5 TB).');

            return;
        }

        $isOpd = $this->targetType === QuotaOverride::TYPE_OPD;
        if (! $isOpd && ! $this->picked) {
            $this->addError('picked', 'Pilih pegawainya dulu.');

            return;
        }

        $data = ['quota_bytes' => $bytes, 'reason' => trim($this->reason), 'updated_by' => auth()->id()];

        if ($this->editingId) {
            $row = QuotaOverride::findOrFail($this->editingId);
            if ($row->target_type === QuotaOverride::TYPE_OPD) {
                $data['name'] = (string) Opd::whereKey($row->opd_id)->value('name');
            }
            $row->update($data);
            $message = 'Penyimpangan diperbarui. Diterapkan pada sinkron berikutnya.';
        } else {
            $target = $isOpd
                ? ['target_type' => QuotaOverride::TYPE_OPD, 'opd_id' => $this->opdId, 'nip' => null, 'name' => (string) Opd::whereKey($this->opdId)->value('name')]
                : ['target_type' => QuotaOverride::TYPE_EMPLOYEE, 'nip' => $this->picked['nip'], 'opd_id' => null, 'name' => $this->picked['name']];

            // Fail-safe on top of the filtered choices: another admin may have
            // added the same target between opening the form and saving.
            $exists = $isOpd
                ? QuotaOverride::where('opd_id', $this->opdId)->exists()
                : QuotaOverride::where('nip', $this->picked['nip'])->exists();
            if ($exists) {
                $this->addError($isOpd ? 'opdId' : 'picked', 'Sudah ada penyimpangan untuk sasaran ini. Ubah yang ada.');

                return;
            }

            QuotaOverride::create($target + $data + ['created_by' => auth()->id()]);
            $message = 'Penyimpangan ditambahkan. Diterapkan pada sinkron berikutnya.';
        }

        $this->dispatch('modal-close:workspace-quota-form');
        $this->dispatch('workspace-quota-saved');
        $this->dispatch('toast', ['type' => 'success', 'message' => $message]);
        $this->resetForm();
    }

    /** @return array<int,string> lowercase NIPs that already have an exception */
    private function existingNips(): array
    {
        return QuotaOverride::employees()->pluck('nip')->map(fn ($n) => mb_strtolower($n))->all();
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'targetType', 'userSearch', 'picked', 'opdId', 'size', 'unit', 'reason']);
        $this->resetValidation();
    }

    public function render()
    {
        return view('nawasara-workspace::livewire.pages.quota.section.form');
    }
}

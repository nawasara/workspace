<?php

namespace Nawasara\Workspace\Livewire\Pilot\Section;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Nawasara\Keycloak\Concerns\SearchesKeycloakDirectory;
use Nawasara\Registry\Models\Opd;
use Nawasara\Workspace\Models\PilotMember;

/**
 * Add / edit a pilot participant.
 *
 * The person is picked from the Keycloak directory, never typed: a NIP typed
 * by hand can belong to nobody, or to someone else. Picking is by index into
 * results recomputed on the server (SearchesKeycloakDirectory), so the DOM is
 * never trusted with an identity.
 */
class Form extends Component
{
    use SearchesKeycloakDirectory;

    #[Locked]
    public ?int $editingId = null;

    public string $userSearch = '';

    /** Chosen person (add mode), shown before saving. */
    #[Locked]
    public ?array $picked = null;

    public int $stage = 1;

    public ?int $opdId = null;

    public ?string $note = null;

    #[Computed]
    public function stageOptions(): array
    {
        return PilotMember::STAGES;
    }

    #[Computed]
    public function opdOptions(): array
    {
        return Opd::orderBy('name')->pluck('name', 'id')->all();
    }

    /** Directory results, without people who are already participants. */
    #[Computed]
    public function userResults(): array
    {
        return $this->keycloakSearchResults($this->userSearch, $this->existingNips());
    }

    #[On('workspace-pilot-create')]
    public function create(): void
    {
        $this->authorize('workspace.pilot.manage');
        $this->resetForm();
        $this->dispatch('modal-open:workspace-pilot-form');
    }

    #[On('workspace-pilot-edit')]
    public function edit(int $id): void
    {
        $this->authorize('workspace.pilot.manage');
        $this->resetForm();

        $m = PilotMember::findOrFail($id);
        $this->editingId = $m->id;
        $this->picked = ['nip' => $m->nip, 'name' => $m->name, 'kc_id' => $m->keycloak_id];
        $this->stage = $m->stage;
        $this->opdId = $m->opd_id;
        $this->note = $m->note;
        $this->dispatch('modal-open:workspace-pilot-form');
    }

    public function pick(int $index): void
    {
        $this->authorize('workspace.pilot.manage');

        $kc = $this->keycloakUserAt($index, $this->userSearch, $this->existingNips());
        $nip = (string) ($kc?->nip ?: $kc?->username);

        if (! $kc || preg_match('/^\d{18}$/', $nip) !== 1) {
            $this->dispatch('toast', ['type' => 'error', 'message' => 'Hanya akun ASN ber-NIP yang dapat menjadi peserta.']);

            return;
        }

        $this->picked = ['nip' => $nip, 'name' => $kc->full_name ?: $nip, 'kc_id' => $kc->user_id];
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
        $this->authorize('workspace.pilot.manage');

        $this->validate([
            'stage' => ['required', 'integer', 'in:'.implode(',', array_keys(PilotMember::STAGES))],
            'opdId' => ['nullable', 'integer', 'exists:nawasara_registry_opd,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['stage' => 'tahap', 'opdId' => 'OPD', 'note' => 'catatan']);

        if (! $this->picked) {
            $this->addError('picked', 'Pilih pegawainya dulu.');

            return;
        }

        $data = ['stage' => $this->stage, 'opd_id' => $this->opdId, 'note' => $this->note ?: null];

        if ($this->editingId) {
            PilotMember::findOrFail($this->editingId)->update($data);
            $message = 'Peserta diperbarui.';
        } else {
            // Fail-safe on top of the exclude list: the same NIP may have been
            // added by another admin between search and click.
            if (PilotMember::where('nip', $this->picked['nip'])->exists()) {
                $this->addError('picked', 'Pegawai ini sudah ada di daftar peserta.');

                return;
            }

            PilotMember::create($data + [
                'nip' => $this->picked['nip'],
                'name' => $this->picked['name'],
                'keycloak_id' => $this->picked['kc_id'],
                'added_by' => auth()->id(),
            ]);
            $message = 'Peserta ditambahkan. Akses aktif paling lambat 1 menit.';
        }

        $this->dispatch('modal-close:workspace-pilot-form');
        $this->dispatch('workspace-pilot-saved');
        $this->dispatch('toast', ['type' => 'success', 'message' => $message]);
        $this->resetForm();
    }

    /** @return array<int,string> lowercase NIPs already in the list */
    private function existingNips(): array
    {
        return PilotMember::pluck('nip')->map(fn ($n) => mb_strtolower($n))->all();
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'userSearch', 'picked', 'stage', 'opdId', 'note']);
        $this->resetValidation();
    }

    public function render()
    {
        return view('nawasara-workspace::livewire.pages.pilot.section.form');
    }
}

<div>
    <x-nawasara-ui::modal id="workspace-pilot-form" :title="$editingId ? 'Ubah Peserta Pilot' : 'Tambah Peserta Pilot'" maxWidth="lg">
        <div class="space-y-4">
            {{-- 1. Pegawai: dipilih dari direktori Keycloak, tidak diketik. --}}
            <div>
                <x-nawasara-ui::form.label value="Pegawai" />
                @if ($picked)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2.5 dark:border-neutral-700">
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-neutral-800 dark:text-neutral-100">{{ $picked['name'] }}</span>
                            <span class="block font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $picked['nip'] }}</span>
                        </span>
                        @unless ($editingId)
                            <x-nawasara-ui::button color="neutral" size="sm" wire:click="unpick">Ganti</x-nawasara-ui::button>
                        @endunless
                    </div>
                @else
                    <x-nawasara-ui::form.input
                        wire:model.live.debounce.400ms="userSearch"
                        placeholder="Ketik nama atau NIP (min. 2 huruf)..."
                        autocomplete="off" />
                    <div class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-gray-200 dark:border-neutral-700">
                        @forelse ($this->userResults as $idx => $u)
                            <button type="button" wire:key="kc-{{ $u['kc_id'] }}" wire:click="pick({{ $idx }})"
                                class="flex w-full items-center justify-between gap-3 border-b border-gray-100 px-3 py-2.5 text-left last:border-0 hover:bg-sky-50 dark:border-neutral-800 dark:hover:bg-sky-900/20">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-neutral-800 dark:text-neutral-100">{{ $u['name'] }}</span>
                                    <span class="block truncate text-xs text-neutral-500 dark:text-neutral-400">{{ $u['nip'] ? 'NIP '.$u['nip'] : $u['kc_username'] }}</span>
                                </span>
                                <span class="shrink-0 text-xs font-medium text-sky-600 dark:text-sky-400">Pilih</span>
                            </button>
                        @empty
                            <div class="px-3 py-5 text-center text-sm text-neutral-500 dark:text-neutral-400">
                                @if (mb_strlen(trim($userSearch)) < 2)
                                    Ketik minimal 2 huruf untuk mencari di direktori pegawai.
                                @else
                                    Tidak ada yang cocok, atau sudah menjadi peserta.
                                @endif
                            </div>
                        @endforelse
                    </div>
                @endif
                @error('picked') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            {{-- 2. Tahap dan OPD. --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-nawasara-ui::form.select label="Tahap" wire:model="stage" :options="$this->stageOptions" />
                    @error('stage') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-nawasara-ui::form.select label="OPD (opsional)" wire:model="opdId"
                        placeholder="Menurut SIMAS Hebat" :options="$this->opdOptions" />
                    @error('opdId') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="-mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                Biarkan OPD kosong supaya mengikuti SIMAS Hebat. Isi hanya bila data SIMAS belum benar.
            </p>

            <div>
                <x-nawasara-ui::form.textarea label="Catatan (opsional)" wire:model="note" :rows="2"
                    placeholder="Mis. penguji fitur berbagi Folder OPD." />
                @error('note') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" x-on:click="$dispatch('close-modal', 'workspace-pilot-form')">Batal</x-nawasara-ui::button>
            <x-nawasara-ui::button color="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">Simpan</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>
</div>

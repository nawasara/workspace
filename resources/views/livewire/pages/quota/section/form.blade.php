<div>
    <x-nawasara-ui::modal id="workspace-quota-form" :title="$editingId ? 'Ubah Penyimpangan Kuota' : 'Tambah Penyimpangan Kuota'" maxWidth="lg">
        <div class="space-y-4">
            @unless ($editingId)
                <div>
                    <x-nawasara-ui::form.select label="Jenis" wire:model.live="targetType" :options="$this->typeOptions" />
                    @error('targetType') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            @endunless

            @if ($targetType === 'opd')
                <div>
                    @if ($editingId)
                        <x-nawasara-ui::form.label value="Folder OPD" />
                        <div class="rounded-lg border border-gray-200 px-3 py-2.5 text-sm font-medium text-neutral-800 dark:border-neutral-700 dark:text-neutral-100">
                            {{ $this->opdOptions[$opdId] ?? '-' }}
                        </div>
                    @else
                        <x-nawasara-ui::form.select label="Folder OPD" wire:model="opdId"
                            placeholder="Pilih OPD" :options="$this->opdOptions" />
                    @endif
                    @error('opdId') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            @else
                {{-- Pegawai: dipilih dari direktori Keycloak, tidak diketik. --}}
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
                                        Tidak ada yang cocok, atau sudah punya penyimpangan.
                                    @endif
                                </div>
                            @endforelse
                        </div>
                    @endif
                    @error('picked') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-nawasara-ui::form.input type="number" min="1" label="Kuota" wire:model="size" placeholder="Mis. 10" />
                    @error('size') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-nawasara-ui::form.select label="Satuan" wire:model="unit" :options="$this->unitOptions" />
                    @error('unit') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <x-nawasara-ui::form.textarea label="Alasan" wire:model="reason" :rows="2"
                    placeholder="Mis. operator arsip foto kegiatan, butuh ruang lebih." />
                @error('reason') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" x-on:click="$dispatch('close-modal', 'workspace-quota-form')">Batal</x-nawasara-ui::button>
            <x-nawasara-ui::button color="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">Simpan</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>
</div>

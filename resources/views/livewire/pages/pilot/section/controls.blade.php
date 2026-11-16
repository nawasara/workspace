<div class="mb-6">
    @if ($killSwitch)
        <div class="flex flex-col gap-3 rounded-lg border border-red-300 bg-red-50 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-red-700/50 dark:bg-red-900/30">
            <div>
                <p class="text-sm font-semibold text-red-800 dark:text-red-200">Kisara Workspace sedang DIMATIKAN untuk semua pegawai</p>
                <p class="text-sm text-red-700 dark:text-red-300">Tidak ada yang dapat masuk, termasuk peserta pilot. Berkas tidak tersentuh.</p>
            </div>
            @can('workspace.pilot.manage')
                <x-nawasara-ui::button color="success" wire:click="toggleKillSwitch">Buka kembali</x-nawasara-ui::button>
            @endcan
        </div>
    @else
        <div class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between dark:border-neutral-700 dark:bg-neutral-800">
            <div>
                <p class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                    @if ($pilotOpen)
                        Terbuka untuk semua ASN (tahap 3)
                    @else
                        Pilot berjalan: {{ $activeCount }} peserta aktif
                    @endif
                </p>
                <p class="text-sm text-neutral-600 dark:text-neutral-400">
                    Hanya peserta aktif yang dapat masuk. Perubahan berlaku paling lambat 1 menit.
                </p>
            </div>
            @can('workspace.pilot.manage')
                <x-nawasara-ui::button color="danger" wire:click="toggleKillSwitch"
                    wire:confirm="Matikan Kisara Workspace untuk SEMUA pegawai? Tidak ada yang bisa masuk sampai dibuka kembali. Berkas tidak dihapus.">
                    Matikan untuk semua
                </x-nawasara-ui::button>
            @endcan
        </div>
    @endif
</div>

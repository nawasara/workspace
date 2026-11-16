<div>
    <x-nawasara-ui::page-header
        title="Pegawai Keluar"
        description="Akun Kisara yang dinonaktifkan: akun Workspace-nya ikut nonaktif dan Drive Saya ditahan {{ $this->holdDays }} hari, lalu dihapus. Folder OPD tidak pernah tersentuh."
        :count="$this->heldCount" />

    <div class="space-y-2 mb-4">
        <div class="flex flex-col md:flex-row md:flex-nowrap md:items-center gap-2">
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <x-nawasara-ui::filter-panel label="Filter"
                    :state="['statusFilter' => $statusFilter]"
                    :labels="['statusFilter' => $this->statusOptions]">
                    <x-nawasara-ui::filter-group label="Status" model="statusFilter"
                        :items="$this->statusOptions" icon="lucide-list-filter" />
                </x-nawasara-ui::filter-panel>
            </div>

            <x-nawasara-ui::search-input model="search" placeholder="Cari nama atau NIP..." />
        </div>

        <div data-filter-chips class="flex flex-wrap items-center gap-2"></div>
    </div>

    @if ($this->rows->total() === 0)
        @if ($search !== '' || $statusFilter !== '')
            <x-nawasara-ui::empty-state icon="lucide-search-x"
                title="Tidak ada yang cocok"
                description="Ubah kata kunci atau saringannya." />
        @else
            <x-nawasara-ui::empty-state icon="lucide-user-x"
                title="Belum ada pegawai keluar"
                description="Begitu akun Kisara seorang pegawai dinonaktifkan, sinkron harian menonaktifkan akun Workspace-nya dan mencantumkannya di sini." />
        @endif
    @else
        <x-nawasara-ui::table :headers="['Pegawai', 'Status', 'Dinonaktifkan', 'Tenggat Hapus', 'Folder OPD', '']" stickyLast>
            <x-slot:table>
                @foreach ($this->rows as $row)
                    <tr wire:key="offboarding-{{ $row->id }}">
                        <td class="px-4 py-3 text-sm">
                            <div class="font-medium text-neutral-800 dark:text-neutral-100">{{ $row->display_name }}</div>
                            <div class="font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $row->nip }}</div>
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ $row->opd?->name ?? 'Tanpa OPD' }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <div class="flex flex-wrap gap-1">
                                <x-nawasara-ui::badge :color="match ($row->status) { 'held' => 'warning', 'deleted' => 'danger', default => 'neutral' }">
                                    {{ \Nawasara\Workspace\Models\Offboarding::STATUSES[$row->status] ?? $row->status }}
                                </x-nawasara-ui::badge>
                                @if ($row->isHeld() && $row->warned_at)
                                    <x-nawasara-ui::badge color="danger">Peringatan terkirim</x-nawasara-ui::badge>
                                @endif
                            </div>
                            @if ($row->status === 'cancelled')
                                <div class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ $row->cancel_reason === 'reenabled' ? 'Akun Kisara aktif lagi' : 'Dibatalkan oleh '.($row->canceller?->name ?? '-') }}
                                </div>
                            @elseif ($row->status === 'deleted')
                                <div class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ $row->deleted_by ? 'oleh '.($row->deleter?->name ?? '-') : 'terjadwal' }},
                                    {{ $row->deleted_at_nc?->timezone('Asia/Jakarta')->format('d M Y') }}
                                </div>
                            @endif
                            @if ($row->last_error)
                                <div class="mt-1 max-w-xs whitespace-normal text-xs text-rose-600 dark:text-rose-400">{{ $row->last_error }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $row->disabled_at?->timezone('Asia/Jakarta')->format('d M Y') }}
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            <div>{{ $row->hold_until?->timezone('Asia/Jakarta')->format('d M Y') }}</div>
                            @if ($row->isHeld())
                                <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ $row->daysLeft() }} hari lagi</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            @if ($row->moved_at)
                                <div class="max-w-xs whitespace-normal">{{ $row->moved_to }}</div>
                                <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ $row->moved_items }} item, {{ $row->mover?->name ?? '-' }}, {{ $row->moved_at->timezone('Asia/Jakarta')->format('d M Y H:i') }}
                                </div>
                            @else
                                <span class="text-neutral-500 dark:text-neutral-400">Belum dipindah</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if ($row->isHeld())
                                <x-nawasara-ui::dropdown-menu-action :id="$row->id" :items="[
                                    ['type' => 'click', 'label' => 'Pindahkan ke Folder OPD', 'wire:click' => 'moveToOpd('.$row->id.')',
                                     'icon' => 'lucide-folder-input', 'permission' => 'workspace.offboarding.manage',
                                     'confirm' => 'Pindahkan seluruh isi Drive Saya pegawai ini ke Folder OPD-nya? Pemindahan tercatat.'],
                                    ['type' => 'click', 'label' => 'Batalkan penghapusan', 'wire:click' => 'cancel('.$row->id.')',
                                     'icon' => 'lucide-undo-2', 'permission' => 'workspace.offboarding.manage',
                                     'confirm' => 'Batalkan penghapusan? Akun tetap nonaktif selama akun Kisara-nya nonaktif, tetapi tidak akan dihapus.'],
                                    ['type' => 'click', 'label' => 'Hapus sekarang', 'wire:click' => 'deleteNow('.$row->id.')',
                                     'icon' => 'lucide-trash-2', 'permission' => 'workspace.offboarding.manage',
                                     'confirm' => 'Hapus akun dan seluruh Drive Saya pegawai ini sekarang? Tidak dapat dibatalkan. Folder OPD tidak tersentuh.'],
                                ]" />
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-slot:table>
        </x-nawasara-ui::table>

        <div class="mt-4">{{ $this->rows->links() }}</div>
    @endif
</div>

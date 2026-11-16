<div>
    <x-nawasara-ui::page-header
        title="Peserta Pilot"
        description="Pegawai yang boleh masuk Kisara Workspace selama uji coba. Mencabut akses tidak menghapus berkasnya."
        :count="$this->totalActive.' aktif'">
        @can('workspace.pilot.manage')
            <x-nawasara-ui::button color="primary"
                x-on:click="$dispatch('open-modal', { id: 'workspace-pilot-form', loading: true }); $dispatch('workspace-pilot-create')">
                Tambah Peserta
            </x-nawasara-ui::button>
        @endcan
    </x-nawasara-ui::page-header>

    <div class="space-y-2 mb-4">
        <div class="flex flex-col md:flex-row md:flex-nowrap md:items-center gap-2">
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <x-nawasara-ui::filter-panel label="Filter"
                    :state="['statusFilter' => $statusFilter, 'stageFilter' => $stageFilter]"
                    :labels="['statusFilter' => $this->statusOptions, 'stageFilter' => $this->stageOptions]">
                    <x-nawasara-ui::filter-group label="Status" model="statusFilter"
                        :items="$this->statusOptions" icon="lucide-circle-dot" />
                    <x-nawasara-ui::filter-group label="Tahap" model="stageFilter"
                        :items="$this->stageOptions" icon="lucide-layers" />
                </x-nawasara-ui::filter-panel>
            </div>

            <x-nawasara-ui::search-input model="search" placeholder="Cari nama atau NIP..." />
        </div>

        <div data-filter-chips class="flex flex-wrap items-center gap-2"></div>
    </div>

    @if ($this->rows->total() === 0)
        @if ($search !== '' || $stageFilter !== '' || $statusFilter !== 'active')
            <x-nawasara-ui::empty-state icon="lucide-search-x"
                title="Tidak ada yang cocok"
                description="Ubah kata kunci atau saringannya." />
        @else
            <x-nawasara-ui::empty-state icon="lucide-users"
                title="Belum ada peserta pilot"
                description="Tambahkan pegawai yang akan mencoba Kisara Workspace. Tahap 0 dimulai dari pemilik layanan." />
        @endif
    @else
        <x-nawasara-ui::table :headers="['Pegawai', 'Tahap', 'OPD', 'Ditambahkan', 'Status', '']" stickyLast>
            <x-slot:table>
                @foreach ($this->rows as $row)
                    <tr wire:key="pilot-{{ $row->id }}">
                        <td class="px-4 py-3 text-sm">
                            <div class="font-medium text-neutral-800 dark:text-neutral-100">{{ $row->name }}</div>
                            <div class="font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $row->nip }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ \Nawasara\Workspace\Models\PilotMember::STAGES[$row->stage] ?? $row->stage }}</td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $row->opd?->name ?? 'Menurut SIMAS' }}
                            @if ($row->note)
                                <div class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">{{ $row->note }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $row->created_at?->timezone('Asia/Jakarta')->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-sm">
                            @if ($row->isActive())
                                <x-nawasara-ui::badge color="success">Aktif</x-nawasara-ui::badge>
                            @else
                                <x-nawasara-ui::badge color="neutral">Dicabut</x-nawasara-ui::badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            @php
                                $items = [
                                    ['type' => 'click', 'label' => 'Ubah', 'wire:click' => 'edit('.$row->id.')',
                                     'modal' => 'workspace-pilot-form', 'icon' => 'lucide-pencil', 'permission' => 'workspace.pilot.manage'],
                                ];
                                $items[] = $row->isActive()
                                    ? ['type' => 'click', 'label' => 'Cabut akses', 'wire:click' => 'revoke('.$row->id.')', 'icon' => 'lucide-user-x',
                                       'confirm' => 'Cabut akses pilot pegawai ini? Berkasnya tidak dihapus dan aksesnya bisa dipulihkan.', 'permission' => 'workspace.pilot.manage']
                                    : ['type' => 'click', 'label' => 'Aktifkan lagi', 'wire:click' => 'restore('.$row->id.')', 'icon' => 'lucide-user-check',
                                       'permission' => 'workspace.pilot.manage'];
                                $items[] = ['type' => 'click', 'label' => 'Hapus', 'wire:click' => 'delete('.$row->id.')', 'icon' => 'lucide-trash-2',
                                    'confirm' => 'Hapus baris ini dari daftar? Pakai ini hanya untuk salah input; untuk menghentikan akses pakai Cabut akses.',
                                    'permission' => 'workspace.pilot.manage'];
                            @endphp
                            <x-nawasara-ui::dropdown-menu-action :id="$row->id" :items="$items" />
                        </td>
                    </tr>
                @endforeach
            </x-slot:table>
        </x-nawasara-ui::table>

        <div class="mt-4">{{ $this->rows->links() }}</div>
    @endif
</div>

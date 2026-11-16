<div>
    <x-nawasara-ui::page-header
        title="Penyimpangan Kuota"
        description="Kuota bawaan: {{ $this->defaults['pegawai'] }} per pegawai, {{ $this->defaults['opd'] }} per Folder OPD. Yang tercantum di sini memakai kuota sendiri, diterapkan pada sinkron berikutnya."
        :count="$this->total">
        @can('workspace.quota.manage')
            <x-nawasara-ui::button color="primary"
                x-on:click="$dispatch('open-modal', { id: 'workspace-quota-form', loading: true }); $dispatch('workspace-quota-create')">
                Tambah Penyimpangan
            </x-nawasara-ui::button>
        @endcan
    </x-nawasara-ui::page-header>

    <div class="space-y-2 mb-4">
        <div class="flex flex-col md:flex-row md:flex-nowrap md:items-center gap-2">
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <x-nawasara-ui::filter-panel label="Filter"
                    :state="['typeFilter' => $typeFilter]"
                    :labels="['typeFilter' => $this->typeOptions]">
                    <x-nawasara-ui::filter-group label="Jenis" model="typeFilter"
                        :items="$this->typeOptions" icon="lucide-layers" />
                </x-nawasara-ui::filter-panel>
            </div>

            <x-nawasara-ui::search-input model="search" placeholder="Cari nama, NIP, atau OPD..." />
        </div>

        <div data-filter-chips class="flex flex-wrap items-center gap-2"></div>
    </div>

    @if ($this->rows->total() === 0)
        @if ($search !== '' || $typeFilter !== '')
            <x-nawasara-ui::empty-state icon="lucide-search-x"
                title="Tidak ada yang cocok"
                description="Ubah kata kunci atau saringannya." />
        @else
            <x-nawasara-ui::empty-state icon="lucide-hard-drive"
                title="Belum ada penyimpangan kuota"
                description="Semua pegawai dan Folder OPD memakai kuota bawaan. Tambahkan penyimpangan bila ada yang butuh lebih besar atau lebih kecil." />
        @endif
    @else
        <x-nawasara-ui::table :headers="['Sasaran', 'Jenis', 'Kuota', 'Alasan', 'Diubah', '']" stickyLast>
            <x-slot:table>
                @foreach ($this->rows as $row)
                    <tr wire:key="quota-{{ $row->id }}">
                        <td class="px-4 py-3 text-sm">
                            @if ($row->target_type === 'opd')
                                <div class="font-medium text-neutral-800 dark:text-neutral-100">{{ $row->opd?->name ?? $row->name }}</div>
                            @else
                                <div class="font-medium text-neutral-800 dark:text-neutral-100">{{ $row->name }}</div>
                                <div class="font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $row->nip }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <x-nawasara-ui::badge :color="$row->target_type === 'opd' ? 'info' : 'neutral'">
                                {{ \Nawasara\Workspace\Models\QuotaOverride::TYPES[$row->target_type] ?? $row->target_type }}
                            </x-nawasara-ui::badge>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <div class="font-medium text-neutral-800 dark:text-neutral-100">{{ $row->quotaLabel() }}</div>
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">bawaan {{ $this->defaults[$row->target_type] ?? '-' }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            <div class="max-w-xs whitespace-normal">{{ $row->reason }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            <div>{{ $row->updater?->name ?? '-' }}</div>
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ $row->updated_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }}</div>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <x-nawasara-ui::dropdown-menu-action :id="$row->id" :items="[
                                ['type' => 'click', 'label' => 'Ubah', 'wire:click' => 'edit('.$row->id.')',
                                 'modal' => 'workspace-quota-form', 'icon' => 'lucide-pencil', 'permission' => 'workspace.quota.manage'],
                                ['type' => 'click', 'label' => 'Hapus', 'wire:click' => 'delete('.$row->id.')', 'icon' => 'lucide-trash-2',
                                 'confirm' => 'Hapus penyimpangan ini? Kuotanya kembali ke bawaan pada sinkron berikutnya. Berkas tidak tersentuh.',
                                 'permission' => 'workspace.quota.manage'],
                            ]" />
                        </td>
                    </tr>
                @endforeach
            </x-slot:table>
        </x-nawasara-ui::table>

        <div class="mt-4">{{ $this->rows->links() }}</div>
    @endif
</div>

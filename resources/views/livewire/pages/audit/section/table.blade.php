<div>
    <x-nawasara-ui::page-header
        title="Jejak Akses"
        description="Siapa melakukan apa pada berkas Kisara Workspace: masuk, membuat, membuka, mengubah, membagikan, mencabut bagian, menghapus, memulihkan, dan perubahan akses folder. Diambil dari log audit Nextcloud dan disimpan 1 tahun."
        :count="$this->rows->total()" />

    <div class="space-y-2 mb-4">
        <div class="flex flex-col md:flex-row md:flex-nowrap md:items-center gap-2">
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <x-nawasara-ui::filter-panel label="Filter"
                    :state="['actionFilter' => $actionFilter, 'scope' => $scope]"
                    :labels="['actionFilter' => $this->actionOptions, 'scope' => $this->scopeOptions]">
                    <x-nawasara-ui::filter-group label="Aksi" model="actionFilter"
                        :items="$this->actionOptions" icon="lucide-list-filter" />
                    <x-nawasara-ui::filter-group label="Cari Di" model="scope"
                        :items="$this->scopeOptions" icon="lucide-search" />
                </x-nawasara-ui::filter-panel>
            </div>

            <x-nawasara-ui::search-input model="search" placeholder="Cari NIP, nama pegawai, nama berkas, atau id berkas..." />
        </div>

        <div class="flex flex-col sm:flex-row sm:items-end gap-2">
            <div class="sm:w-48"><x-nawasara-ui::form.input type="date" label="Dari Tanggal" wire:model.live="dateFrom" /></div>
            <div class="sm:w-48"><x-nawasara-ui::form.input type="date" label="Sampai Tanggal" wire:model.live="dateTo" /></div>
        </div>

        <div data-filter-chips class="flex flex-wrap items-center gap-2"></div>

        @if ($this->lastRead)
            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                Log terakhir dibaca {{ $this->lastRead->last_read_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB.
                @if ($this->lastRead->last_error)
                    <span class="text-rose-600 dark:text-rose-400">Pembacaan terakhir gagal: {{ $this->lastRead->last_error }}</span>
                @endif
            </p>
        @endif
    </div>

    @if ($this->rows->total() === 0)
        @if ($this->isFiltered())
            <x-nawasara-ui::empty-state icon="lucide-search-x"
                title="Tidak ada yang cocok"
                description="Ubah kata kunci, aksi, atau rentang tanggalnya." />
        @else
            <x-nawasara-ui::empty-state icon="lucide-footprints"
                title="Belum ada jejak akses"
                description="Jejak muncul setelah log audit Nextcloud dibaca. Bila tetap kosong, periksa sumber log di pengaturan paket (README, bagian Jejak Akses)." />
        @endif
    @else
        <x-nawasara-ui::table :headers="['Waktu', 'Pegawai', 'Aksi', 'Berkas', 'Sebelum / Sesudah', 'IP', '']" stickyLast>
            <x-slot:table>
                @foreach ($this->rows as $row)
                    <tr wire:key="audit-{{ $row->id }}">
                        <td class="px-4 py-3 text-sm whitespace-nowrap text-neutral-700 dark:text-neutral-200">
                            <div>{{ $row->occurred_at->timezone('Asia/Jakarta')->format('d M Y') }}</div>
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ $row->occurred_at->timezone('Asia/Jakarta')->format('H:i:s') }} WIB</div>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            @if ($row->nip)
                                <div class="font-medium text-neutral-800 dark:text-neutral-100">{{ $row->account?->display_name ?? '-' }}</div>
                                <div class="font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $row->nip }}</div>
                            @else
                                <span class="text-neutral-500 dark:text-neutral-400">Sistem</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <x-nawasara-ui::badge :color="\Nawasara\Workspace\Models\AuditEvent::ACTION_COLORS[$row->action] ?? 'neutral'">
                                {{ \Nawasara\Workspace\Models\AuditEvent::ACTIONS[$row->action] ?? $row->action }}
                            </x-nawasara-ui::badge>
                            @if ($row->detail)
                                <div class="mt-1 max-w-xs whitespace-normal text-xs text-neutral-500 dark:text-neutral-400">{{ $row->detail }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            @if ($row->file_path)
                                <div class="font-medium text-neutral-800 dark:text-neutral-100">{{ $row->file_name }}</div>
                                <div class="max-w-sm whitespace-normal break-all text-xs text-neutral-500 dark:text-neutral-400">{{ $row->displayPath() }}</div>
                            @elseif ($row->file_id)
                                <span class="text-neutral-500 dark:text-neutral-400">Berkas id {{ $row->file_id }}</span>
                            @else
                                <span class="text-neutral-500 dark:text-neutral-400">-</span>
                            @endif
                            @if ($row->target)
                                <div class="mt-1 text-xs text-neutral-600 dark:text-neutral-300">Untuk: {{ $row->target }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            @if ($row->before !== null || $row->after !== null)
                                <div class="max-w-xs whitespace-normal text-xs">
                                    <div><span class="text-neutral-500 dark:text-neutral-400">Sebelum:</span> {{ $row->before ?? '-' }}</div>
                                    <div><span class="text-neutral-500 dark:text-neutral-400">Sesudah:</span> {{ $row->after ?? '-' }}</div>
                                </div>
                            @else
                                <span class="text-neutral-500 dark:text-neutral-400">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ $row->ip ?? '-' }}</td>
                        <td class="px-4 py-3 text-right">
                            <x-nawasara-ui::dropdown-menu-action :id="$row->id" :items="array_values(array_filter([
                                ($row->file_id || $row->file_name) ? ['type' => 'click', 'label' => 'Jejak berkas ini', 'wire:click' => 'traceFile('.$row->id.')', 'icon' => 'lucide-file-search'] : null,
                                $row->nip ? ['type' => 'click', 'label' => 'Jejak pegawai ini', 'wire:click' => 'traceEmployee('.$row->id.')', 'icon' => 'lucide-user-search'] : null,
                            ]))" />
                        </td>
                    </tr>
                @endforeach
            </x-slot:table>
        </x-nawasara-ui::table>

        <div class="mt-4">{{ $this->rows->links() }}</div>
    @endif
</div>

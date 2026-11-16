<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Kisara Workspace', 'url' => '#'], ['label' => 'Ringkasan']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page-header
            title="Kisara Workspace"
            description="Docs, Sheets, Slides, dan Drive untuk ASN di atas Nextcloud + OnlyOffice. Halaman ini mengelola pilot, akun, Folder OPD, dan kuota.">
            <x-nawasara-ui::icon-button icon="refresh-cw" tooltip="Periksa ulang koneksi" wire:click="refresh" />
        </x-nawasara-ui::page-header>

        @if (! $status['configured'])
            <x-nawasara-ui::empty-state
                icon="lucide-plug-zap"
                title="Belum terhubung ke Nextcloud Workspace"
                description="Isi grup Vault &quot;workspace&quot; (base_url, admin_user, app_password), lalu tekan Uji di halaman Vault." />
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-nawasara-ui::stat-card
                    label="Koneksi Nextcloud"
                    :value="$status['ok'] ? 'Terhubung' : 'Gagal'"
                    :color="$status['ok'] ? 'success' : 'danger'"
                    icon="lucide-server" />
                <x-nawasara-ui::stat-card
                    label="Versi Nextcloud"
                    :value="$status['version'] ?? '-'"
                    color="neutral"
                    icon="lucide-tag" />
                <x-nawasara-ui::stat-card
                    label="Konektor OnlyOffice"
                    :value="$status['onlyoffice'] === null ? '-' : ($status['onlyoffice'] ? 'Aktif' : 'Tidak aktif')"
                    :color="$status['onlyoffice'] ? 'success' : 'warning'"
                    icon="lucide-file-text" />
            </div>

            @if ($status['error'])
                <p class="mt-4 text-sm text-red-600 dark:text-red-400">{{ $status['error'] }}</p>
            @endif
        @endif
    </x-nawasara-ui::page.container>
</div>

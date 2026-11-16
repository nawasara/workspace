<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Kisara Workspace', 'url' => '#'], ['label' => 'Pegawai Keluar']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <livewire:nawasara-workspace.offboarding.section.table />
    </x-nawasara-ui::page.container>
</div>

<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Kisara Workspace', 'url' => '#'], ['label' => 'Penyimpangan Kuota']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <livewire:nawasara-workspace.quota.section.table />
        <livewire:nawasara-workspace.quota.section.form />
    </x-nawasara-ui::page.container>
</div>

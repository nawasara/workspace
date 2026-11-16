<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Kisara Workspace', 'url' => '#'], ['label' => 'Jejak Akses']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <livewire:nawasara-workspace.audit.section.table />
    </x-nawasara-ui::page.container>
</div>

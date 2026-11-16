<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Kisara Workspace', 'url' => '#'], ['label' => 'Peserta Pilot']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <livewire:nawasara-workspace.pilot.section.controls />
        <livewire:nawasara-workspace.pilot.section.table />
        <livewire:nawasara-workspace.pilot.section.form />
    </x-nawasara-ui::page.container>
</div>

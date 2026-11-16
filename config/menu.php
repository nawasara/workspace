<?php

$prefix = 'nawasara-workspace';

/*
| Sidebar for Kisara Workspace (Docs, Sheets, Slides, Drive for ASN).
|
| Own workspace id: no other package contributes to it (AGENTS.md §5).
| `group` must be one of WorkspaceManager::GROUP_ORDER, or the workspace
| lands silently under "Lainnya".
|
| Pages are added per task: Peserta Pilot (T-05), Folder OPD (T-12/T-13),
| Penyimpangan Kuota (T-14), Antrean Pegawai Keluar (T-33), Jejak Akses (T-36).
*/

return [
    [
        'workspace' => 'kisara-workspace',
        'label' => 'Kisara Workspace',
        'icon' => 'lucide-folder-open',
        'group' => 'Layanan',
        'url' => '',
        'permission' => 'workspace.view',
        'submenu' => [
            [
                'label' => 'Ringkasan',
                'icon' => 'lucide-layout-dashboard',
                'url' => url($prefix.'/overview'),
                'permission' => 'workspace.view',
                'navigate' => true,
            ],
            [
                'label' => 'Peserta Pilot',
                'icon' => 'lucide-users',
                'url' => url($prefix.'/pilot'),
                'permission' => 'workspace.view',
                'navigate' => true,
            ],
            [
                'label' => 'Penyimpangan Kuota',
                'icon' => 'lucide-hard-drive',
                'url' => url($prefix.'/quota'),
                'permission' => 'workspace.quota.view',
                'navigate' => true,
            ],
            [
                'label' => 'Pegawai Keluar',
                'icon' => 'lucide-user-x',
                'url' => url($prefix.'/offboarding'),
                'permission' => 'workspace.offboarding.view',
                'navigate' => true,
            ],
            [
                'label' => 'Jejak Akses',
                'icon' => 'lucide-footprints',
                'url' => url($prefix.'/audit'),
                'permission' => 'workspace.audit.view',
                'navigate' => true,
            ],
        ],
    ],
];

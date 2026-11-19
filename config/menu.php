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

// With nawasara/asn-portal installed, who may use Workspace is managed in
// Akses Layanan Kisara (one list for every Kisara app) and /pilot only
// redirects there, so the item takes that page's label and permission. The
// URL stays inside this workspace on purpose: a submenu URL under another
// package's prefix would make the sidebar think that page belongs here.
$pilotItem = class_exists(\Nawasara\AsnPortal\Services\KisaraAccess::class)
    ? [
        'label' => 'Akses Layanan',
        'icon' => 'lucide-users',
        'url' => url($prefix.'/pilot'),
        'permission' => 'asn-portal.access.view',
        'navigate' => true,
    ]
    : [
        'label' => 'Peserta Pilot',
        'icon' => 'lucide-users',
        'url' => url($prefix.'/pilot'),
        'permission' => 'workspace.view',
        'navigate' => true,
    ];

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
            $pilotItem,
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

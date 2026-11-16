<?php

return [
    /*
    | Nextcloud admin connection lives in Vault (group `workspace`), never here.
    | The admin account is used ONLY by Nawasara jobs (provisioning, folders,
    | quotas). Workspace Web never sees it: it talks to Nextcloud as the
    | employee (ADR 0003).
    */
    'vault_group' => 'workspace',

    'request_timeout' => 15,

    /*
    | Defaults (FR-13 AC-1). Overridable per employee / per OPD later (T-14).
    */
    'quota' => [
        'drive_default' => '3 GB',
        'opd_folder_default' => '50 GB',
    ],

    'scheduler' => [
        'enabled' => env('NAWASARA_WORKSPACE_SCHEDULER', true),
    ],

    // Minutes between provisioning runs (T-10).
    'sync_interval' => 15,
];

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
    | Defaults (FR-13 AC-1). Exceptions per employee / per OPD are rows in
    | Penyimpangan Kuota (T-14), applied by the next account / folder sync.
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

    // Most account writes per run. The first run for ~11 000 ASN is spread
    // over several runs instead of one that outlives the job timeout.
    'provision_batch' => 1000,

    /*
    | Nextcloud door of the pilot gate (T-07): this Keycloak group is kept equal
    | to who may enter. Must match user_oidc's group-whitelist-regex (T-01).
    */
    'keycloak_pilot_group' => 'workspace-pilot',

    // Push a group sync right after a participant or the kill switch changes,
    // instead of waiting for the 15-minute run (FR-02 AC-3: within a minute).
    'sync_on_change' => env('NAWASARA_WORKSPACE_SYNC_ON_CHANGE', true),
];

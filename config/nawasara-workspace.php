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

    /*
    | Directory search for Bagikan / @mention (T-25). Limits are per minute:
    | per service token (all of Workspace Web) and per employee typing
    | (`actor`). Results per call are capped at max_limit.
    */
    'directory' => [
        'default_limit' => 10,
        'max_limit' => 20,
        'per_token_per_minute' => 600,
        'per_actor_per_minute' => 60,
    ],

    /*
    | Built-in templates (T-21, FR-15): BuiltinTemplateSeeder puts the files of
    | resources/templates/ in this team folder. admin_group writes (the
    | Nawasara admin account must be in it); reader_groups plus every OPD-*
    | group with a Folder OPD read only. Metadata (name, order, scope,
    | status) is the nawasara_workspace_templates table (T-31).
    */
    'templates' => [
        'folder' => 'Templat Kisara',
        'admin_group' => 'admin',
        'reader_groups' => ['workspace-pilot'],
        // Kelola Templat upload limit (FR-16 AC-2). PHP upload_max_filesize
        // and post_max_size must be at least this.
        'max_upload_mb' => 20,
    ],

    /*
    | Quota API for the Penyimpanan page of Kisara Akun (T-29). Drive answers
    | are cached per NIP so reloads do not reach Nextcloud each time; a failed
    | read is cached shorter. warning_percent = "Hampir penuh" (FR-14 AC-2).
    */
    'quota_api' => [
        'cache_seconds' => 60,
        'failure_cache_seconds' => 15,
        'timeout' => 5,
        'warning_percent' => 80,
    ],
];

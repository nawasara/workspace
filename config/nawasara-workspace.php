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

    /*
    | Departed employees (T-33, FR-18). Daily at run_at (Asia/Jakarta).
    | hold_days: Drive Saya kept after the Keycloak account is disabled.
    | warn_days: warning before deletion; deletion never comes sooner than
    | this after the warning. max_new_per_run: new departures disabled per
    | run, so a Keycloak glitch that disables everyone cannot disable all of
    | Workspace in one go. archive_dir: folder inside the Folder OPD that
    | "Pindahkan ke Folder OPD" moves Drive Saya into.
    */
    'offboarding' => [
        'hold_days' => 90,
        'warn_days' => 7,
        'max_new_per_run' => 200,
        'run_at' => '02:30',
        'archive_dir' => 'Arsip Pegawai Keluar',
    ],

    /*
    | Jejak Akses (T-36, FR-20): Nextcloud admin_audit log -> audit events.
    | source: none | file | http (see Services/Audit/AuditLogSource).
    |  - file: path readable by Nawasara (mounted or copied log).
    |  - http: URL serving the log read-only with Range support (e.g. nginx
    |    on the Workspace host, internal port). Bearer token from Vault group
    |    `workspace` field `audit_log_token`, else `token` below.
    | With `none` the ingest job is not scheduled.
    | interval: minutes between reads. retention_days: FR-20 AC-3 (1 year).
    | prune_at: daily prune, Asia/Jakarta.
    */
    'audit' => [
        'source' => env('NAWASARA_WORKSPACE_AUDIT_SOURCE', 'none'),
        'file' => env('NAWASARA_WORKSPACE_AUDIT_FILE', ''),
        'url' => env('NAWASARA_WORKSPACE_AUDIT_URL', ''),
        'token' => env('NAWASARA_WORKSPACE_AUDIT_TOKEN', ''),
        'interval' => 5,
        'chunk_bytes' => 1024 * 1024,
        'max_bytes_per_run' => 32 * 1024 * 1024,
        'retention_days' => 365,
        'prune_at' => '03:15',
    ],

    /*
    | Alerts to the Nawasara Telegram channel (T-35, NFR-07). Needs
    | nawasara/alerting; without it the checks only log.
    |  - provisioning: alert after N failed runs in a row of one Workspace job
    |    (nawasara/sync history); the next success resolves it.
    |  - onlyoffice: probed every minute; alert when down longer than
    |    down_minutes. healthcheck_url empty = {Vault base_url minus /nc}/oo/healthcheck
    |    (internal address, answers "true").
    |  - disk: Workspace LXC disk from nawasara/proxmox (synced every 15 min).
    |    Alert above threshold_percent; no change when the sync is older than
    |    stale_minutes.
    | Backup failure: pending T-34 (D-01), see README.
    */
    'monitoring' => [
        'provisioning' => [
            'consecutive_failures' => 2,
        ],
        'onlyoffice' => [
            'enabled' => env('NAWASARA_WORKSPACE_OO_MONITOR', true),
            'healthcheck_url' => env('NAWASARA_WORKSPACE_OO_HEALTHCHECK_URL', ''),
            'down_minutes' => 5,
            'timeout' => 5,
        ],
        'disk' => [
            'enabled' => env('NAWASARA_WORKSPACE_DISK_MONITOR', true),
            'vmid' => (int) env('NAWASARA_WORKSPACE_VMID', 145),
            'vm_type' => 'lxc',
            'threshold_percent' => 80,
            'stale_minutes' => 120,
        ],
    ],
];

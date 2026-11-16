<?php

namespace Nawasara\Workspace\Services\Audit;

use Nawasara\Vault\Facades\Vault;

/**
 * Builds the configured AuditLogSource from config `nawasara-workspace.audit`
 * (see AuditLogSource for why there are two). Returns null when no source
 * is set: the ingest job then does nothing and is not even scheduled.
 */
class AuditSourceFactory
{
    public static function make(): ?AuditLogSource
    {
        $cfg = (array) config('nawasara-workspace.audit', []);

        return match ($cfg['source'] ?? 'none') {
            'file' => new FileAuditLogSource((string) ($cfg['file'] ?? '')),
            'http' => new HttpAuditLogSource(
                url: (string) ($cfg['url'] ?? ''),
                token: self::token($cfg),
                timeout: (int) config('nawasara-workspace.request_timeout', 15),
            ),
            default => null,
        };
    }

    /**
     * Token for the HTTP source: Vault group `workspace`, field
     * `audit_log_token`, when it exists there; the env value otherwise.
     * Vault lookups must never break the job on a group without the field.
     */
    private static function token(array $cfg): string
    {
        try {
            $token = Vault::get((string) config('nawasara-workspace.vault_group', 'workspace'), 'audit_log_token');
        } catch (\Throwable) {
            $token = null;
        }

        return (string) ($token ?: ($cfg['token'] ?? ''));
    }
}

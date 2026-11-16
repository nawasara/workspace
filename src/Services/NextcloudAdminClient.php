<?php

namespace Nawasara\Workspace\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Nawasara\Vault\Facades\Vault;

/**
 * Nawasara's admin connection to the Workspace Nextcloud (OCS API).
 *
 * Used only by Nawasara: provisioning, groups, OPD folders, quotas. Workspace
 * Web never holds these credentials; it acts as the employee (ADR 0003).
 * Authenticates with an app password of the Nextcloud admin, stored in Vault
 * group `workspace`, never the admin's login password.
 *
 * T-04 ships the connection check; T-09 adds users, groups and team folders.
 */
class NextcloudAdminClient
{
    public function __construct(
        private string $baseUrl,
        private string $user,
        private string $appPassword,
        private int $timeout = 15,
    ) {}

    /** Built from Vault; empty values mean "not configured", not an error. */
    public static function fromVault(): static
    {
        $group = (string) config('nawasara-workspace.vault_group', 'workspace');

        return new static(
            baseUrl: rtrim((string) Vault::get($group, 'base_url'), '/'),
            user: (string) Vault::get($group, 'admin_user'),
            appPassword: (string) Vault::get($group, 'app_password'),
            timeout: (int) config('nawasara-workspace.request_timeout', 15),
        );
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->user !== '' && $this->appPassword !== '';
    }

    /**
     * Nextcloud version and whether the OnlyOffice connector is enabled, from
     * the capabilities endpoint (one cheap authenticated call).
     *
     * @return array{version: string, onlyoffice: bool}
     *
     * @throws \RuntimeException on a network error or a non-200 answer
     */
    public function capabilities(): array
    {
        try {
            $response = $this->ocs()->get($this->baseUrl.'/ocs/v2.php/cloud/capabilities', ['format' => 'json']);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Nextcloud tidak dapat dihubungi: '.$e->getMessage(), 0, $e);
        }

        if ($response->status() === 401) {
            throw new \RuntimeException('Nextcloud menolak kredensial admin (401). Periksa app password di Vault.');
        }
        if (! $response->successful()) {
            throw new \RuntimeException('Nextcloud menjawab HTTP '.$response->status().'.');
        }

        $data = $response->json('ocs.data') ?? [];

        return [
            'version' => (string) ($data['version']['string'] ?? '?'),
            'onlyoffice' => isset($data['capabilities']['onlyoffice']),
        ];
    }

    /**
     * Vault "Uji" button. Must return the key `success`, not `ok`: the panel
     * reads `success` and shows a red toast for anything else (AGENTS.md §3).
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'Isi base_url, admin_user, dan app_password dulu.'];
        }

        try {
            $caps = $this->capabilities();
        } catch (\RuntimeException $e) {
            return ['success' => false, 'message' => 'Gagal: '.$e->getMessage()];
        }

        return [
            'success' => true,
            'message' => 'Terhubung ke Nextcloud '.$caps['version']
                .($caps['onlyoffice'] ? ', konektor OnlyOffice aktif.' : ', tetapi konektor OnlyOffice BELUM aktif.'),
        ];
    }

    protected function ocs(): PendingRequest
    {
        return Http::withBasicAuth($this->user, $this->appPassword)
            ->withHeaders(['OCS-APIRequest' => 'true'])
            ->acceptJson()
            ->timeout($this->timeout);
    }
}

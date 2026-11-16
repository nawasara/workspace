<?php

namespace Nawasara\Workspace\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nawasara\Vault\Facades\Vault;

/**
 * Nawasara's admin connection to the Workspace Nextcloud.
 *
 * Used only by Nawasara: accounts, groups, OPD team folders, quotas. Workspace
 * Web never holds these credentials; it acts as the employee (ADR 0003).
 * Authenticates with an app password of the Nextcloud admin, stored in Vault
 * group `workspace`, never the admin's login password.
 *
 * Paths and payloads were verified against Nextcloud 31.0.14 + groupfolders
 * 19.1.20 on LXC 145 (17 Nov 2026), not taken from documentation:
 *   - users and groups: /ocs/v2.php/cloud/..., JSON bodies
 *   - team folders: /index.php/apps/groupfolders/folders?format=json
 *     (NOT under /ocs/v2.php in this version: 404 there), success
 *     statuscode 100
 *
 * Every failure throws NextcloudException; a method never answers "false"
 * for an error the caller could mistake for a decision.
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

    // ─── Connection ─────────────────────────────────────

    /**
     * @return array{version: string, onlyoffice: bool}
     */
    public function capabilities(): array
    {
        $data = $this->ocs('get', '/ocs/v2.php/cloud/capabilities');

        return [
            'version' => (string) ($data['version']['string'] ?? '?'),
            'onlyoffice' => isset($data['capabilities']['onlyoffice']),
        ];
    }

    /**
     * Vault "Uji" button. Must return the key `success`, not `ok` (AGENTS §3).
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
        } catch (NextcloudException $e) {
            return ['success' => false, 'message' => 'Gagal: '.$e->getMessage()];
        }

        return [
            'success' => true,
            'message' => 'Terhubung ke Nextcloud '.$caps['version']
                .($caps['onlyoffice'] ? ', konektor OnlyOffice aktif.' : ', tetapi konektor OnlyOffice BELUM aktif.'),
        ];
    }

    // ─── Users (UID = NIP, ADR 0005) ────────────────────

    /** The account, or null when it does not exist. */
    public function getUser(string $uid): ?array
    {
        try {
            return $this->ocs('get', '/ocs/v2.php/cloud/users/'.rawurlencode($uid));
        } catch (NextcloudException $e) {
            if ($e->httpStatus === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Create an account with a random password nobody ever uses: employees
     * sign in through Keycloak (user_oidc links to this account by UID).
     *
     * @param  list<string>  $groups
     */
    public function createUser(string $uid, string $displayName, ?string $email, string $quota, array $groups = []): void
    {
        $body = [
            'userid' => $uid,
            'password' => Str::password(40),
            'displayName' => $displayName,
            'quota' => $quota,
            'groups' => $groups,
        ];
        if ($email) {
            // Safe only because core newUser.sendEmail=no (hardening script).
            $body['email'] = $email;
        }

        $this->ocs('post', '/ocs/v2.php/cloud/users', $body);
    }

    /** One field: displayname, email or quota. */
    public function updateUser(string $uid, string $key, string $value): void
    {
        $this->ocs('put', '/ocs/v2.php/cloud/users/'.rawurlencode($uid), ['key' => $key, 'value' => $value]);
    }

    public function disableUser(string $uid): void
    {
        $this->ocs('put', '/ocs/v2.php/cloud/users/'.rawurlencode($uid).'/disable');
    }

    public function enableUser(string $uid): void
    {
        $this->ocs('put', '/ocs/v2.php/cloud/users/'.rawurlencode($uid).'/enable');
    }

    public function deleteUser(string $uid): void
    {
        $this->ocs('delete', '/ocs/v2.php/cloud/users/'.rawurlencode($uid));
    }

    // ─── Groups ─────────────────────────────────────────

    /** Create the group unless it already exists. */
    public function ensureGroup(string $gid): void
    {
        try {
            $this->ocs('post', '/ocs/v2.php/cloud/groups', ['groupid' => $gid]);
        } catch (NextcloudException $e) {
            if ($e->ocsCode !== 102) { // 102 = group exists
                throw $e;
            }
        }
    }

    /** @return list<string> */
    public function userGroups(string $uid): array
    {
        return array_values($this->ocs('get', '/ocs/v2.php/cloud/users/'.rawurlencode($uid).'/groups')['groups'] ?? []);
    }

    public function addUserToGroup(string $uid, string $gid): void
    {
        $this->ocs('post', '/ocs/v2.php/cloud/users/'.rawurlencode($uid).'/groups', ['groupid' => $gid]);
    }

    public function removeUserFromGroup(string $uid, string $gid): void
    {
        $this->ocs('delete', '/ocs/v2.php/cloud/users/'.rawurlencode($uid).'/groups', ['groupid' => $gid]);
    }

    // ─── Team folders (Folder OPD) ──────────────────────

    /**
     * @return array<int, array{id: int, mount_point: string, groups: array<string,int>, quota: int, acl: bool}>
     */
    public function teamFolders(): array
    {
        $out = [];
        foreach ($this->ocs('get', $this->gf()) ?? [] as $f) {
            $out[(int) $f['id']] = [
                'id' => (int) $f['id'],
                'mount_point' => (string) $f['mount_point'],
                'groups' => (array) ($f['groups'] ?? []),
                'quota' => (int) ($f['quota'] ?? -3),
                'acl' => (bool) ($f['acl'] ?? false),
            ];
        }

        return $out;
    }

    /** @return int the new folder id */
    public function createTeamFolder(string $name): int
    {
        return (int) ($this->ocs('post', $this->gf(), ['mountpoint' => $name])['id']
            ?? throw new NextcloudException('Nextcloud tidak mengembalikan id folder baru.'));
    }

    public function renameTeamFolder(int $id, string $name): void
    {
        $this->ocs('post', $this->gf("/{$id}/mountpoint"), ['mountpoint' => $name]);
    }

    /** Permissions 31 = read, write, create, delete, share. */
    public function assignTeamFolderGroup(int $id, string $gid, int $permissions = 31): void
    {
        $this->ocs('post', $this->gf("/{$id}/groups"), ['group' => $gid]);
        if ($permissions !== 31) {
            $this->ocs('post', $this->gf("/{$id}/groups/".rawurlencode($gid)), ['permissions' => $permissions]);
        }
    }

    public function removeTeamFolderGroup(int $id, string $gid): void
    {
        $this->ocs('delete', $this->gf("/{$id}/groups/".rawurlencode($gid)));
    }

    public function setTeamFolderQuota(int $id, int $bytes): void
    {
        $this->ocs('post', $this->gf("/{$id}/quota"), ['quota' => $bytes]);
    }

    /**
     * Turn on advanced permissions and let a group manage them: how OPD
     * members restrict their own sub-folders (FR-12 AC-3, D-03, T-13).
     */
    public function enableTeamFolderAcl(int $id, string $managerGroup): void
    {
        $this->ocs('post', $this->gf("/{$id}/acl"), ['acl' => 1]);
        $this->ocs('post', $this->gf("/{$id}/manageACL"), ['mappingType' => 'group', 'mappingId' => $managerGroup, 'manageAcl' => true]);
    }

    public function deleteTeamFolder(int $id): void
    {
        $this->ocs('delete', $this->gf("/{$id}"));
    }

    // ─── Transport ──────────────────────────────────────

    private function gf(string $suffix = ''): string
    {
        return '/index.php/apps/groupfolders/folders'.$suffix.'?format=json';
    }

    /**
     * One OCS call; returns `ocs.data`. Throws on network errors, non-2xx, or
     * an OCS failure status inside a 200 (OCS v1-style apps do that).
     */
    private function ocs(string $method, string $path, array $body = []): mixed
    {
        // base_url already carries the /nc webroot, so /ocs/... and
        // /index.php/... both hang off it.
        $url = $this->baseUrl.$path;

        try {
            $response = $this->http()->send(strtoupper($method), $url, $body === [] ? [] : ['json' => $body]);
        } catch (ConnectionException $e) {
            throw new NextcloudException('Nextcloud tidak dapat dihubungi: '.$e->getMessage(), previous: $e);
        }

        return $this->unwrap($response, $method, $path);
    }

    private function unwrap(Response $response, string $method, string $path): mixed
    {
        $meta = $response->json('ocs.meta') ?? [];
        $code = isset($meta['statuscode']) ? (int) $meta['statuscode'] : null;
        $okStatus = ($meta['status'] ?? null) === 'ok';

        if ($response->status() === 401) {
            throw new NextcloudException('Nextcloud menolak kredensial admin (401). Periksa app password di Vault.', 401, $code);
        }
        if (! $response->successful() || ($meta !== [] && ! $okStatus)) {
            $msg = (string) ($meta['message'] ?? '');
            throw new NextcloudException(
                strtoupper($method).' '.$path.' gagal: HTTP '.$response->status().($code !== null ? ", OCS {$code}" : '').($msg !== '' ? " ({$msg})" : ''),
                $response->status(),
                $code,
            );
        }

        return $response->json('ocs.data');
    }

    protected function http(): PendingRequest
    {
        return Http::withBasicAuth($this->user, $this->appPassword)
            ->withHeaders(['OCS-APIRequest' => 'true'])
            ->acceptJson()
            ->timeout($this->timeout);
    }
}

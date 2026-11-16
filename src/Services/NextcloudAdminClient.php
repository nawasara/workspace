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

    /**
     * Drive usage of one account, as Nextcloud counts it: files the employee
     * owns (shared ones included, FR-13 AC-3) plus versions and trash
     * (FR-13 AC-4). `quota` null = unlimited. Null when the account does not
     * exist.
     *
     * An account that never signed in has no storage yet: Nextcloud then
     * answers only `quota` (bytes, or "none") with `used` 0.
     *
     * @return array{used: int, quota: ?int}|null
     */
    public function userQuota(string $uid): ?array
    {
        $user = $this->getUser($uid);
        if ($user === null) {
            return null;
        }

        $q = $user['quota'] ?? null;
        if (! is_array($q) || ! array_key_exists('used', $q)) {
            throw new NextcloudException('Nextcloud tidak mengembalikan pemakaian kuota akun.');
        }

        $quota = $q['quota'] ?? null;
        $quota = is_numeric($quota) && (int) $quota >= 0 ? (int) $quota : null; // -3 / "none" = unlimited

        return ['used' => max(0, (int) $q['used']), 'quota' => $quota];
    }

    /** Same client with another timeout, for calls a person is waiting on. */
    public function withTimeout(int $seconds): static
    {
        $clone = clone $this;
        $clone->timeout = $seconds;

        return $clone;
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
            // gid => permissions. Some groupfolders versions answer
            // gid => {permissions, displayName, type}; both become an int.
            $groups = [];
            foreach ((array) ($f['groups'] ?? []) as $gid => $perm) {
                $groups[(string) $gid] = (int) (is_array($perm) ? ($perm['permissions'] ?? 0) : $perm);
            }
            $out[(int) $f['id']] = [
                'id' => (int) $f['id'],
                'mount_point' => (string) $f['mount_point'],
                'groups' => $groups,
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

    /** Change the permissions of a group already on the folder (1 = read only). */
    public function setTeamFolderGroupPermissions(int $id, string $gid, int $permissions): void
    {
        $this->ocs('post', $this->gf("/{$id}/groups/".rawurlencode($gid)), ['permissions' => $permissions]);
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

    // ─── Files of the admin account (WebDAV) ─────────────

    /** The admin account the client signs in as (its files live under its UID). */
    public function adminUser(): string
    {
        return $this->user;
    }

    /**
     * Contents of a file in the admin's own tree, e.g. "Templat Kisara/Nota
     * Dinas.docx" (team folders appear there for the admin's groups). Null
     * when the file does not exist.
     *
     * Only Nawasara control-plane jobs use this (built-in templates, T-21);
     * Workspace Web reads files as the employee, never as admin (ADR 0003).
     */
    public function downloadFile(string $path): ?string
    {
        $response = $this->dav('GET', $path);
        if ($response->status() === 404) {
            return null;
        }
        $this->davOk($response, 'GET', $path);

        return $response->body();
    }

    /** Create or replace a file in the admin's own tree (single PUT; small files only). */
    public function uploadFile(string $path, string $contents, string $mime = 'application/octet-stream'): void
    {
        $this->davOk($this->dav('PUT', $path, $contents, $mime), 'PUT', $path);
    }

    /**
     * Nextcloud file id of a file in the admin's tree, or null when it does
     * not exist. The id is the same for every user who sees the file (a team
     * folder file has one id), so Workspace Web can ask for its preview with
     * the employee's own token. Overwriting a file with PUT keeps its id.
     */
    public function fileId(string $path): ?int
    {
        $body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
            .'<d:prop><oc:fileid/></d:prop></d:propfind>';
        $response = $this->dav('PROPFIND', $path, $body, 'application/xml', ['Depth' => '0']);
        if ($response->status() === 404) {
            return null;
        }
        $this->davOk($response, 'PROPFIND', $path);

        // Prefix-agnostic: servers are free to pick the namespace prefix.
        if (preg_match('#<(?:[A-Za-z0-9_-]+:)?fileid>\s*(\d+)\s*</#', $response->body(), $m) !== 1) {
            throw new NextcloudException("PROPFIND {$path}: jawaban tanpa fileid");
        }

        return (int) $m[1];
    }

    /** Delete a file in the admin's tree. Already gone counts as done (true = deleted now). */
    public function deleteFile(string $path): bool
    {
        $response = $this->dav('DELETE', $path);
        if ($response->status() === 404) {
            return false;
        }
        $this->davOk($response, 'DELETE', $path);

        return true;
    }

    private function dav(string $method, string $path, ?string $body = null, ?string $mime = null, array $headers = []): Response
    {
        $segments = array_map('rawurlencode', array_filter(explode('/', $path), fn ($s) => $s !== ''));
        $url = $this->baseUrl.'/remote.php/dav/files/'.rawurlencode($this->user).'/'.implode('/', $segments);

        $request = Http::withBasicAuth($this->user, $this->appPassword)->timeout($this->timeout)->withHeaders($headers);
        if ($body !== null) {
            $request = $request->withBody($body, $mime ?? 'application/octet-stream');
        }

        try {
            return $request->send($method, $url);
        } catch (ConnectionException $e) {
            throw new NextcloudException('Nextcloud tidak dapat dihubungi: '.$e->getMessage(), previous: $e);
        }
    }

    private function davOk(Response $response, string $method, string $path): void
    {
        if ($response->status() === 401) {
            throw new NextcloudException('Nextcloud menolak kredensial admin (401). Periksa app password di Vault.', 401);
        }
        if (! $response->successful()) {
            throw new NextcloudException("{$method} {$path} gagal: HTTP ".$response->status(), $response->status());
        }
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

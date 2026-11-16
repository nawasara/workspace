<?php

namespace Nawasara\Workspace\Services;

use Nawasara\Keycloak\Models\KeycloakUser;
use Nawasara\Workspace\Models\Account;

/**
 * A Nextcloud account for every active ASN, before they ever open Workspace
 * (T-10, FR-17, FR-10, ADR 0005), so anyone can be found, tagged and shared
 * with from day one. Accounts are created here but cannot sign in until the
 * pilot gate lets them (user_oidc group whitelist, T-07).
 *
 * Source: the Keycloak snapshot (`nawasara_keycloak_users`): enabled accounts
 * whose username is an 18-digit NIP. UID in Nextcloud = NIP, so the account
 * created here and the one user_oidc links at first login are the same.
 *
 * Idempotent and incremental: an account is written only when it is new or
 * its name, e-mail, quota or OPD changed (state_hash). At most `limit` writes
 * per run; the rest follow in the next run, so the first ~11 000 accounts
 * arrive over a few runs instead of one long one.
 *
 * Disabled Keycloak accounts are left alone here: offboarding (hold 90 days,
 * then delete) is its own flow (T-33).
 */
class AccountProvisioner
{
    public function __construct(
        private NextcloudAdminClient $nc,
        private OpdResolver $opds,
    ) {}

    /**
     * @return array{seen: int, created: int, updated: int, unchanged: int, failed: int, deferred: int}
     */
    public function run(int $limit = 1000): array
    {
        $stats = ['seen' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'deferred' => 0];
        $quota = (string) config('nawasara-workspace.quota.drive_default', '3 GB');
        $known = Account::pluck('state_hash', 'nip')->all();
        $writes = 0;

        KeycloakUser::query()
            ->where('enabled', true)
            ->orderBy('id')
            ->chunk(500, function ($users) use (&$stats, &$writes, &$known, $quota, $limit) {
                foreach ($users as $kc) {
                    $nip = (string) $kc->username;
                    if (preg_match('/^\d{18}$/', $nip) !== 1) {
                        continue;
                    }
                    $stats['seen']++;

                    $want = [
                        'display_name' => mb_substr($kc->full_name ?: $nip, 0, 255),
                        'email' => $kc->email ?: null,
                        'quota' => $quota,
                        'opd_id' => $this->opds->forKeycloakUser($kc),
                    ];
                    $hash = hash('sha256', json_encode($want));

                    if (($known[$nip] ?? null) === $hash) {
                        $stats['unchanged']++;
                        continue;
                    }
                    if ($writes >= $limit) {
                        $stats['deferred']++;
                        continue;
                    }
                    $writes++;

                    try {
                        $created = $this->write($nip, $want);
                        $stats[$created ? 'created' : 'updated']++;
                        Account::updateOrCreate(['nip' => $nip], $want + [
                            'keycloak_id' => $kc->user_id,
                            'state_hash' => $hash,
                            'nc_synced_at' => now(),
                            'last_error' => null,
                        ] + ($created ? ['nc_created_at' => now()] : []));
                        $known[$nip] = $hash;
                    } catch (NextcloudException $e) {
                        $stats['failed']++;
                        // Recorded without the new hash, so the next run retries it.
                        Account::where('nip', $nip)->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
                    }
                }
            });

        return $stats;
    }

    /**
     * Bring one Nextcloud account to the wanted state.
     *
     * @return bool true when the account was created
     */
    private function write(string $nip, array $want): bool
    {
        $group = $want['opd_id'] ? Account::opdGroup($want['opd_id']) : null;
        if ($group) {
            $this->nc->ensureGroup($group);
        }

        $existing = $this->nc->getUser($nip);

        if ($existing === null) {
            $this->nc->createUser($nip, $want['display_name'], $want['email'], $want['quota'], $group ? [$group] : []);

            return true;
        }

        if (($existing['displayname'] ?? null) !== $want['display_name']) {
            $this->nc->updateUser($nip, 'displayname', $want['display_name']);
        }
        if ($want['email'] && ($existing['email'] ?? null) !== $want['email']) {
            $this->nc->updateUser($nip, 'email', $want['email']);
        }
        $this->nc->updateUser($nip, 'quota', $want['quota']);

        // OPD groups only: other groups (workspace-pilot from user_oidc,
        // admin) are never touched. Moving OPD removes the old group and
        // adds the new one; files made in the old Folder OPD stay there
        // (FR-12 AC-4).
        $current = $this->nc->userGroups($nip);
        foreach ($current as $g) {
            if (str_starts_with($g, 'OPD-') && $g !== $group) {
                $this->nc->removeUserFromGroup($nip, $g);
            }
        }
        if ($group && ! in_array($group, $current, true)) {
            $this->nc->addUserToGroup($nip, $group);
        }

        return false;
    }
}

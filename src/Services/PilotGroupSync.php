<?php

namespace Nawasara\Workspace\Services;

use Nawasara\Keycloak\Models\KeycloakUser;
use Nawasara\Keycloak\Services\KeycloakClient;
use Nawasara\Workspace\Models\PilotMember;

/**
 * Keeps the Keycloak group `workspace-pilot` equal to who may enter now
 * (T-07, ADR 0004). Nextcloud's user_oidc refuses login to anyone outside the
 * group, so this is the Nextcloud door of the pilot gate: it holds even for
 * someone who goes straight to /nc/, WebDAV, or the desktop app.
 *
 * "Who may enter" comes from AccessDecider, the same rules the Workspace Web
 * door uses. Kill switch on = nobody, so the group is emptied.
 *
 * Only the difference is written: one add per missing person, one removal
 * per extra one. Running it twice changes nothing the second time.
 */
class PilotGroupSync
{
    public function __construct(
        private KeycloakClient $keycloak,
        private AccessDecider $decider,
    ) {}

    /**
     * @return array{group: string, desired: int, current: int, added: int, removed: int, failed: int, unresolved: int}
     */
    public function run(): array
    {
        $name = (string) config('nawasara-workspace.keycloak_pilot_group', 'workspace-pilot');
        $group = $this->keycloak->findGroupByName($name)
            ?? throw new \RuntimeException("Grup Keycloak '{$name}' belum ada. Buat dulu di realm (T-01).");

        [$desired, $unresolved] = $this->desiredMembers();
        $current = [];
        foreach ($this->keycloak->getGroupMembers($group['id']) as $m) {
            $current[$m['id']] = $m['username'];
        }

        [$toAdd, $toRemove] = static::plan($desired, $current);

        $failed = 0;
        foreach ($toAdd as $id) {
            $this->keycloak->addUserToGroup($id, $group['id']) || $failed++;
        }
        foreach ($toRemove as $id) {
            $this->keycloak->removeUserFromGroup($id, $group['id']) || $failed++;
        }

        return [
            'group' => $name,
            'desired' => count($desired),
            'current' => count($current),
            'added' => count($toAdd),
            'removed' => count($toRemove),
            'failed' => $failed,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * Pure diff: which Keycloak user ids to add and to remove.
     *
     * @param  array<string, string>  $desired  keycloak id => nip
     * @param  array<string, string>  $current  keycloak id => username
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function plan(array $desired, array $current): array
    {
        return [
            array_values(array_diff(array_keys($desired), array_keys($current))),
            array_values(array_diff(array_keys($current), array_keys($desired))),
        ];
    }

    /**
     * Active participants the decider lets in, keyed by Keycloak user id.
     * The id stored at pick time is used first; the snapshot by NIP fills in
     * rows that predate it. Someone with neither is counted as unresolved,
     * never guessed.
     *
     * @return array{0: array<string, string>, 1: int}
     */
    private function desiredMembers(): array
    {
        $desired = [];
        $unresolved = 0;

        foreach (PilotMember::active()->get() as $m) {
            if (! $this->decider->decide($m->nip)['allowed']) {
                continue;
            }

            $id = $m->keycloak_id ?: KeycloakUser::where('username', $m->nip)->value('user_id');
            if ($id) {
                $desired[$id] = $m->nip;
            } else {
                $unresolved++;
            }
        }

        return [$desired, $unresolved];
    }
}

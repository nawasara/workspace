<?php

namespace Nawasara\Workspace\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;

/**
 * Where Workspace's pilot list lives.
 *
 * Since Akses Layanan Kisara (nawasara/asn-portal), the list of who may use
 * Kisara Workspace, its stage, the OPD override, the kill switch and "open
 * to all" live there, as app `workspace`, next to Kisara Mail and later
 * tools. This class is the one place Workspace asks it.
 *
 * nawasara/asn-portal is NOT a dependency of this package (nor the other
 * way round): it is found by class name, the same way asn-portal used to
 * find Workspace's AccessDecider. Two conditions make it the source:
 *   1. the class exists (package installed), and
 *   2. its table exists (migrated). The migration that creates it also
 *      copies the pilot list, so the switch happens in one `migrate` run.
 * Otherwise Workspace keeps its own tables (nawasara_workspace_pilot_members,
 * Setting flags) exactly as before.
 *
 * Only a YES is remembered per process. A no is asked again each time: a
 * queue worker started between deploy and `migrate` would otherwise keep
 * using the old list for good, and the Keycloak group would stop following
 * the new one. Without asn-portal the check is a cheap class_exists.
 */
class KisaraAccessBridge
{
    public const SERVICE = 'Nawasara\AsnPortal\Services\KisaraAccess';

    public const TABLE = 'nawasara_asn_portal_app_access';

    public const APP = 'workspace';

    /** Event fired by asn-portal after any access change (by name, see provider). */
    public const CHANGED_EVENT = 'Nawasara\AsnPortal\Events\KisaraAccessChanged';

    /** @var array<string, bool> service class => active */
    private static array $active = [];

    public function __construct(
        private Container $container,
        private string $serviceClass = self::SERVICE,
    ) {}

    public function active(): bool
    {
        if (self::$active[$this->serviceClass] ?? false) {
            return true;
        }

        return self::$active[$this->serviceClass] = class_exists($this->serviceClass) && Schema::hasTable(self::TABLE);
    }

    /** Forget the remembered answer (tests, or right after a migration in the same process). */
    public static function flush(): void
    {
        self::$active = [];
    }

    /** @return array{nip: string, allowed: bool, reason: ?string, stage: ?int, opd_id: ?int} */
    public function decide(string $nip): array
    {
        $d = $this->service()->allows(self::APP, $nip);

        return [
            'nip' => $nip,
            'allowed' => $d['allowed'] === true,
            'reason' => $d['reason'] ?? null,
            'stage' => $d['stage'] ?? null,
            'opd_id' => isset($d['opd_id']) ? (int) $d['opd_id'] : null,
        ];
    }

    /** Open to all, or an active row; the kill switch is not checked. */
    public function isMember(string $nip): bool
    {
        return $this->service()->isMember(self::APP, $nip);
    }

    /** @return list<array{nip: string, keycloak_id: ?string}> active rows of app `workspace` */
    public function members(): array
    {
        return $this->service()->activeMembers(self::APP)
            ->map(fn ($m) => ['nip' => (string) $m->nip, 'keycloak_id' => $m->keycloak_id ?: null])
            ->values()
            ->all();
    }

    /** @return array<string, int> nip => OPD id */
    public function opdOverrides(): array
    {
        return $this->service()->opdOverrides(self::APP);
    }

    private function service(): object
    {
        return $this->container->make($this->serviceClass);
    }
}

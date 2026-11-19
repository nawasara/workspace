<?php

namespace Nawasara\Workspace\Services;

use Nawasara\Keycloak\Models\KeycloakUser;
use Nawasara\Registry\Models\Opd;
use Nawasara\Registry\Services\UnitKerjaMatcher;
use Nawasara\Workspace\Models\PilotMember;
use Nawasara\Workspace\Support\KisaraAccessBridge;

/**
 * Which OPD an ASN belongs to, for their OPD group and Folder OPD (T-11).
 *
 *   1. pilot override: `opd_id` set on the participant (when SIMAS is wrong),
 *      in Akses Layanan Kisara when asn-portal is installed, else in this
 *      package's pilot table
 *   2. SIMAS: Keycloak attribute `simas_skpd` (filled on every login by the
 *      authenticator, 2.4.0) matched WHOLE to a registry OPD, the same rule
 *      as UnitKerjaMatcher::matchSimas: "DINAS PENDIDIKAN" never claims
 *      "DINAS PENDIDIKAN DAN KEBUDAYAAN"
 *   3. otherwise none: no OPD group, no Folder OPD (fail-closed, FR-12 AC-2)
 *
 * The OPD map is built once per instance: UnitKerjaMatcher::match loads every
 * OPD per call, which ~11 000 lookups per provisioning run cannot afford.
 */
class OpdResolver
{
    /** @var array<string, int> normalised name => opd id */
    private ?array $byName = null;

    /** @var array<string, int> nip => opd id */
    private ?array $overrides = null;

    public function forKeycloakUser(KeycloakUser $kc): ?int
    {
        return $this->resolve((string) $kc->username, $kc->attributeValue('simas_skpd'));
    }

    public function resolve(string $nip, ?string $simasSkpd): ?int
    {
        $this->overrides ??= $this->loadOverrides();
        if (isset($this->overrides[$nip])) {
            return $this->overrides[$nip];
        }

        if ($simasSkpd === null || trim($simasSkpd) === '') {
            return null;
        }

        $this->byName ??= $this->buildMap();

        return $this->byName[UnitKerjaMatcher::normalize($simasSkpd)] ?? null;
    }

    /** @return array<string, int> */
    private function loadOverrides(): array
    {
        $bridge = app(KisaraAccessBridge::class);
        if ($bridge->active()) {
            return $bridge->opdOverrides();
        }

        return PilotMember::whereNotNull('opd_id')->pluck('opd_id', 'nip')->map(fn ($v) => (int) $v)->all();
    }

    /** simas_name when set (bound to SIMAS spelling), else name. */
    private function buildMap(): array
    {
        $map = [];
        foreach (Opd::all(['id', 'name', 'simas_name']) as $opd) {
            $key = UnitKerjaMatcher::normalize((string) ($opd->simas_name ?: $opd->name));
            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = (int) $opd->id;
            }
        }

        return $map;
    }
}

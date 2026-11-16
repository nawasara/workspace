<?php

namespace Nawasara\Workspace\Services;

use Nawasara\Keycloak\Models\KeycloakUser;
use Nawasara\Workspace\Models\PilotMember;
use Nawasara\Workspace\Models\Setting;

/**
 * Who may enter Kisara Workspace right now (FR-02). One rule set for both
 * doors (ADR 0004): the access API Workspace Web asks (T-06) and the Keycloak
 * group `workspace-pilot` that Nextcloud checks (T-07). If the two ever
 * decided differently, someone could be refused by one door and let in by
 * the other.
 *
 * Order matters:
 *   1. kill switch      nobody, whatever else is true
 *   2. disabled account an ASN whose Kisara account is off never gets in
 *   3. pilot_open       stage 3: every active ASN
 *   4. participant      active row in the pilot list
 */
class AccessDecider
{
    public const KILL_SWITCH = 'kill_switch';

    public const ACCOUNT_DISABLED = 'account_disabled';

    public const NOT_PARTICIPANT = 'not_participant';

    public const REVOKED = 'revoked';

    public const INVALID_NIP = 'invalid_nip';

    /**
     * @return array{nip: string, allowed: bool, reason: ?string, stage: ?int, opd_id: ?int}
     */
    public function decide(string $nip): array
    {
        $deny = fn (string $reason) => ['nip' => $nip, 'allowed' => false, 'reason' => $reason, 'stage' => null, 'opd_id' => null];

        if (preg_match('/^\d{18}$/', $nip) !== 1) {
            return $deny(self::INVALID_NIP);
        }

        if (Setting::flag(Setting::KILL_SWITCH)) {
            return $deny(self::KILL_SWITCH);
        }

        // From the Keycloak snapshot. An account missing from the snapshot is
        // not treated as disabled: the snapshot syncs hourly and a brand-new
        // ASN would otherwise be refused for up to an hour for no reason.
        $enabled = KeycloakUser::where('username', $nip)->value('enabled');
        if ($enabled === false || $enabled === 0) {
            return $deny(self::ACCOUNT_DISABLED);
        }

        $member = PilotMember::where('nip', $nip)->first();

        if (Setting::flag(Setting::PILOT_OPEN)) {
            return ['nip' => $nip, 'allowed' => true, 'reason' => null, 'stage' => 3, 'opd_id' => $member?->opd_id];
        }

        if (! $member) {
            return $deny(self::NOT_PARTICIPANT);
        }

        if (! $member->isActive()) {
            return $deny(self::REVOKED);
        }

        return ['nip' => $nip, 'allowed' => true, 'reason' => null, 'stage' => $member->stage, 'opd_id' => $member->opd_id];
    }
}

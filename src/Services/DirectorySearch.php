<?php

namespace Nawasara\Workspace\Services;

use Illuminate\Database\Eloquent\Builder;
use Nawasara\Keycloak\Models\KeycloakUser;
use Nawasara\Registry\Models\Opd;
use Nawasara\Workspace\Models\Account;

/**
 * The Kisara Workspace directory behind Bagikan and @mention (FR-09 AC-1/5,
 * FR-10 AC-1, T-25).
 *
 * Source is the Keycloak snapshot (`nawasara_keycloak_users`), not Nextcloud:
 * an ASN who never opened Workspace must be found too, and the snapshot holds
 * every Kisara account. Only enabled accounts whose username is an 18-digit
 * NIP count as ASN, the same rule as AccountProvisioner, so whoever is found
 * here also has a Nextcloud account with UID = NIP to share with.
 *
 * Searched columns are chosen on purpose: username (NIP) and first/last name.
 * Never email, never the `attributes` JSON: a LIKE on either would turn the
 * search into an oracle for e-mail or phone numbers ("does anyone's WhatsApp
 * start with 0812..."), even though neither is ever returned.
 *
 * Jabatan, unit and OPD come from the simas_* attributes that the Keycloak
 * authenticator writes at login. An ASN who never logged in to any Kisara
 * app has none yet: they are still found, with those fields null.
 */
class DirectorySearch
{
    public const MIN_QUERY = 3;

    public const MAX_TERMS = 4;

    public function __construct(private OpdResolver $opds) {}

    /**
     * Normalise a raw query: keep letters, digits, space and the punctuation
     * found in names (. , ' -). Drops LIKE wildcards (% _) and backslashes
     * so user input can never widen the pattern.
     */
    public static function clean(string $q): string
    {
        $q = preg_replace("/[^\\p{L}\\p{N} .,'\\-]/u", ' ', $q) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $q) ?? '');
    }

    /**
     * @return list<array{nip: string, name: string, jabatan: ?string, unit: ?string, opd_id: ?int}>
     */
    public function people(string $q, int $limit): array
    {
        $query = KeycloakUser::query()
            ->select(['id', 'username', 'first_name', 'last_name', 'attributes'])
            ->where('enabled', true);

        if (ctype_digit($q)) {
            // NIP prefix: a range on the username index, not a scan.
            $query->where('username', 'like', $q.'%')->orderBy('username');
        } else {
            $this->whereName($query, $q);
            $first = explode(' ', $q)[0];
            $query->orderByRaw('CASE WHEN first_name LIKE ? THEN 0 ELSE 1 END', [$first.'%'])
                ->orderBy('first_name')->orderBy('last_name');
        }

        // Over-fetch a little: the 18-digit check runs in PHP (no portable
        // regex in SQL), and service accounts are rare but not absent.
        $rows = $query->limit($limit + 10)->get();

        $out = [];
        foreach ($rows as $kc) {
            $nip = (string) $kc->username;
            if (preg_match('/^\d{18}$/', $nip) !== 1) {
                continue;
            }
            $out[] = [
                'nip' => $nip,
                'name' => $kc->full_name ?: ($kc->attributeValue('simas_nama') ?? $nip),
                'jabatan' => $kc->attributeValue('simas_jabatan'),
                'unit' => $kc->attributeValue('simas_unit'),
                'opd_id' => $this->opds->forKeycloakUser($kc),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * OPDs to share with as a group (`OPD-<id>`). Only OPDs that already have
     * at least one Workspace account: the Nextcloud group is created by the
     * account sync, so an OPD without accounts has no group to share with.
     *
     * @return \Illuminate\Support\Collection<int, Opd>
     */
    public function opds(string $q, int $limit)
    {
        if (ctype_digit($q)) {
            return collect();
        }

        $withAccounts = Account::query()->whereNotNull('opd_id')->select('opd_id')->distinct();

        return Opd::query()
            ->select(['id', 'name'])
            ->whereIn('id', $withAccounts)
            ->where(function ($w) use ($q) {
                $w->where('name', 'like', '%'.$q.'%')->orWhere('code', 'like', $q.'%');
            })
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', [$q.'%'])
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /** Every word must appear in the first or last name ("pringgo saputro"). */
    private function whereName(Builder $query, string $q): void
    {
        $terms = array_slice(array_values(array_filter(explode(' ', $q), fn ($t) => $t !== '')), 0, self::MAX_TERMS);

        foreach ($terms as $term) {
            $like = '%'.$term.'%';
            $query->where(function ($w) use ($like) {
                $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like);
            });
        }
    }
}

<?php

namespace Nawasara\Workspace\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Nawasara\Workspace\Models\PilotMember;
use Nawasara\Workspace\Models\Setting;

/**
 * Drive and Mail usage of one employee, for the Penyimpanan page of Kisara
 * Akun (FR-14, T-29).
 *
 *   drive  Nextcloud, the account whose UID is the NIP. Nextcloud's own
 *          numbers, so shares count to the owner and versions and trash
 *          count too (FR-13 AC-3, AC-4). Null for someone who is not a pilot
 *          participant (FR-14 AC-4): every ASN has an account (T-10), but
 *          the card is only for those who may use Drive.
 *   mail   the WHM snapshot of nawasara/whm (`SyncWhmEmailsJob`), never a
 *          live WHM call. The personal mailbox: linked through cPanel
 *          External Authentication to this NIP and to nobody else (the same
 *          rule as the ASN portal's MailboxDirectory). Shared agency
 *          mailboxes are not this person's quota. Null when there is none.
 *
 * Each part fails on its own (FR-14 AC-3): an error becomes
 * `status: unavailable` for that part only, and the other is still read.
 *
 * Drive is cached per NIP (`quota_api.cache_seconds`) so a page reload does
 * not reach Nextcloud each time; a failure is cached for a shorter time
 * (`quota_api.failure_cache_seconds`) so a Nextcloud that is down is not
 * hit with a fresh timeout by every visitor.
 */
class QuotaReader
{
    public const OK = 'ok';

    public const UNAVAILABLE = 'unavailable';

    public const LEVEL_OK = 'ok';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_FULL = 'full';

    private const MB = 1024 * 1024;

    public function __construct(private NextcloudAdminClient $nc) {}

    /**
     * @return array{nip: string, drive: ?array, mail: ?array}
     */
    public function forNip(string $nip): array
    {
        return [
            'nip' => $nip,
            'drive' => $this->isParticipant($nip) ? $this->drive($nip) : null,
            'mail' => $this->mail($nip),
        ];
    }

    /**
     * Pilot participant for the Drive card: pilot open to all ASN, or an
     * active row in the pilot list. The kill switch is deliberately not
     * checked: it closes the door for a while, but the files are still there
     * and still use the quota.
     */
    public function isParticipant(string $nip): bool
    {
        return Setting::flag(Setting::PILOT_OPEN)
            || PilotMember::where('nip', $nip)->whereNull('removed_at')->exists();
    }

    /** @return array{status: string, used_bytes: ?int, quota_bytes: ?int, used_percent: ?int, level: ?string} */
    public function drive(string $nip): array
    {
        $key = "nawasara-workspace:quota:drive:{$nip}";
        if (is_array($hit = Cache::get($key))) {
            return $hit;
        }

        try {
            if (! $this->nc->isConfigured()) {
                throw new NextcloudException('Nextcloud belum dikonfigurasi di Vault.');
            }
            $q = $this->nc->withTimeout(max(1, (int) config('nawasara-workspace.quota_api.timeout', 5)))->userQuota($nip);
            // No account yet: the sync creates it within one run. Saying
            // "0 of 3 GB" would be a guess, not Nextcloud's answer.
            $out = $q === null
                ? $this->unavailable('drive', 'account_missing')
                : $this->section($q['used'], $q['quota']);
        } catch (\Throwable $e) {
            $out = $this->unavailable('drive', $e->getMessage());
        }

        $ttl = $out['status'] === self::OK
            ? (int) config('nawasara-workspace.quota_api.cache_seconds', 60)
            : (int) config('nawasara-workspace.quota_api.failure_cache_seconds', 15);
        if ($ttl > 0) {
            Cache::put($key, $out, $ttl);
        }

        return $out;
    }

    /** @return array{status: string, address: ?string, used_bytes: ?int, quota_bytes: ?int, used_percent: ?int, level: ?string}|null */
    public function mail(string $nip): ?array
    {
        $links = \Nawasara\Whm\Models\WhmMailboxLink::class;
        $accounts = \Nawasara\Whm\Models\WhmEmailAccount::class;

        try {
            // Soft dependency: without nawasara/whm (or before its migrations
            // ran) Mail cannot be read, which is not the same as "no mailbox".
            if (! class_exists($links) || ! class_exists($accounts)
                || ! Schema::hasTable((new $links)->getTable()) || ! Schema::hasTable((new $accounts)->getTable())) {
                return ['address' => null] + $this->unavailable('mail', 'whm_missing');
            }

            $mine = $links::query()->where('subject', $nip)->pluck('mailbox')->map(fn ($m) => strtolower((string) $m))->unique();
            if ($mine->isEmpty()) {
                return null;
            }

            // Personal = no other NIP linked to the same mailbox.
            $shared = $links::query()->whereIn('mailbox', $mine->all())->where('subject', '!=', $nip)->pluck('mailbox')
                ->map(fn ($m) => strtolower((string) $m))->unique()->all();
            $personal = $mine->reject(fn ($m) => in_array($m, $shared, true))->sort()->values();
            if ($personal->isEmpty()) {
                return null;
            }

            // Several personal mailboxes is rare; the first by address is
            // stable, so the card does not jump between reloads.
            $address = $personal->first();
            $account = $accounts::query()->whereRaw('LOWER(email) = ?', [$address])->first();

            // Linked but not in the snapshot, or the nightly disk sync has not
            // stored usage yet: unknown, not empty.
            if ($account === null || $account->disk_used_mb === null || empty($account->humanized)) {
                return ['address' => $address] + $this->unavailable('mail', 'not_synced');
            }

            $quota = $account->quota_mb !== null ? (int) $account->quota_mb * self::MB : null;

            return ['address' => $address] + $this->section((int) round((float) $account->disk_used_mb * self::MB), $quota);
        } catch (\Throwable $e) {
            return ['address' => null] + $this->unavailable('mail', $e->getMessage());
        }
    }

    /**
     * Percent is rounded down, and "warning" compares the exact bytes, so
     * 79.9 % is neither shown as 80 nor flagged (FR-14 AC-2: from 80 %).
     *
     * @return array{status: string, used_bytes: int, quota_bytes: ?int, used_percent: ?int, level: string}
     */
    private function section(int $used, ?int $quota): array
    {
        $warn = (int) config('nawasara-workspace.quota_api.warning_percent', 80);

        if ($quota === null || $quota <= 0) {
            return ['status' => self::OK, 'used_bytes' => $used, 'quota_bytes' => null, 'used_percent' => null, 'level' => self::LEVEL_OK];
        }

        $level = match (true) {
            $used >= $quota => self::LEVEL_FULL,
            $used * 100 >= $quota * $warn => self::LEVEL_WARNING,
            default => self::LEVEL_OK,
        };

        return [
            'status' => self::OK,
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'used_percent' => (int) floor($used * 100 / $quota),
            'level' => $level,
        ];
    }

    /** @return array{status: string, used_bytes: null, quota_bytes: null, used_percent: null, level: null} */
    private function unavailable(string $source, string $why): array
    {
        // No NIP in the log line: the reason is the same for everyone when a
        // source is down, and the request id from api.log ties it to a call.
        Log::warning('workspace.quota.source_unavailable', ['source' => $source, 'reason' => mb_substr($why, 0, 300)]);

        return ['status' => self::UNAVAILABLE, 'used_bytes' => null, 'quota_bytes' => null, 'used_percent' => null, 'level' => null];
    }
}

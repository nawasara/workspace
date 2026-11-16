<?php

namespace Nawasara\Workspace\Alerting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nawasara\Vault\Facades\Vault;

/**
 * "OnlyOffice tidak menjawab > 5 menit" (NFR-07).
 *
 * Probed every minute from Nawasara to OnlyOffice's own healthcheck (answers
 * the body `true`) through the internal address, the same one the admin
 * client uses. Not through docs.ponorogo.go.id: a Cloudflare or tunnel fault
 * is not an OnlyOffice fault, and a public probe would also need the public
 * path to be open to Nawasara.
 *
 * The first failed probe only records when the outage began (cache). The
 * alert fires once the outage is longer than down_minutes, so one restart
 * or a slow minute stays quiet. The first good probe clears the mark and
 * resolves the alert.
 *
 * Logs only on transitions (down, alerted, recovered), not every minute.
 */
class OnlyOfficeHealthMonitor
{
    public const CACHE_KEY = 'nawasara-workspace:onlyoffice:down-since';

    public const URL_CACHE_KEY = 'nawasara-workspace:onlyoffice:base-url';

    public const TARGET_TYPE = 'WorkspaceService';

    public const TARGET_ID = 'onlyoffice';

    /** @return string up | down | alerted | unconfigured */
    public function check(): string
    {
        $url = $this->url();
        if ($url === '') {
            return 'unconfigured';
        }

        $requestId = (string) Str::uuid();
        $reason = $this->probe($url);
        $now = Carbon::now();

        if ($reason === null) {
            $since = Cache::pull(self::CACHE_KEY);
            if ($since !== null) {
                Log::info('workspace.onlyoffice.recovered', [
                    'request_id' => $requestId,
                    'down_seconds' => $now->getTimestamp() - (int) $since,
                ]);
            }
            WorkspaceAlerts::resolve(WorkspaceAlerts::RULE_ONLYOFFICE, self::TARGET_TYPE, self::TARGET_ID);

            return 'up';
        }

        $since = Cache::get(self::CACHE_KEY);
        if ($since === null) {
            $since = $now->getTimestamp();
            Cache::forever(self::CACHE_KEY, $since);
            Log::warning('workspace.onlyoffice.down', ['request_id' => $requestId, 'reason' => $reason]);
        }

        $downSeconds = $now->getTimestamp() - (int) $since;
        $limit = max(1, (int) config('nawasara-workspace.monitoring.onlyoffice.down_minutes', 5)) * 60;

        if ($downSeconds <= $limit) {
            return 'down';
        }

        Log::warning('workspace.onlyoffice.alerted', [
            'request_id' => $requestId, 'reason' => $reason, 'down_seconds' => $downSeconds,
        ]);

        WorkspaceAlerts::fire(WorkspaceAlerts::RULE_ONLYOFFICE, self::TARGET_TYPE, self::TARGET_ID, [
            'down_since' => Carbon::createFromTimestamp((int) $since)->timezone('Asia/Jakarta')->format('d M Y H:i').' WIB',
            'minutes' => intdiv($downSeconds, 60),
            'reason' => $reason,
        ]);

        return 'alerted';
    }

    /** Null when healthy, else a short reason (status code or exception class; no URL, no body). */
    public function probe(string $url): ?string
    {
        try {
            $response = Http::timeout(max(1, (int) config('nawasara-workspace.monitoring.onlyoffice.timeout', 5)))
                ->withoutRedirecting()
                ->get($url);
        } catch (\Throwable $e) {
            return class_basename($e);
        }

        if (! $response->successful()) {
            return 'http_'.$response->status();
        }

        return trim($response->body()) === 'true' ? null : 'unexpected_body';
    }

    /**
     * Configured URL, else {Vault base_url without /nc}/oo/healthcheck.
     * The single origin serves Nextcloud at /nc and OnlyOffice at /oo.
     */
    public function url(): string
    {
        $url = (string) config('nawasara-workspace.monitoring.onlyoffice.healthcheck_url', '');
        if ($url !== '') {
            return $url;
        }

        // Cached: a Vault read is logged in vault_access_log, and once a
        // minute would add 1 440 rows a day for a value that rarely changes.
        $base = (string) Cache::remember(self::URL_CACHE_KEY, 600, function () {
            try {
                return rtrim((string) Vault::get((string) config('nawasara-workspace.vault_group', 'workspace'), 'base_url'), '/');
            } catch (\Throwable) {
                return '';
            }
        });

        if ($base === '') {
            return '';
        }

        if (str_ends_with($base, '/nc')) {
            $base = substr($base, 0, -3);
        }

        return $base.'/oo/healthcheck';
    }
}

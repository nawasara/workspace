<?php

namespace Nawasara\Workspace\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Nawasara\Keycloak\Models\KeycloakUser;
use Nawasara\Workspace\Models\Account;
use Nawasara\Workspace\Models\Offboarding;
use Nawasara\Workspace\Models\OpdFolder;

/**
 * Departed employees (T-33, FR-18). Admin Kisara owns this queue (D-03).
 *
 * Source of "departed": the Keycloak snapshot (`nawasara_keycloak_users`),
 * `enabled = false`, for a NIP that has a Workspace account
 * (`nawasara_workspace_accounts`). An account that is simply missing from
 * the snapshot is NOT treated as departed: a partial or failed Keycloak
 * sync would otherwise disable people en masse.
 *
 * One daily run:
 *   1. held / manually cancelled rows whose Keycloak account is enabled
 *      again: enable in Nextcloud; a held row becomes cancelled (AC-5).
 *   2. newly disabled: disable in Nextcloud (shares stay valid, AC-1) and
 *      hold for `hold_days` (AC-2). At most `max_new_per_run` per run.
 *   3. held, deadline within `warn_days`, not warned: warn once (AC-4).
 *   4. held, deadline passed: delete the account, which takes Drive Saya
 *      with it. Team folders belong to their OPD, not to the account, so
 *      they are never touched (AC-4). The account row is dropped, so if
 *      the person ever comes back the account sync creates a fresh one.
 *
 * The account sync (AccountProvisioner) skips every NIP with a row that
 * holds the account disabled, so the two never fight over one account.
 */
class OffboardingManager
{
    public function __construct(
        private NextcloudAdminClient $nc,
        private OffboardingNotifier $notifier,
    ) {}

    /**
     * @return array{reenabled: int, queued: int, warned: int, deleted: int, failed: int, deferred: int}
     */
    public function run(): array
    {
        $stats = ['reenabled' => 0, 'queued' => 0, 'warned' => 0, 'deleted' => 0, 'failed' => 0, 'deferred' => 0];

        $this->reenable($stats);
        $this->queueDeparted($stats);
        $this->warnDue($stats);
        $this->deleteDue($stats);

        return $stats;
    }

    private function reenable(array &$stats): void
    {
        $rows = Offboarding::where('nc_disabled', true)->get();
        if ($rows->isEmpty()) {
            return;
        }
        $enabled = KeycloakUser::whereIn('username', $rows->pluck('nip'))->where('enabled', true)->pluck('username')->flip();

        foreach ($rows as $row) {
            if (! isset($enabled[$row->nip])) {
                continue;
            }
            try {
                if ($this->nc->getUser($row->nip) !== null) {
                    $this->nc->enableUser($row->nip);
                }
                $row->fill(['nc_disabled' => false, 'last_error' => null]);
                if ($row->isHeld()) {
                    $row->fill(['status' => Offboarding::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => Offboarding::CANCEL_REENABLED]);
                }
                $row->save();
                $this->notifier->clear($row);
                $stats['reenabled']++;
            } catch (NextcloudException $e) {
                $this->fail($row, $e, $stats);
            }
        }
    }

    private function queueDeparted(array &$stats): void
    {
        $limit = max(1, (int) config('nawasara-workspace.offboarding.max_new_per_run', 200));
        $holdDays = max(1, (int) config('nawasara-workspace.offboarding.hold_days', 90));
        $handled = Offboarding::where('nc_disabled', true)->pluck('nip')->flip();

        // Disabled in Keycloak AND has a Workspace account. The join keeps
        // ~11 000 enabled people out of the loop.
        $candidates = Account::query()
            ->join('nawasara_keycloak_users as kc', 'kc.username', '=', 'nawasara_workspace_accounts.nip')
            ->where('kc.enabled', false)
            ->orderBy('nawasara_workspace_accounts.id')
            ->get(['nawasara_workspace_accounts.nip', 'nawasara_workspace_accounts.display_name', 'nawasara_workspace_accounts.opd_id']);

        $writes = 0;
        foreach ($candidates as $acc) {
            if (isset($handled[$acc->nip])) {
                continue;
            }
            if ($writes >= $limit) {
                $stats['deferred']++;

                continue;
            }
            $writes++;

            try {
                if ($this->nc->getUser($acc->nip) !== null) {
                    $this->nc->disableUser($acc->nip);
                }
            } catch (NextcloudException $e) {
                // No row yet: the next run tries again.
                $stats['failed']++;
                Log::warning('workspace offboarding: disable failed', ['error' => $e->getMessage()]);

                continue;
            }

            Offboarding::create([
                'nip' => $acc->nip,
                'display_name' => $acc->display_name,
                'opd_id' => $acc->opd_id,
                'status' => Offboarding::STATUS_HELD,
                'nc_disabled' => true,
                'disabled_at' => now(),
                'hold_until' => now()->addDays($holdDays),
            ]);
            $handled[$acc->nip] = true;
            $stats['queued']++;
        }

        if ($stats['deferred'] > 0) {
            Log::warning('workspace offboarding: more departures than max_new_per_run; rest follow next run', ['deferred' => $stats['deferred']]);
        }
    }

    private function warnDue(array &$stats): void
    {
        $days = max(0, (int) config('nawasara-workspace.offboarding.warn_days', 7));

        Offboarding::held()->whereNull('warned_at')->where('hold_until', '<=', now()->addDays($days))
            ->get()->each(function (Offboarding $row) use (&$stats) {
                $this->notifier->warn($row);
                $row->update(['warned_at' => now()]);
                $stats['warned']++;
            });
    }

    private function deleteDue(array &$stats): void
    {
        $days = max(0, (int) config('nawasara-workspace.offboarding.warn_days', 7));

        Offboarding::held()->where('hold_until', '<=', now())->get()
            ->each(function (Offboarding $row) use (&$stats, $days) {
                // Never sooner than warn_days after the warning: if the
                // schedule missed days, or the deadline was moved forward,
                // admin Kisara still gets the full week to move the files.
                // One hour of slack so a daily run a few seconds earlier
                // than last week's does not slip a whole day.
                if ($row->warned_at === null || $row->warned_at->copy()->addDays($days)->subHour()->isFuture()) {
                    return;
                }
                try {
                    $this->delete($row, null);
                    $stats['deleted']++;
                } catch (NextcloudException $e) {
                    $this->fail($row, $e, $stats);
                }
            });
    }

    /**
     * Delete the Nextcloud account and Drive Saya now. Team folders are not
     * touched. `$by` = user id of admin Kisara, null for the schedule.
     */
    public function delete(Offboarding $row, ?int $by): void
    {
        if (! $row->isHeld()) {
            throw new \DomainException('Hanya akun yang masih ditahan yang dapat dihapus.');
        }

        if ($this->nc->getUser($row->nip) !== null) {
            $this->nc->deleteUser($row->nip);
        }

        $row->update([
            'status' => Offboarding::STATUS_DELETED,
            'nc_disabled' => false,
            'deleted_at_nc' => now(),
            'deleted_by' => $by,
            'last_error' => null,
        ]);
        Account::where('nip', $row->nip)->delete();
        $this->notifier->clear($row);
        Log::info('workspace offboarding: account deleted', ['offboarding_id' => $row->id, 'manual' => $by !== null]);
    }

    /**
     * Stop the deletion (AC-5 by hand, e.g. disabled by mistake or long
     * leave). The Nextcloud account stays disabled while the Keycloak account
     * is; it is enabled by the next run once Keycloak is enabled again. The
     * row keeps `nc_disabled`, so the next run does not queue it again.
     */
    public function cancel(Offboarding $row, int $by): void
    {
        if (! $row->isHeld()) {
            throw new \DomainException('Hanya akun yang masih ditahan yang dapat dibatalkan.');
        }

        $row->update([
            'status' => Offboarding::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $by,
            'cancel_reason' => Offboarding::CANCEL_MANUAL,
        ]);
        $this->notifier->clear($row);
    }

    /**
     * Move Drive Saya into "<archive_dir>/<NIP> <name>" in the Folder OPD of
     * the employee's OPD (AC-3), and record who, when and where. The
     * employee is added to that OPD's group for the move if needed and
     * removed again afterwards.
     *
     * @return array{moved: int, failed: list<string>, to: string}
     */
    public function moveToOpdFolder(Offboarding $row, int $by): array
    {
        if (! $row->isHeld()) {
            throw new \DomainException('Hanya akun yang masih ditahan yang dapat dipindahkan.');
        }
        $folder = $row->opd_id ? OpdFolder::firstWhere('opd_id', $row->opd_id) : null;
        if (! $folder) {
            throw new \DomainException('Pegawai ini tidak punya Folder OPD tujuan.');
        }
        if ($this->nc->getUser($row->nip) === null) {
            throw new \DomainException('Akun Nextcloud pegawai ini tidak ditemukan.');
        }

        $dir = trim((string) config('nawasara-workspace.offboarding.archive_dir', 'Arsip Pegawai Keluar'), '/')
            .'/'.self::safeName($row->nip.' '.$row->display_name);
        $group = Account::opdGroup($row->opd_id);
        $added = ! in_array($group, $this->nc->userGroups($row->nip), true);
        if ($added) {
            $this->nc->addUserToGroup($row->nip, $group);
        }

        try {
            $result = $this->nc->moveUserFilesToTeamFolder($row->nip, $folder->name, $dir);
        } finally {
            if ($added) {
                $this->nc->removeUserFromGroup($row->nip, $group);
            }
        }

        $to = $folder->name.'/'.$dir;
        $row->update([
            'moved_at' => now(),
            'moved_by' => $by,
            'moved_to' => mb_substr($to, 0, 500),
            'moved_items' => $result['moved'],
            'last_error' => $result['failed'] ? mb_substr('Tidak terpindah (nama sudah ada): '.implode(', ', $result['failed']), 0, 500) : null,
        ]);
        Log::info('workspace offboarding: drive moved to OPD folder', ['offboarding_id' => $row->id, 'moved' => $result['moved'], 'failed' => count($result['failed'])]);

        return $result + ['to' => $to];
    }

    /** A name Nextcloud accepts as one path segment. */
    private static function safeName(string $name): string
    {
        return trim(preg_replace('#[\\\\/:*?"<>|]+#', ' ', $name)) ?: 'pegawai';
    }

    private function fail(Offboarding $row, NextcloudException $e, array &$stats): void
    {
        $stats['failed']++;
        $row->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
        Log::warning('workspace offboarding: step failed', ['offboarding_id' => $row->id, 'error' => $e->getMessage()]);
    }
}

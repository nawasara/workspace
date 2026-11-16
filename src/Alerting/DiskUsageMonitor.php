<?php

namespace Nawasara\Workspace\Alerting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "Disk > 80%" (NFR-07) for the Workspace LXC.
 *
 * Reads what nawasara/proxmox already syncs every 15 minutes
 * (nawasara_proxmox_vms.disk_used / disk_total). Proxmox reports the disk
 * from inside the machine for LXC containers (QEMU VMs report 0 without a
 * guest agent), and the Workspace server is an LXC, so no agent or new
 * endpoint is needed.
 *
 * Separate from proxmox's own vm.disk rule on purpose: that one stays quiet
 * while more than 20 GB is free. On the 200 GB Workspace disk, 80% still
 * leaves 40 GB, so it would not fire until about 90%, later than NFR-07.
 *
 * Note: Proxmox reports the ROOT disk of the container only. If the Nextcloud
 * data directory is ever moved to a separate mount point (mp0), this no
 * longer measures it.
 *
 * Missing row, zero size or a stale sync: no change at all (neither fire nor
 * resolve). Unknown is not healthy.
 */
class DiskUsageMonitor
{
    public const TARGET_TYPE = 'ProxmoxVm';

    /** @return string ok | high | unknown | stale | unavailable */
    public function check(): string
    {
        if (! class_exists(\Nawasara\Proxmox\Models\ProxmoxVm::class)) {
            return 'unavailable';
        }

        $vmid = (int) config('nawasara-workspace.monitoring.disk.vmid', 145);
        $type = (string) config('nawasara-workspace.monitoring.disk.vm_type', 'lxc');
        $targetId = "{$type}/{$vmid}";
        $requestId = (string) Str::uuid();

        $vm = \Nawasara\Proxmox\Models\ProxmoxVm::query()->where('vmid', $vmid)->where('vm_type', $type)->first();

        if ($vm === null || (int) $vm->disk_total <= 0) {
            Log::info('workspace.disk.unknown', ['request_id' => $requestId, 'target' => $targetId]);

            return 'unknown';
        }

        $staleMinutes = max(1, (int) config('nawasara-workspace.monitoring.disk.stale_minutes', 120));
        if ($vm->last_synced_at === null || Carbon::parse($vm->last_synced_at)->lt(Carbon::now()->subMinutes($staleMinutes))) {
            Log::warning('workspace.disk.stale', ['request_id' => $requestId, 'target' => $targetId]);

            return 'stale';
        }

        $total = (int) $vm->disk_total;
        $used = (int) $vm->disk_used;
        $percent = round($used / $total * 100, 1);
        $threshold = (float) config('nawasara-workspace.monitoring.disk.threshold_percent', 80);

        if ($percent <= $threshold) {
            WorkspaceAlerts::resolve(WorkspaceAlerts::RULE_DISK, self::TARGET_TYPE, $targetId);

            return 'ok';
        }

        Log::warning('workspace.disk.high', ['request_id' => $requestId, 'target' => $targetId, 'percent' => $percent]);

        WorkspaceAlerts::fire(WorkspaceAlerts::RULE_DISK, self::TARGET_TYPE, $targetId, [
            'label' => ($vm->name ?: 'LXC').' ('.$targetId.')',
            'percent' => $percent,
            'used_gb' => round($used / 1073741824, 1),
            'total_gb' => round($total / 1073741824, 1),
            'free_gb' => round(($total - $used) / 1073741824, 1),
            'threshold' => $threshold,
        ]);

        return 'high';
    }
}

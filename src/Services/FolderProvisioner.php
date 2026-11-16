<?php

namespace Nawasara\Workspace\Services;

use Nawasara\Registry\Models\Opd;
use Nawasara\Registry\Support\OpdName;
use Nawasara\Workspace\Models\Account;
use Nawasara\Workspace\Models\OpdFolder;

/**
 * One team folder per OPD that has members (T-12, FR-12), shared with the
 * OPD's group `OPD-<id>`, with its own quota (files there do not count against
 * anyone's personal quota, AC-5), and with advanced permissions managed by
 * the OPD group itself: members restrict their own sub-folders, there is no
 * OPD admin (D-03, T-13).
 *
 * Idempotent: a folder that exists is only corrected (name after an OPD
 * rename, group, quota, ACL), never recreated. A folder Nextcloud no longer
 * has is created again and its row updated.
 */
class FolderProvisioner
{
    public function __construct(private NextcloudAdminClient $nc) {}

    /**
     * @return array{opds: int, created: int, renamed: int, fixed: int, unchanged: int, failed: int}
     */
    public function run(): array
    {
        $stats = ['opds' => 0, 'created' => 0, 'renamed' => 0, 'fixed' => 0, 'unchanged' => 0, 'failed' => 0];
        $quota = $this->bytes((string) config('nawasara-workspace.quota.opd_folder_default', '50 GB'));
        $remote = $this->nc->teamFolders();

        $opdIds = Account::whereNotNull('opd_id')->distinct()->pluck('opd_id');

        foreach (Opd::whereIn('id', $opdIds)->get(['id', 'name']) as $opd) {
            $stats['opds']++;
            $group = Account::opdGroup($opd->id);
            $name = OpdName::tidy((string) $opd->name);

            try {
                $row = OpdFolder::where('opd_id', $opd->id)->first();
                $folder = $row ? ($remote[(int) $row->nc_folder_id] ?? null) : null;

                if (! $folder) {
                    $this->nc->ensureGroup($group);
                    $id = $this->nc->createTeamFolder($name);
                    $this->nc->assignTeamFolderGroup($id, $group);
                    $this->nc->setTeamFolderQuota($id, $quota);
                    $this->nc->enableTeamFolderAcl($id, $group);
                    OpdFolder::updateOrCreate(['opd_id' => $opd->id], ['nc_folder_id' => $id, 'name' => $name, 'quota_bytes' => $quota]);
                    $stats['created']++;

                    continue;
                }

                $id = (int) $row->nc_folder_id;
                $changed = false;

                if ($folder['mount_point'] !== $name) {
                    $this->nc->renameTeamFolder($id, $name);
                    $stats['renamed']++;
                    $changed = true;
                }
                if (! array_key_exists($group, $folder['groups'])) {
                    $this->nc->ensureGroup($group);
                    $this->nc->assignTeamFolderGroup($id, $group);
                    $changed = true;
                }
                if ($folder['quota'] !== (int) $row->quota_bytes) {
                    $this->nc->setTeamFolderQuota($id, (int) $row->quota_bytes);
                    $changed = true;
                }
                if (! $folder['acl']) {
                    $this->nc->enableTeamFolderAcl($id, $group);
                    $changed = true;
                }

                $row->update(['name' => $name]);
                if (! $changed) {
                    $stats['unchanged']++;
                } elseif ($folder['mount_point'] === $name) {
                    $stats['fixed']++; // corrected something other than the name
                }
            } catch (NextcloudException) {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /** "50 GB" → bytes. */
    private function bytes(string $size): int
    {
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*(TB|GB|MB)\s*$/i', $size, $m) !== 1) {
            throw new \InvalidArgumentException("Ukuran kuota tidak dikenal: {$size}");
        }

        return (int) round((float) $m[1] * match (strtoupper($m[2])) { 'TB' => 1024 ** 4, 'GB' => 1024 ** 3, 'MB' => 1024 ** 2 });
    }
}

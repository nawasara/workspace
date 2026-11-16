<?php

namespace Nawasara\Workspace\Services;

use Nawasara\Registry\Models\Opd;
use Nawasara\Registry\Support\OpdName;
use Nawasara\Workspace\Models\Account;
use Nawasara\Workspace\Models\OpdFolder;
use Nawasara\Workspace\Models\QuotaOverride;
use Nawasara\Workspace\Support\QuotaSize;

/**
 * One team folder per OPD that has members (T-12, FR-12), shared with the
 * OPD's group `OPD-<id>`, with its own quota (files there do not count against
 * anyone's personal quota, AC-5; 50 GB unless Penyimpangan Kuota has an
 * exception for the OPD, T-14), and with advanced permissions managed by
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
        $default = QuotaSize::bytes(QuotaOverride::defaultFor(QuotaOverride::TYPE_OPD));
        $overrides = QuotaOverride::opds()->pluck('quota_bytes', 'opd_id')->all();
        $remote = $this->nc->teamFolders();

        $opdIds = Account::whereNotNull('opd_id')->distinct()->pluck('opd_id');

        foreach (Opd::whereIn('id', $opdIds)->get(['id', 'name']) as $opd) {
            $stats['opds']++;
            $group = Account::opdGroup($opd->id);
            $name = OpdName::tidy((string) $opd->name);
            $quota = (int) ($overrides[$opd->id] ?? $default);

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
                // Compared with what Nextcloud reports, not the row: an
                // exception set, changed or removed lands on the next run.
                if ($folder['quota'] !== $quota) {
                    $this->nc->setTeamFolderQuota($id, $quota);
                    $changed = true;
                }
                if (! $folder['acl']) {
                    $this->nc->enableTeamFolderAcl($id, $group);
                    $changed = true;
                }

                $row->update(['name' => $name, 'quota_bytes' => $quota]);
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
}

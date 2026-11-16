<?php

namespace Nawasara\Workspace\Services\Audit;

use Illuminate\Support\Carbon;

/**
 * Turns one admin_audit JSON line into an access event, or null when the
 * line is not one FR-20 asks for.
 *
 * Message formats are copied from the sources Kisara Workspace runs
 * (Nextcloud 31 `apps/admin_audit`, groupfolders 19 `lib/ACL/RuleManager`),
 * not guessed:
 *
 *   login    Login successful: "<uid>"                     (AuthEventListener)
 *   open     File with id "<id>" accessed: "<path>"        (Actions\Files::read)
 *   create   File with id "<id>" created: "<path>"         (Files::create)
 *            File id copied from: "<id>" to "<id>", path from "<p>" to "<p>"  (Files::copy)
 *   update   File with id "<id>" written to: "<path>"      (Files::write)
 *            File renamed with id "<id>" from "<p>" to "<p>"  (Files::afterRename)
 *   share    The <type> "<path>" with ID "<id>" has been shared to the user|group "<x>"
 *              with permissions "<n>"  (Share ID: <id>)     (SharingEventListener)
 *            The permissions of the shared <type> "<path>" with ID "<id>" have been
 *              changed to "<n>"                            (Actions\Sharing)
 *   unshare  The <type> "<path>" with ID "<id>" has been unshared [from the user|group "<x>"]
 *              (Share ID: <id>)
 *   delete   File with id "<id>" deleted: "<path>"         (Files::delete, to the trash)
 *            File "<path>" deleted from trash bin.         (Actions\Trashbin, permanent)
 *   restore  File "<path>" restored from trash bin.        (Trashbin::restore)
 *            Version "<v>" of "<path>" was restored.       (Actions\Versions)
 *   acl_change  A new ACL rule was created | The ACL rule was updated to permission "<n>"
 *              and mask "<n>" for file/folder with id "<id>" for user|group "<x>"
 *            The ACL rule was deleted for file/folder with id: "<id>" for the user|group "<x>"
 *
 * Deliberately ignored: login attempt / failed, logout, preview and cache
 * entry lines (noise: one per thumbnail, one per file the scanner sees),
 * user/group/app management, console commands, link password / expiry,
 * version deleted.
 */
class AuditLogParser
{
    private const SHARE_KIND = ['user' => 'Pengguna', 'group' => 'Grup', 'email recipient' => 'Email', 'room' => 'Ruang'];

    private const PERMS = [1 => 'baca', 2 => 'ubah', 4 => 'buat', 8 => 'hapus', 16 => 'bagikan'];

    /**
     * @return array<string, mixed>|null  columns of AuditEvent, plus
     *         `merge_write` (true for "written to": see AuditIngestor)
     */
    public function parse(string $line): ?array
    {
        $entry = json_decode(trim($line), true);
        if (! is_array($entry) || ! is_string($entry['message'] ?? null)) {
            return null;
        }
        if (isset($entry['app']) && $entry['app'] !== 'admin_audit') {
            return null;
        }

        $event = $this->match($entry['message']);
        if ($event === null) {
            return null;
        }

        try {
            // Nextcloud writes ATOM with an offset; store UTC (wall clock
            // values with an offset are a known trap with Eloquent).
            $occurred = Carbon::parse((string) ($entry['time'] ?? ''))->utc();
        } catch (\Throwable) {
            return null;
        }

        $user = (string) ($entry['user'] ?? '--');
        $event['nip'] ??= ($user === '--' || $user === '') ? null : $user;
        $agent = (string) ($entry['userAgent'] ?? '--');

        return $event + [
            'occurred_at' => $occurred,
            'ip' => $this->cut((string) ($entry['remoteAddr'] ?? ''), 45) ?: null,
            'user_agent' => $agent === '--' ? null : $this->cut($agent, 255),
            'req_id' => $this->cut((string) ($entry['reqId'] ?? ''), 64) ?: null,
        ];
    }

    private function match(string $m): ?array
    {
        if (preg_match('/^Login successful: "(.*)"$/s', $m, $x)) {
            return $this->event('login', ['nip' => $x[1]]);
        }
        if (preg_match('/^File with id "(\d+)" (accessed|created|written to|deleted): "(.*)"$/s', $m, $x)) {
            $action = ['accessed' => 'open', 'created' => 'create', 'written to' => 'update', 'deleted' => 'delete'][$x[2]];

            return $this->event($action, [
                'file_id' => (int) $x[1],
                'path' => $x[3],
                'detail' => $action === 'delete' ? 'Dipindah ke Sampah' : null,
                'merge_write' => $x[2] === 'written to',
            ]);
        }
        if (preg_match('/^File renamed with id "(\d+)" from "(.*)" to "(.*)"$/s', $m, $x)) {
            return $this->event('update', [
                'file_id' => (int) $x[1], 'path' => $x[3], 'detail' => 'Ganti nama atau pindah',
                'before' => $this->cut($x[2], 255), 'after' => $this->cut($x[3], 255),
            ]);
        }
        if (preg_match('/^File id copied from: "(\d+)" to "(\d+)", path from "(.*)" to "(.*)"$/s', $m, $x)) {
            return $this->event('create', ['file_id' => (int) $x[2], 'path' => $x[4], 'detail' => $this->cut('Salinan dari '.$x[3], 255)]);
        }
        if (preg_match('/^File "(.*)" deleted from trash bin\.$/s', $m, $x)) {
            return $this->event('delete', ['path' => $x[1], 'detail' => 'Dihapus permanen dari Sampah']);
        }
        if (preg_match('/^File "(.*)" restored from trash bin\.$/s', $m, $x)) {
            return $this->event('restore', ['path' => $x[1], 'detail' => 'Dipulihkan dari Sampah']);
        }
        if (preg_match('/^Version "(.*)" of "(.*)" was restored\.$/s', $m, $x)) {
            $v = ctype_digit($x[1]) ? Carbon::createFromTimestampUTC((int) $x[1])->timezone('Asia/Jakarta')->format('d M Y H:i').' WIB' : $x[1];

            return $this->event('restore', ['path' => $x[2], 'detail' => $this->cut('Versi '.$v.' dipulihkan', 255)]);
        }
        if (preg_match('/^The \w+ "(.*)" with ID "(\d+)" has been shared (?:via link|to the ([a-z ]+?) "(.*)") with permissions "(\d+)"\s+\(Share ID: (\d+)\)$/s', $m, $x)) {
            return $this->event('share', [
                'file_id' => (int) $x[2], 'path' => $x[1],
                'target' => $this->target($x[3] ?? '', $x[4] ?? ''),
                'after' => $this->perms((int) $x[5]),
                'subject_key' => 'share:'.$x[6],
            ]);
        }
        if (preg_match('/^The permissions of the shared \w+ "(.*)" with ID "(\d+)" have been changed to "(\d+)"$/s', $m, $x)) {
            // No share id in this line, so the previous value is not
            // looked up: with several recipients it would be a guess.
            return $this->event('share', [
                'file_id' => (int) $x[2], 'path' => $x[1], 'detail' => 'Izin bagian diubah',
                'after' => $this->perms((int) $x[3]),
            ]);
        }
        if (preg_match('/^The \w+ "(.*)" with ID "(\d+)" has been unshared(?: from the ([a-z ]+?) "(.*)")? \(Share ID: (\d+)\)$/s', $m, $x)) {
            return $this->event('unshare', [
                'file_id' => (int) $x[2], 'path' => $x[1],
                'target' => $this->target($x[3] ?? '', $x[4] ?? ''),
                'subject_key' => 'share:'.$x[5],
            ]);
        }
        if (preg_match('/^(A new ACL rule was created|The ACL rule was updated) to permission "(\d+)" and mask "(\d+)" for file\/folder with id "(\d+)" for (user|group) "(.*)"$/s', $m, $x)) {
            return $this->event('acl_change', [
                'file_id' => (int) $x[4],
                'target' => $this->target($x[5], $x[6]),
                'detail' => str_starts_with($x[1], 'A new') ? 'Aturan akses baru' : 'Aturan akses diubah',
                'after' => $this->acl((int) $x[2], (int) $x[3]),
                'subject_key' => $this->cut('acl:'.$x[4].':'.$x[5].':'.$x[6], 191),
            ]);
        }
        if (preg_match('/^The ACL rule was deleted for file\/folder with id: "(\d+)" for the (user|group) "(.*)"$/s', $m, $x)) {
            return $this->event('acl_change', [
                'file_id' => (int) $x[1],
                'target' => $this->target($x[2], $x[3]),
                'detail' => 'Aturan akses dihapus',
                'after' => 'Ikut folder induk',
                'subject_key' => $this->cut('acl:'.$x[1].':'.$x[2].':'.$x[3], 191),
            ]);
        }

        return null;
    }

    private function event(string $action, array $f): array
    {
        $path = $f['path'] ?? null;

        return [
            'action' => $action,
            'nip' => isset($f['nip']) ? $this->cut($f['nip'], 64) : null,
            'file_id' => $f['file_id'] ?? null,
            'file_path' => $path === null ? null : $this->cut($path, 1000),
            'file_name' => $path === null ? null : ($this->cut(basename($path), 191) ?: null),
            'target' => $f['target'] ?? null,
            'detail' => $f['detail'] ?? null,
            'before' => $f['before'] ?? null,
            'after' => $f['after'] ?? null,
            'subject_key' => $f['subject_key'] ?? null,
            'merge_write' => $f['merge_write'] ?? false,
        ];
    }

    private function target(string $kind, string $name): string
    {
        if ($kind === '') {
            return 'Tautan publik';
        }

        return $this->cut((self::SHARE_KIND[$kind] ?? ucfirst($kind)).' '.$name, 255);
    }

    /** Share permission bits (OCP\Constants::PERMISSION_*) as words. */
    public function perms(int $bits): string
    {
        $words = [];
        foreach (self::PERMS as $bit => $word) {
            if ($bits & $bit) {
                $words[] = $word;
            }
        }

        return $words ? implode(', ', $words) : 'tanpa izin';
    }

    /**
     * Groupfolders ACL: bits in `mask` are set by this rule (allowed if
     * also in `permissions`, denied otherwise); bits outside the mask
     * follow the parent folder.
     */
    public function acl(int $permissions, int $mask): string
    {
        $allow = [];
        $deny = [];
        foreach (self::PERMS as $bit => $word) {
            if ($mask & $bit) {
                ($permissions & $bit) ? $allow[] = $word : $deny[] = $word;
            }
        }

        $parts = [];
        if ($allow) {
            $parts[] = 'Izinkan: '.implode(', ', $allow);
        }
        if ($deny) {
            $parts[] = 'Tolak: '.implode(', ', $deny);
        }

        return $parts ? implode('; ', $parts) : 'Ikut folder induk';
    }

    private function cut(string $s, int $max): string
    {
        return mb_substr($s, 0, $max);
    }
}

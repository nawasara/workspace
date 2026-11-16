<?php

namespace Nawasara\Workspace\Services;

use Illuminate\Support\Facades\Log;
use Nawasara\Workspace\Models\Template;
use ZipArchive;

/**
 * Template files in the team folder "Templat Kisara", written with the
 * Nextcloud admin account (control plane; Workspace Web only reads the folder
 * as the employee, ADR 0003).
 *
 * Every method either finishes the Nextcloud side or throws
 * NextcloudException before the caller touches the database row, so a row
 * never points at a file that was not written.
 */
class TemplateFiles
{
    public function __construct(private NextcloudAdminClient $nc) {}

    public function configured(): bool
    {
        return $this->nc->isConfigured();
    }

    /**
     * Why this upload is refused, or null when it is a usable OOXML file of
     * the given extension. Checked on the content, not only the name: a
     * renamed PDF would otherwise reach every employee's ribbon.
     */
    public static function rejectReason(string $localPath, string $ext, int $size): ?string
    {
        $maxBytes = (int) config('nawasara-workspace.templates.max_upload_mb', 20) * 1024 * 1024;
        if (! isset(Template::MIME[$ext])) {
            return 'Berkas harus .docx, .xlsx, atau .pptx.';
        }
        if ($size <= 0) {
            return 'Berkas kosong.';
        }
        if ($size > $maxBytes) {
            return 'Berkas melebihi '.config('nawasara-workspace.templates.max_upload_mb', 20).' MB.';
        }

        $zip = new ZipArchive;
        if ($zip->open($localPath) !== true) {
            return 'Berkas bukan dokumen Office yang sah.';
        }
        $types = (string) $zip->getFromName('[Content_Types].xml');
        $main = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'][$ext];
        // Zips rarely carry directory entries, so look for any file under it.
        $ok = $types !== '' && self::hasPrefix($zip, $main);
        $zip->close();

        return $ok ? null : 'Isi berkas tidak cocok dengan .'.$ext.'.';
    }

    /**
     * Upload a new template file under a free name derived from `$name`.
     *
     * @return array{file_name: string, file_id: int, size_bytes: int}
     */
    public function store(string $name, string $ext, string $contents): array
    {
        $fileName = $this->freeName($name, $ext);
        $path = Template::folder().'/'.$fileName;

        $this->nc->uploadFile($path, $contents, Template::MIME[$ext]);
        $id = $this->nc->fileId($path);
        if ($id === null) {
            throw new NextcloudException("Berkas {$path} tidak ditemukan setelah diunggah.");
        }
        Log::info('workspace.templates.file_stored', ['file' => $fileName, 'bytes' => strlen($contents)]);

        return ['file_name' => $fileName, 'file_id' => $id, 'size_bytes' => strlen($contents)];
    }

    /**
     * New content for an existing template, same path. Nextcloud keeps the
     * file id on overwrite; it is read again anyway in case the file had been
     * removed by hand in the meantime.
     *
     * @return array{file_id: int, size_bytes: int}
     */
    public function replace(Template $template, string $contents): array
    {
        $this->nc->uploadFile($template->path(), $contents, Template::MIME[$template->extension()]);
        $id = $this->nc->fileId($template->path());
        if ($id === null) {
            throw new NextcloudException("Berkas {$template->path()} tidak ditemukan setelah diunggah.");
        }
        Log::info('workspace.templates.file_replaced', ['template' => $template->id, 'bytes' => strlen($contents)]);

        return ['file_id' => $id, 'size_bytes' => strlen($contents)];
    }

    /**
     * Remove the template's own file from the template folder. Files that
     * employees created from it are copies in their Drive / Folder OPD and are
     * not touched (FR-16 AC-5, FR-05 AC-4). Already gone is fine.
     */
    public function delete(Template $template): void
    {
        $deleted = $this->nc->deleteFile($template->path());
        Log::info('workspace.templates.file_deleted', ['template' => $template->id, 'was_present' => $deleted]);
    }

    /** "Nota Dinas Bupati.docx", or "... (2).docx" when that name is taken. */
    public function freeName(string $name, string $ext): string
    {
        $base = self::cleanName($name);
        $taken = fn (string $f) => Template::where('file_name', $f)->exists()
            || $this->nc->fileId(Template::folder().'/'.$f) !== null;

        $candidate = "{$base}.{$ext}";
        for ($n = 2; $taken($candidate); $n++) {
            $candidate = "{$base} ({$n}).{$ext}";
        }

        return $candidate;
    }

    /** A name that is safe as a Nextcloud file name. */
    public static function cleanName(string $name): string
    {
        $clean = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $name) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '', " .\t");
        $clean = mb_substr($clean, 0, 100);

        return $clean === '' ? 'Templat' : $clean;
    }

    private static function hasPrefix(ZipArchive $zip, string $prefix): bool
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with((string) $zip->getNameIndex($i), $prefix)) {
                return true;
            }
        }

        return false;
    }
}

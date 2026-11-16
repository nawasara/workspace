<?php

namespace Nawasara\Workspace\Services\Audit;

/** admin_audit log readable as a local file (see AuditLogSource). */
class FileAuditLogSource implements AuditLogSource
{
    public function __construct(private readonly string $path) {}

    public function name(): string
    {
        return 'file';
    }

    public function isConfigured(): bool
    {
        return $this->path !== '';
    }

    public function probe(): array
    {
        clearstatcache(true, $this->path);
        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new \RuntimeException("Log admin_audit tidak dapat dibaca: {$this->path}");
        }

        $size = (int) filesize($this->path);
        $head = $this->read(0, min($size, 64 * 1024));

        return ['size' => $size, 'fingerprint' => self::fingerprint($head)];
    }

    public function read(int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $fh = @fopen($this->path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("Log admin_audit tidak dapat dibuka: {$this->path}");
        }

        try {
            fseek($fh, $offset);
            $data = fread($fh, $length);
        } finally {
            fclose($fh);
        }

        return $data === false ? '' : $data;
    }

    /** sha1 of the first complete line, null if there is none yet. */
    public static function fingerprint(string $head): ?string
    {
        $nl = strpos($head, "\n");

        return $nl === false ? null : sha1(substr($head, 0, $nl));
    }
}

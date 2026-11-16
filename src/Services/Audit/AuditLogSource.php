<?php

namespace Nawasara\Workspace\Services\Audit;

/**
 * Where the Nextcloud admin_audit log is read from (T-36).
 *
 * The log lives on the Workspace host (LXC 145), Nawasara on another, and
 * nothing ships raw log files between them today (the nawasara-agent sends
 * detected incidents, not log lines). So the source is pluggable:
 *
 *  - FileAuditLogSource: the log is readable as a local path (mounted
 *    volume, rsync, or Nawasara and Nextcloud on one host in a test).
 *  - HttpAuditLogSource: the log is served read-only over HTTP with Range
 *    support (e.g. an nginx location on LXC 145, internal port, token and
 *    source-IP restricted). No new access for Nawasara: it already reaches
 *    Nextcloud's internal URL.
 *
 * Both are byte-offset readers over an append-only file, which is what
 * makes the job resumable and rotation-safe.
 */
interface AuditLogSource
{
    /** Stable id for the cursor row, e.g. "file" or "http". */
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * Current state of the log without reading its body: total size in
     * bytes and sha1 of its first complete line (null while the log has
     * no complete line yet).
     *
     * @return array{size: int, fingerprint: ?string}
     */
    public function probe(): array;

    /**
     * Raw bytes from $offset, at most $length. May return fewer bytes;
     * the caller only consumes complete lines.
     */
    public function read(int $offset, int $length): string;
}

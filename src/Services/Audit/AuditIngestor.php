<?php

namespace Nawasara\Workspace\Services\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nawasara\Workspace\Models\AuditCursor;
use Nawasara\Workspace\Models\AuditEvent;

/**
 * Reads new admin_audit lines from a source into AuditEvent rows (T-36).
 *
 * Resumable: the byte offset of the last complete line is saved after
 * every chunk, in the same transaction as that chunk's rows, so a run that
 * dies halfway continues where the last chunk ended.
 *
 * Idempotent: a row is keyed by sha256 of its raw line, so re-reading
 * (cursor reset after rotation, a retried run) inserts nothing twice.
 */
class AuditIngestor
{
    public function __construct(private readonly AuditLogParser $parser) {}

    /**
     * @return array{read_bytes: int, lines: int, inserted: int, duplicates: int, ignored: int, merged: int, reset: bool, offset: int, size: int}
     */
    public function run(AuditLogSource $source, int $maxBytes, int $chunkBytes): array
    {
        $stats = ['read_bytes' => 0, 'lines' => 0, 'inserted' => 0, 'duplicates' => 0, 'ignored' => 0, 'merged' => 0, 'reset' => false, 'offset' => 0, 'size' => 0];

        $cursor = AuditCursor::firstOrCreate(['source' => $source->name()], ['offset' => 0]);
        $probe = $source->probe();
        $stats['size'] = $probe['size'];

        $rotated = $cursor->fingerprint !== null && $probe['fingerprint'] !== null && $cursor->fingerprint !== $probe['fingerprint'];
        if ($rotated || $probe['size'] < $cursor->offset) {
            Log::info('workspace audit: log rotated or truncated, reading from the start', [
                'source' => $source->name(), 'offset' => $cursor->offset, 'size' => $probe['size'],
            ]);
            $cursor->offset = 0;
            $stats['reset'] = true;
        }
        $cursor->fingerprint = $probe['fingerprint'] ?? $cursor->fingerprint;

        $offset = $cursor->offset;
        $chunkBytes = max(4096, $chunkBytes);

        while ($offset < $probe['size'] && $stats['read_bytes'] < $maxBytes) {
            $data = $source->read($offset, min($chunkBytes, $probe['size'] - $offset));
            if ($data === '') {
                break;
            }

            $lastNl = strrpos($data, "\n");
            if ($lastNl === false) {
                if (strlen($data) < $chunkBytes) {
                    break; // the last line is still being written: next run
                }
                // A single line longer than a chunk is not an audit line;
                // step over it instead of being stuck on it forever.
                Log::warning('workspace audit: skipped an oversize line', ['offset' => $offset]);
                $consumed = strlen($data);
                $lines = [];
            } else {
                $consumed = $lastNl + 1;
                $lines = explode("\n", substr($data, 0, $lastNl));
            }

            DB::transaction(function () use ($lines, $cursor, $offset, $consumed, &$stats) {
                $this->ingest($lines, $stats);
                $cursor->offset = $offset + $consumed;
                $cursor->last_read_at = now();
                $cursor->last_error = null;
                $cursor->save();
            });

            $offset += $consumed;
            $stats['read_bytes'] += $consumed;
        }

        $cursor->last_read_at = now();
        $cursor->save();
        $stats['offset'] = $offset;

        return $stats;
    }

    /** @param list<string> $lines */
    private function ingest(array $lines, array &$stats): void
    {
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
        $stats['lines'] += count($lines);
        if ($lines === []) {
            return;
        }

        $hashes = array_map(fn ($l) => hash('sha256', rtrim($l, "\r")), $lines);
        $known = array_flip(AuditEvent::whereIn('raw_hash', array_unique($hashes))->pluck('raw_hash')->all());

        foreach ($lines as $i => $line) {
            $hash = $hashes[$i];
            if (isset($known[$hash])) {
                $stats['duplicates']++;

                continue;
            }
            $known[$hash] = true;

            $event = $this->parser->parse($line);
            if ($event === null) {
                $stats['ignored']++;

                continue;
            }

            // Nextcloud logs "created" AND "written to" for one upload, and
            // "written to" again for each save in the same request. One
            // request, one file = one row.
            $mergeWrite = $event['merge_write'];
            unset($event['merge_write']);
            if ($mergeWrite && $event['req_id'] !== null && $event['file_id'] !== null
                && AuditEvent::where('req_id', $event['req_id'])->where('file_id', $event['file_id'])
                    ->whereIn('action', ['create', 'update'])->exists()) {
                $stats['merged']++;

                continue;
            }

            // admin_audit logs only the new value; the old one is the
            // previous event about the same share or ACL rule.
            if ($event['subject_key'] !== null && $event['before'] === null) {
                $event['before'] = AuditEvent::where('subject_key', $event['subject_key'])
                    ->orderByDesc('occurred_at')->orderByDesc('id')->value('after');
            }

            // ACL lines carry only the file id: borrow the path from the
            // last event that named it.
            if ($event['file_path'] === null && $event['file_id'] !== null) {
                $knownPath = AuditEvent::where('file_id', $event['file_id'])->whereNotNull('file_path')
                    ->orderByDesc('occurred_at')->orderByDesc('id')->first(['file_path', 'file_name']);
                if ($knownPath) {
                    $event['file_path'] = $knownPath->file_path;
                    $event['file_name'] = $knownPath->file_name;
                }
            }

            AuditEvent::create($event + ['raw_hash' => $hash]);
            $stats['inserted']++;
        }
    }

    /** Delete events older than $days, in batches so one run never locks the table long. */
    public function prune(int $days, int $batch = 5000): int
    {
        $before = now()->subDays(max(1, $days));
        $deleted = 0;

        do {
            $ids = AuditEvent::where('occurred_at', '<', $before)->orderBy('id')->limit($batch)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += AuditEvent::whereIn('id', $ids)->delete();
        } while ($ids->count() === $batch);

        return $deleted;
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak Akses (T-36, FR-20): one row per access event read from the
     * Nextcloud admin_audit log. Kept one year (AC-3), then pruned daily.
     *
     * bigint, not UUID: internal log, never referenced from outside, and
     * it is the kind of table that grows to millions of rows (AGENTS §10a).
     *
     * `raw_hash` = sha256 of the raw log line: re-reading the same bytes
     * (cursor reset after rotation, a retried run) never doubles a row.
     *
     * `subject_key` ties events about the same share or ACL rule together,
     * so the next change can fill `before` from the previous `after`
     * (admin_audit only logs the new value).
     *
     * Paths can exceed an indexable length, so per-file search uses
     * `file_id` (exact) and `file_name` (indexed basename); the full path
     * is kept for display and a LIKE fallback.
     */
    public function up(): void
    {
        Schema::create('nawasara_workspace_audit_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at');
            $table->string('action', 16); // login|create|open|update|share|unshare|delete|restore|acl_change
            $table->string('nip', 64)->nullable(); // Nextcloud uid = NIP; null = system (cron, occ)
            $table->unsignedBigInteger('file_id')->nullable();
            $table->string('file_path', 1000)->nullable();
            $table->string('file_name', 191)->nullable();
            $table->string('target', 255)->nullable();  // share recipient or ACL user/group
            $table->string('detail', 255)->nullable();
            $table->string('before', 255)->nullable();
            $table->string('after', 255)->nullable();
            $table->string('subject_key', 191)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('req_id', 64)->nullable();
            $table->char('raw_hash', 64)->unique();
            $table->timestamp('created_at')->nullable();

            $table->index(['nip', 'occurred_at']);
            $table->index(['file_id', 'occurred_at']);
            $table->index(['file_name', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
            $table->index('occurred_at');
            $table->index(['subject_key', 'occurred_at']);
            $table->index(['req_id', 'file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_workspace_audit_events');
    }
};

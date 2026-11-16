<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where IngestAuditLogJob stopped reading, per source (T-36).
     *
     * `offset` = bytes of the log already consumed (complete lines only).
     * `fingerprint` = sha1 of the log's first line: if it changes, or the
     * log is shorter than `offset`, the file was rotated or truncated and
     * reading restarts at 0. Rows already stored are skipped by raw_hash,
     * so a restart is safe.
     *
     * Not in nawasara_workspace_settings on purpose: saving a Setting
     * triggers the pilot group sync.
     */
    public function up(): void
    {
        Schema::create('nawasara_workspace_audit_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64)->unique();
            $table->unsignedBigInteger('offset')->default(0);
            $table->string('fingerprint', 40)->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_workspace_audit_cursors');
    }
};

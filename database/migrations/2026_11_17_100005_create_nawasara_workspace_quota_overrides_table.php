<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quota exceptions to the defaults in config (FR-13 AC-1, T-14): one row
     * per employee (by NIP) or per OPD folder (by opd_id). No row = default.
     * Deleting a row is how an exception ends; the next sync puts the default
     * back, and the activity log keeps what it was and who removed it.
     *
     * One target per row: `target_type` says which of `nip` / `opd_id` is
     * set. Both are unique (NULLs do not collide), so a person or an OPD has
     * at most one exception.
     *
     * bigint: internal, never referenced from outside (AGENTS §10a).
     */
    public function up(): void
    {
        Schema::create('nawasara_workspace_quota_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('target_type', 16); // pegawai | opd
            $table->string('nip', 32)->nullable()->unique();
            $table->foreignId('opd_id')->nullable()->unique()->constrained('nawasara_registry_opd')->cascadeOnDelete();
            $table->string('name'); // employee name at the time it was set, for the list
            $table->unsignedBigInteger('quota_bytes');
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('target_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_workspace_quota_overrides');
    }
};

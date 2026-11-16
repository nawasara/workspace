<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Departed employees' Workspace accounts (T-33, FR-18): one row per
     * departure. Disabled in Keycloak -> account disabled in Nextcloud and
     * held `hold_days` (90) -> warning 7 days before -> account and Drive
     * Saya deleted. Re-enabled before the deadline -> cancelled.
     *
     * A row is never removed: it is the record of what happened to the
     * account (who moved the files where, who deleted it, when).
     *
     * `nc_disabled` = this row currently holds the Nextcloud account
     * disabled. At most one such row per NIP: it is how the offboarding job
     * knows a departure is already being handled, and how the account sync
     * knows to keep its hands off (it would otherwise rewrite the account).
     *
     * bigint: internal, never referenced from outside (AGENTS §10a).
     */
    public function up(): void
    {
        Schema::create('nawasara_workspace_offboarding', function (Blueprint $table) {
            $table->id();
            $table->string('nip', 32);
            $table->string('display_name');
            $table->foreignId('opd_id')->nullable()->constrained('nawasara_registry_opd')->nullOnDelete();
            $table->string('status', 16); // held | cancelled | deleted
            $table->boolean('nc_disabled')->default(false);

            $table->timestamp('disabled_at');
            $table->timestamp('hold_until');
            $table->timestamp('warned_at')->nullable();

            $table->timestamp('moved_at')->nullable();
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('moved_to', 500)->nullable();
            $table->unsignedInteger('moved_items')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 16)->nullable(); // reenabled | manual

            $table->timestamp('deleted_at_nc')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete(); // null = by schedule

            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['nip', 'nc_disabled']);
            $table->index(['status', 'hold_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_workspace_offboarding');
    }
};

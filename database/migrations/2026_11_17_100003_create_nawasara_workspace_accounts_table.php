<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What Nawasara last wrote to Nextcloud for each ASN account (T-10, T-11).
     *
     * Exists so a provisioning run only calls Nextcloud for accounts that are
     * new or changed: `state_hash` covers name, e-mail, quota and OPD group.
     * Without it every run would ask Nextcloud about ~11 000 people.
     *
     * Nextcloud stays the source of truth for the account itself; this is a
     * record of what was sent, and a row is rewritten if the hash differs.
     *
     * bigint: internal (AGENTS §10a). The UID in Nextcloud is the NIP.
     */
    public function up(): void
    {
        Schema::create('nawasara_workspace_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('nip', 32)->unique();
            $table->string('keycloak_id', 64)->nullable()->index();
            $table->string('display_name');
            $table->string('email')->nullable();
            $table->string('quota', 32);
            $table->foreignId('opd_id')->nullable()->constrained('nawasara_registry_opd')->nullOnDelete();
            $table->string('state_hash', 64);
            $table->timestamp('nc_created_at')->nullable();
            $table->timestamp('nc_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index('opd_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_workspace_accounts');
    }
};

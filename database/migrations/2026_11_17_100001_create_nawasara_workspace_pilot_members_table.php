<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who may enter Kisara Workspace during the pilot (FR-02). The single
     * source of truth for both doors: the access API Workspace Web asks
     * (T-06) and the Keycloak group `workspace-pilot` Nextcloud checks (T-07).
     *
     * bigint, not UUID: internal, never referenced from outside (AGENTS §10a).
     *
     * `removed_at` = revoked ("cabut"): the row and its history stay, the
     * person can be restored. Deleting the row is for a wrong entry only.
     * Revoking never touches the person's files (FR-02 AC-4).
     *
     * `opd_id` is optional: by default the OPD comes from SIMAS (Keycloak
     * attribute simas_skpd, T-11). When set here it wins, for the pilot cases
     * where SIMAS is not yet right.
     */
    public function up(): void
    {
        Schema::create('nawasara_workspace_pilot_members', function (Blueprint $table) {
            $table->id();
            $table->string('nip', 32)->unique();
            $table->string('name');
            $table->string('keycloak_id', 64)->nullable()->index();
            $table->unsignedTinyInteger('stage')->default(1); // 0 owner, 1 Diskominfo, 2 OPD pilot
            $table->foreignId('opd_id')->nullable()->constrained('nawasara_registry_opd')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable()->index();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_workspace_pilot_members');
    }
};

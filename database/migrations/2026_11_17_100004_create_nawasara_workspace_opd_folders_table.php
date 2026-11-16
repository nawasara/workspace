<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One Nextcloud team folder per OPD (FR-12, T-12). The group is named by
     * OPD id (`OPD-<id>`), the folder by the tidy OPD name, so renaming an OPD
     * renames the folder and breaks nothing.
     */
    public function up(): void
    {
        Schema::create('nawasara_workspace_opd_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opd_id')->unique()->constrained('nawasara_registry_opd')->cascadeOnDelete();
            $table->unsignedBigInteger('nc_folder_id')->unique();
            $table->string('name');
            $table->unsignedBigInteger('quota_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_workspace_opd_folders');
    }
};

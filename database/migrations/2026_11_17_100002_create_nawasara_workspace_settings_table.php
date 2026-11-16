<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Small key/value settings for Workspace: `kill_switch` (FR-02 AC-4) and
     * `pilot_open` (stage 3: every ASN may enter). In the database, not in
     * config, because an admin flips them from the panel and both doors must
     * see the change within a minute without a deploy.
     */
    public function up(): void
    {
        Schema::create('nawasara_workspace_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_workspace_settings');
    }
};

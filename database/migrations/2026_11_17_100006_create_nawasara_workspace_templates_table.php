<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Template metadata (FR-16, T-31). The file itself lives in the team
     * folder "Templat Kisara" in Nextcloud (`file_name` inside it); this row
     * says who sees it in the "Mulai ... baru" ribbon and in which order.
     *
     * UUID: the id leaves Nawasara (Workspace Web keeps it, and it appears in
     * the admin screen's URLs), AGENTS §10a. HasUuids gives ordered v7 ids.
     *
     * `builtin_key` is set only for the 14 built-in templates (T-21): the
     * seeder finds its row by it on every run, so a rename by an admin is not
     * mistaken for a missing template.
     */
    public function up(): void
    {
        Schema::create('nawasara_workspace_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('app', 16);                 // docs | sheets | slides
            $table->string('scope', 8)->default('all'); // all | opd
            $table->foreignId('opd_id')->nullable()->constrained('nawasara_registry_opd')->nullOnDelete();
            $table->string('status', 16)->default('draft'); // draft | active | inactive
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('file_name')->unique();     // inside the "Templat Kisara" folder
            $table->unsignedBigInteger('file_id')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('builtin_key', 64)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The ribbon query: active, one app, ordered.
            $table->index(['status', 'app', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_workspace_templates');
    }
};

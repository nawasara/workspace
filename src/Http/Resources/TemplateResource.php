<?php

namespace Nawasara\Workspace\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nawasara\Workspace\Models\Template;

/**
 * Allow-list of a template (AGENTS §10a). Every key is named here.
 *
 * File location: `path` (relative to the employee's own Nextcloud root, e.g.
 * "Templat Kisara/Nota Dinas.docx") is what Workspace Web needs to copy the
 * template with the employee's token (WebDAV COPY), and `file_id` is what it
 * needs for the preview thumbnail. Both are visible to every employee in
 * Nextcloud anyway; nothing about the admin account or server is exposed.
 * Who created or changed it stays out: Workspace Web has no use for it.
 *
 * @mixin Template
 */
class TemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Template $t */
        $t = $this->resource;

        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'app' => $t->app,
            'extension' => $t->extension(),
            'scope' => $t->scope,
            'opd' => $t->opd_id !== null && $t->opd ? ['id' => (int) $t->opd->id, 'name' => $t->opd->name] : null,
            'status' => $t->status,
            'sort_order' => $t->sort_order,
            'builtin' => $t->isBuiltin(),
            'path' => $t->path(),
            'file_id' => $t->file_id,
            'size_bytes' => $t->size_bytes,
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }
}

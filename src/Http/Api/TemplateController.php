<?php

namespace Nawasara\Workspace\Http\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Nawasara\Keycloak\Models\KeycloakUser;
use Nawasara\Workspace\Http\Resources\TemplateResource;
use Nawasara\Workspace\Models\Template;
use Nawasara\Workspace\Services\NextcloudException;
use Nawasara\Workspace\Services\OpdResolver;
use Nawasara\Workspace\Services\TemplateFiles;
use Nawasara\Workspace\Services\WorkspaceAdmins;
use Spatie\Activitylog\Facades\CauserResolver;
use Throwable;

/**
 * Templates for Workspace Web (FR-15, FR-16, T-31).
 *
 * Read (scope workspace.template.read):
 *   GET    /templates?nip=&app=            the "Mulai ... baru" ribbon of one employee
 *
 * Kelola Templat (scope workspace.template.manage, plus `actor`):
 *   GET    /templates/manage?actor=&app=&status=
 *   POST   /templates                      multipart: actor, name, description, scope, opd_id, sort_order, file
 *   PATCH  /templates/{id}                 actor + any of name, description, scope, opd_id, status, sort_order
 *   POST   /templates/{id}/file            multipart: actor, file (new content, same template)
 *   DELETE /templates/{id}?actor=
 *
 * Who acts: Workspace Web passes `actor` = the NIP of the signed-in
 * employee, and every write is refused (403 not_admin) unless that NIP is
 * admin Kisara (WorkspaceAdmins, the same rule as `is_admin` in the access
 * API). The scope alone is not enough: it only proves the caller is
 * Workspace Web, not that the person clicking is an admin (FR-16 AC-1, D-03).
 */
class TemplateController extends Controller
{
    // ─── Ribbon ─────────────────────────────────────────

    /**
     * Active templates for every OPD plus the employee's own OPD (FR-16
     * AC-4), ordered. An admin Kisara also sees drafts, of any scope, so a
     * draft can be tried before it is published (FR-16 AC-3). Inactive
     * templates are never in the ribbon.
     */
    public function index(Request $request, OpdResolver $opds, WorkspaceAdmins $admins): JsonResponse
    {
        $nip = (string) $request->query('nip', '');
        if (preg_match('/^\d{18}$/', $nip) !== 1) {
            return $this->error(422, 'invalid_nip', 'nip harus NIP 18 digit.');
        }
        $app = $request->query('app');
        if ($app !== null && ! isset(Template::EXTENSIONS[$app])) {
            return $this->error(422, 'invalid_app', 'app harus docs, sheets, atau slides.');
        }

        $kc = KeycloakUser::where('username', $nip)->first();
        $opdId = $kc ? $opds->forKeycloakUser($kc) : $opds->resolve($nip, null);
        $drafts = $admins->isAdmin($nip);

        $query = Template::query()->with('opd:id,name')
            ->where(function ($q) use ($opdId, $drafts) {
                $q->where(fn ($v) => $v->visibleTo($opdId));
                if ($drafts) {
                    $q->orWhere('status', Template::STATUS_DRAFT);
                }
            })
            ->when($app, fn ($q) => $q->where('app', $app))
            ->ribbonOrder();

        return response()->json([
            'data' => TemplateResource::collection($query->get())->resolve(),
            'meta' => ['nip' => $nip, 'app' => $app, 'opd_id' => $opdId, 'drafts_included' => $drafts],
        ]);
    }

    // ─── Kelola Templat ─────────────────────────────────

    /** Every template, drafts and inactive included, for the admin screen. */
    public function manage(Request $request, WorkspaceAdmins $admins): JsonResponse
    {
        $actor = $this->actor($request, $admins);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }

        $app = $request->query('app');
        $status = $request->query('status');
        if ($app !== null && ! isset(Template::EXTENSIONS[$app])) {
            return $this->error(422, 'invalid_app', 'app harus docs, sheets, atau slides.');
        }
        if ($status !== null && ! in_array($status, Template::STATUSES, true)) {
            return $this->error(422, 'invalid_status', 'status harus draft, active, atau inactive.');
        }

        $rows = Template::query()->with('opd:id,name')
            ->when($app, fn ($q) => $q->where('app', $app))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->ribbonOrder()
            ->get();

        return response()->json([
            'data' => TemplateResource::collection($rows)->resolve(),
            'meta' => ['total' => $rows->count(), 'max_upload_mb' => (int) config('nawasara-workspace.templates.max_upload_mb', 20)],
        ]);
    }

    /** Upload a new template; it starts as a draft (FR-16 AC-2). */
    public function store(Request $request, WorkspaceAdmins $admins, TemplateFiles $files): JsonResponse
    {
        $actor = $this->actor($request, $admins);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }

        $v = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'scope' => ['required', Rule::in([Template::SCOPE_ALL, Template::SCOPE_OPD])],
            'opd_id' => ['nullable', 'required_if:scope,'.Template::SCOPE_OPD, 'integer', 'exists:nawasara_registry_opd,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'file' => ['required', 'file'],
        ], $this->messages());
        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $upload = $request->file('file');
        [$ext, $reject] = $this->checkUpload($upload);
        if ($reject !== null) {
            return $this->invalid(['file' => [$reject]]);
        }
        if (! $files->configured()) {
            return $this->error(503, 'nextcloud_not_configured', 'Koneksi Nextcloud belum diatur di Nawasara.');
        }

        $app = Template::appForExtension($ext);
        $data = $v->validated();
        $stored = null;

        try {
            $stored = $files->store($data['name'], $ext, (string) file_get_contents($upload->getRealPath()));
            CauserResolver::setCauser($actor);
            $template = Template::create([
                'name' => trim($data['name']),
                'description' => $this->nullIfBlank($data['description'] ?? null),
                'app' => $app,
                'scope' => $data['scope'],
                'opd_id' => $data['scope'] === Template::SCOPE_OPD ? (int) $data['opd_id'] : null,
                'status' => Template::STATUS_DRAFT,
                'sort_order' => isset($data['sort_order']) ? (int) $data['sort_order'] : $this->nextSortOrder($app),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ] + $stored);
        } catch (NextcloudException $e) {
            return $this->nextcloudFailed('store', $e);
        } catch (Throwable $e) {
            // The file went up but the row did not: take the file back, so
            // the folder never holds a template nobody can manage.
            if ($stored !== null) {
                $this->cleanupOrphan($files, $stored['file_name']);
            }
            throw $e;
        }

        Log::info('workspace.templates.created', ['template' => $template->id, 'app' => $app, 'scope' => $template->scope, 'actor_user' => $actor->getKey()]);

        return response()->json(['data' => (new TemplateResource($template->load('opd:id,name')))->resolve()], 201);
    }

    /** Metadata and status (draft / active / inactive). The file is not touched. */
    public function update(Request $request, string $id, WorkspaceAdmins $admins): JsonResponse
    {
        $actor = $this->actor($request, $admins);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }
        $template = $this->find($id);
        if ($template === null) {
            return $this->error(404, 'not_found', 'Templat tidak ditemukan.');
        }

        $v = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'scope' => ['sometimes', 'required', Rule::in([Template::SCOPE_ALL, Template::SCOPE_OPD])],
            'opd_id' => ['sometimes', 'nullable', 'integer', 'exists:nawasara_registry_opd,id'],
            'status' => ['sometimes', 'required', Rule::in(Template::STATUSES)],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:65535'],
        ], $this->messages());
        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }
        $data = $v->validated();

        $scope = $data['scope'] ?? $template->scope;
        $opdId = array_key_exists('opd_id', $data) ? $data['opd_id'] : $template->opd_id;
        if ($scope === Template::SCOPE_OPD && $opdId === null) {
            return $this->invalid(['opd_id' => ['OPD wajib dipilih untuk templat satu OPD.']]);
        }

        $changes = array_intersect_key($data, array_flip(['name', 'description', 'status', 'sort_order']));
        if (isset($changes['name'])) {
            $changes['name'] = trim($changes['name']);
        }
        if (array_key_exists('description', $changes)) {
            $changes['description'] = $this->nullIfBlank($changes['description']);
        }
        $changes['scope'] = $scope;
        $changes['opd_id'] = $scope === Template::SCOPE_OPD ? (int) $opdId : null;
        $changes['updated_by'] = $actor->getKey();

        CauserResolver::setCauser($actor);
        $template->update($changes);
        Log::info('workspace.templates.updated', ['template' => $template->id, 'fields' => array_keys($data), 'actor_user' => $actor->getKey()]);

        return response()->json(['data' => (new TemplateResource($template->fresh()->load('opd:id,name')))->resolve()]);
    }

    /** New content for the same template (Ganti berkas, FR-16 AC-5). */
    public function replaceFile(Request $request, string $id, WorkspaceAdmins $admins, TemplateFiles $files): JsonResponse
    {
        $actor = $this->actor($request, $admins);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }
        $template = $this->find($id);
        if ($template === null) {
            return $this->error(404, 'not_found', 'Templat tidak ditemukan.');
        }
        if ($template->isBuiltin()) {
            return $this->error(409, 'builtin_template', 'Berkas templat bawaan dikelola dari paket Nawasara, tidak dapat diganti di sini.');
        }

        $v = Validator::make($request->all(), ['file' => ['required', 'file']], $this->messages());
        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }
        $upload = $request->file('file');
        [$ext, $reject] = $this->checkUpload($upload);
        if ($reject === null && $ext !== $template->extension()) {
            $reject = 'Berkas pengganti harus .'.$template->extension().', sama dengan templat ini.';
        }
        if ($reject !== null) {
            return $this->invalid(['file' => [$reject]]);
        }
        if (! $files->configured()) {
            return $this->error(503, 'nextcloud_not_configured', 'Koneksi Nextcloud belum diatur di Nawasara.');
        }

        try {
            $stored = $files->replace($template, (string) file_get_contents($upload->getRealPath()));
        } catch (NextcloudException $e) {
            return $this->nextcloudFailed('replace', $e);
        }

        CauserResolver::setCauser($actor);
        $template->update($stored + ['updated_by' => $actor->getKey()]);
        // Same size and id would make the row look unchanged: touch it so
        // updated_at says the content changed.
        $template->touch();

        return response()->json(['data' => (new TemplateResource($template->fresh()->load('opd:id,name')))->resolve()]);
    }

    /**
     * Remove the template: its row and its own file in "Templat Kisara".
     * Files employees already made from it are separate copies and stay
     * (FR-16 AC-5). The confirmation is asked by Workspace Web.
     */
    public function destroy(Request $request, string $id, WorkspaceAdmins $admins, TemplateFiles $files): JsonResponse
    {
        $actor = $this->actor($request, $admins);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }
        $template = $this->find($id);
        if ($template === null) {
            return $this->error(404, 'not_found', 'Templat tidak ditemukan.');
        }
        if ($template->isBuiltin()) {
            return $this->error(409, 'builtin_template', 'Templat bawaan tidak dapat dihapus; nonaktifkan saja.');
        }
        if (! $files->configured()) {
            return $this->error(503, 'nextcloud_not_configured', 'Koneksi Nextcloud belum diatur di Nawasara.');
        }

        // File first: if Nextcloud fails the row stays, so the admin can
        // retry instead of leaving a file no screen can reach.
        try {
            $files->delete($template);
        } catch (NextcloudException $e) {
            return $this->nextcloudFailed('delete', $e);
        }

        CauserResolver::setCauser($actor);
        $template->delete();
        Log::info('workspace.templates.deleted', ['template' => $template->id, 'actor_user' => $actor->getKey()]);

        return response()->json(['data' => ['id' => $template->id, 'deleted' => true]]);
    }

    // ─── Helpers ────────────────────────────────────────

    /** The admin's local account, or the error response to return. */
    private function actor(Request $request, WorkspaceAdmins $admins): Model|JsonResponse
    {
        $nip = $request->input('actor');
        if (! is_string($nip) || preg_match('/^\d{18}$/', $nip) !== 1) {
            return $this->error(422, 'invalid_actor', 'actor harus NIP 18 digit.');
        }
        $user = $admins->userForNip($nip);
        if ($user === null) {
            Log::warning('workspace.templates.write_refused', ['reason' => 'not_admin', 'path' => $request->path()]);

            return $this->error(403, 'not_admin', 'Hanya admin Kisara yang dapat mengelola templat.');
        }

        return $user;
    }

    private function find(string $id): ?Template
    {
        return Str::isUuid($id) ? Template::find($id) : null;
    }

    /** @return array{0: string, 1: ?string} extension, reason to refuse */
    private function checkUpload(?UploadedFile $upload): array
    {
        if ($upload === null || ! $upload->isValid()) {
            return ['', 'Berkas gagal diunggah.'];
        }
        $ext = strtolower(pathinfo((string) $upload->getClientOriginalName(), PATHINFO_EXTENSION));

        return [$ext, TemplateFiles::rejectReason((string) $upload->getRealPath(), $ext, (int) $upload->getSize())];
    }

    private function nextSortOrder(string $app): int
    {
        return ((int) Template::where('app', $app)->max('sort_order')) + 10;
    }

    private function nullIfBlank(?string $s): ?string
    {
        return $s === null || trim($s) === '' ? null : trim($s);
    }

    private function cleanupOrphan(TemplateFiles $files, string $fileName): void
    {
        try {
            $files->delete(new Template(['file_name' => $fileName]));
        } catch (Throwable $e) {
            Log::error('workspace.templates.orphan_file', ['file' => $fileName, 'reason' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }

    private function nextcloudFailed(string $stage, NextcloudException $e): JsonResponse
    {
        Log::error('workspace.templates.nextcloud_failed', ['stage' => $stage, 'status' => $e->httpStatus, 'reason' => mb_substr($e->getMessage(), 0, 300)]);

        return $this->error(502, 'nextcloud_unavailable', 'Nextcloud tidak dapat menyimpan berkas templat. Coba lagi sebentar.');
    }

    private function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'required_if' => ':attribute wajib diisi.',
            'max' => ':attribute terlalu panjang.',
            'integer' => ':attribute harus angka.',
            'in' => ':attribute tidak dikenal.',
            'exists' => ':attribute tidak ditemukan.',
            'file' => ':attribute harus berkas.',
            'min' => ':attribute terlalu kecil.',
        ];
    }

    private function invalid(array $fields): JsonResponse
    {
        return response()->json(['error' => ['code' => 'validation_failed', 'message' => 'Isian tidak valid.', 'fields' => $fields]], 422);
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}

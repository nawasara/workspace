<?php

namespace Nawasara\Workspace\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Nawasara\Registry\Models\Opd;
use Nawasara\Workspace\Http\Resources\AccessResource;
use Nawasara\Workspace\Services\AccessDecider;
use Nawasara\Workspace\Services\WorkspaceAdmins;

/**
 * GET /api/v1/workspace/access/{nip}   scope: workspace.access.read
 *
 * Asked by Workspace Web on every new session and every 60 seconds after
 * (FR-02 AC-1, AC-3). Always 200 with `allowed`: a refusal is an answer, not
 * an error, and Workspace Web shows its own "sedang uji coba" page for it.
 * Only a missing or wrong token is an error (401/403, from middleware).
 */
class AccessController extends Controller
{
    public function show(string $nip, AccessDecider $decider): JsonResponse
    {
        $decision = $decider->decide($nip);

        $opd = $decision['opd_id'] ? Opd::find($decision['opd_id']) : null;

        return response()->json([
            'data' => (new AccessResource($decision + ['opd' => $opd, 'is_admin' => $this->isAdmin($nip, $decision['allowed'])]))->resolve(),
        ]);
    }

    /**
     * Admin Kisara (WorkspaceAdmins: local account with this NIP holding
     * `workspace.template.manage`), for Kelola Templat in Workspace Web
     * (T-32). Never true for someone who may not enter at all.
     */
    private function isAdmin(string $nip, bool $allowed): bool
    {
        return $allowed && app(WorkspaceAdmins::class)->isAdmin($nip);
    }
}

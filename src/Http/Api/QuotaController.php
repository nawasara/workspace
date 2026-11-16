<?php

namespace Nawasara\Workspace\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Nawasara\Workspace\Http\Resources\QuotaResource;
use Nawasara\Workspace\Services\QuotaReader;

/**
 * GET /api/v1/workspace/quota/{nip}   scope: workspace.quota.read
 *
 * Drive + Mail usage for the Penyimpanan page of Kisara Akun (FR-14, T-29).
 * 200 even when a source failed: that section says `unavailable` and the
 * other is intact (FR-14 AC-3). `drive` is null for someone who is not a
 * pilot participant, `mail` is null for someone without a personal mailbox
 * (FR-14 AC-4). Only a NIP that is not 18 digits is an error (422).
 */
class QuotaController extends Controller
{
    public function show(string $nip, QuotaReader $reader): JsonResponse
    {
        if (preg_match('/^\d{18}$/', $nip) !== 1) {
            return response()->json(['error' => ['code' => 'invalid_nip', 'message' => 'nip harus NIP 18 digit.']], 422);
        }

        return response()->json([
            'data' => (new QuotaResource($reader->forNip($nip)))->resolve(),
            'meta' => ['warning_percent' => (int) config('nawasara-workspace.quota_api.warning_percent', 80)],
        ]);
    }
}

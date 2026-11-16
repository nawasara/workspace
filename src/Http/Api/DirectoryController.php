<?php

namespace Nawasara\Workspace\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;
use Nawasara\Registry\Models\Opd;
use Nawasara\Workspace\Http\Resources\DirectoryOpdResource;
use Nawasara\Workspace\Http\Resources\DirectoryPersonResource;
use Nawasara\Workspace\Services\DirectorySearch;

/**
 * GET /api/v1/workspace/directory?q=&limit=&actor=   scope: workspace.directory.read
 *
 * Search behind Bagikan and @mention in Workspace Web (FR-09, FR-10, T-25).
 * `q` is a name (every word must match) or a NIP prefix (digits only), at
 * least 3 characters. `actor` is the NIP of the employee typing, passed by
 * Workspace Web so the rate limit holds per employee and not only per
 * service token (NFR-01): one employee cannot exhaust everyone's budget.
 *
 * Two limits, both per minute, both from config: per token (the whole
 * Workspace Web) and per actor. 429 with Retry-After when exceeded.
 */
class DirectoryController extends Controller
{
    public function index(Request $request, DirectorySearch $search): JsonResponse
    {
        $q = DirectorySearch::clean((string) $request->query('q', ''));
        $actor = $request->query('actor');
        $max = max(1, (int) config('nawasara-workspace.directory.max_limit', 20));
        $limit = $request->filled('limit') ? (int) $request->query('limit') : (int) config('nawasara-workspace.directory.default_limit', 10);
        $limit = min(max(1, $limit), $max);

        if (mb_strlen($q) < DirectorySearch::MIN_QUERY) {
            return $this->error(422, 'query_too_short', 'Kata kunci minimal '.DirectorySearch::MIN_QUERY.' karakter.');
        }
        if ($actor !== null && (! is_string($actor) || preg_match('/^\d{18}$/', $actor) !== 1)) {
            return $this->error(422, 'invalid_actor', 'actor harus NIP 18 digit.');
        }

        if ($limited = $this->throttle($request, $actor)) {
            return $limited;
        }

        $people = $search->people($q, $limit);
        $opdIds = array_values(array_unique(array_filter(array_column($people, 'opd_id'))));
        $opdById = $opdIds ? Opd::query()->whereIn('id', $opdIds)->get(['id', 'name'])->keyBy('id') : collect();

        $people = array_map(fn ($p) => $p + ['opd' => $p['opd_id'] ? $opdById->get($p['opd_id']) : null], $people);

        return response()->json([
            'data' => [
                'people' => DirectoryPersonResource::collection($people)->resolve(),
                'opds' => DirectoryOpdResource::collection($search->opds($q, min($limit, 10)))->resolve(),
            ],
            'meta' => ['q' => $q, 'limit' => $limit],
        ]);
    }

    private function throttle(Request $request, ?string $actor): ?JsonResponse
    {
        $token = $request->attributes->get('api_token');
        $keys = [
            'workspace-directory:token:'.($token?->id ?? $request->ip()) => (int) config('nawasara-workspace.directory.per_token_per_minute', 600),
        ];
        if ($actor !== null) {
            $keys['workspace-directory:actor:'.$actor] = (int) config('nawasara-workspace.directory.per_actor_per_minute', 60);
        }

        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return $this->error(429, 'rate_limited', 'Terlalu banyak pencarian. Coba lagi sebentar.')
                    ->header('Retry-After', (string) RateLimiter::availableIn($key));
            }
        }
        foreach (array_keys($keys) as $key) {
            RateLimiter::hit($key, 60);
        }

        return null;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}

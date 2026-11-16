<?php

namespace Nawasara\Workspace\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Allow-list of what Workspace Web learns about a person (AGENTS §10a).
 * Every key is named here; nothing is passed through, so a future field on
 * the decision cannot leak by default. No name, e-mail or phone: Workspace
 * Web already has the name from the login token.
 */
class AccessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'nip' => $r['nip'],
            'allowed' => $r['allowed'],
            'reason' => $r['reason'],
            'stage' => $r['stage'],
            'opd' => $r['opd'] ? ['id' => $r['opd']->id, 'name' => $r['opd']->name] : null,
            'is_admin' => $r['is_admin'],
        ];
    }
}

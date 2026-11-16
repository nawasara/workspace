<?php

namespace Nawasara\Workspace\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Allow-list of what the directory says about a person (FR-09 AC-5,
 * AGENTS §10a). Every key is named here. Never email, phone/WhatsApp or any
 * other Keycloak attribute: the directory is visible to every ASN.
 *
 * `nip` is included on purpose: it is the Nextcloud UID that Workspace Web
 * needs to share with or @mention this person (ADR 0005).
 */
class DirectoryPersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'nip' => $r['nip'],
            'name' => $r['name'],
            'jabatan' => $r['jabatan'],
            'unit' => $r['unit'],
            'opd' => $r['opd'] ? ['id' => (int) $r['opd']->id, 'name' => (string) $r['opd']->name] : null,
        ];
    }
}

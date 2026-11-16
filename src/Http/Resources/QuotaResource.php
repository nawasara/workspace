<?php

namespace Nawasara\Workspace\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Allow-list of the Penyimpanan answer (AGENTS §10a). Every key is named;
 * a section is either null (no card) or always carries the same keys, with
 * nulls when `status` is `unavailable`, so Kisara Akun never has to guess
 * which keys exist.
 */
class QuotaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'nip' => $r['nip'],
            'drive' => $r['drive'] === null ? null : $this->section($r['drive']),
            'mail' => $r['mail'] === null ? null : ['address' => $r['mail']['address']] + $this->section($r['mail']),
        ];
    }

    private function section(array $s): array
    {
        return [
            'status' => $s['status'],
            'used_bytes' => $s['used_bytes'],
            'quota_bytes' => $s['quota_bytes'],
            'used_percent' => $s['used_percent'],
            'level' => $s['level'],
        ];
    }
}

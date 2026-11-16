<?php

namespace Nawasara\Workspace\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nawasara\Workspace\Models\Account;

/**
 * An OPD to share with as a whole (FR-09 AC-1). `group` is the Nextcloud
 * group id Workspace Web passes as share target (shareType 1). Allow-list:
 * the registry row also holds address, phone and e-mail, none of which
 * belongs here.
 */
class DirectoryOpdResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->id,
            'name' => (string) $this->resource->name,
            'group' => Account::opdGroup((int) $this->resource->id),
        ];
    }
}

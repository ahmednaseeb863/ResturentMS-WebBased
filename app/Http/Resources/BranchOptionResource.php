<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** Small branch shape for pickers and the branch switcher. */
class BranchOptionResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Support\Activity;
use Illuminate\Http\Request;

class ActivityLogResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'event' => $this->event,
            'module' => $this->subjectName(),
            'subject' => $this->subject_label,
            'description' => $this->description,
            'properties' => $this->cleanProperties($this->properties ?? []),
            'admin' => $this->ref('admin'),
            'branch' => $this->ref('branch'),
            'ip_address' => $this->ip_address,
            'created_at' => static::iso($this->created_at),
        ];
    }

    /** Defensive: strip ids / foreign keys / secrets even if a caller logged them. */
    private function cleanProperties(array $properties): array
    {
        return collect($properties)
            ->map(fn ($value) => is_array($value) && ! array_is_list($value) ? Activity::clean($value) : $value)
            ->all();
    }
}

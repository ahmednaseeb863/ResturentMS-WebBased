<?php

namespace App\Http\Resources;

use App\Models\PrintJob;
use Illuminate\Http\Request;

/** A print job in the print queue. Load `printer`, `createdBy`, `printedBy`. */
class PrintJobResource extends Resource
{
    public function toArray(Request $request): array
    {
        /** @var PrintJob $job */
        $job = $this->resource;

        return [
            'id' => $job->uuid,
            'title' => $job->title,
            'document' => ['value' => $job->document_type->value, 'label' => $job->document_type->label()],
            'status' => ['value' => $job->status->value, 'label' => $job->status->label(), 'tone' => $job->status->tone()],
            'printer' => $this->ref('printer'),
            'copies' => $job->copies,
            'attempts' => $job->attempts,
            'error' => $job->error,
            'created_by' => $job->relationLoaded('createdBy') ? $job->createdBy?->name : null,
            'printed_by' => $job->relationLoaded('printedBy') ? $job->printedBy?->name : null,
            'created_at' => static::iso($job->created_at),
            'printed_at' => static::iso($job->printed_at),
        ];
    }
}

<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;

/** `image` column holding a path on the public disk (old files are kept when replaced). */
trait HasImage
{
    public function imageUrl(): ?string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }
}

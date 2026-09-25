<?php

namespace App\Http\Requests\Concerns;

/** `image` upload + `remove_image` flag. Old files stay on the disk (only unlinked). */
trait HandlesImage
{
    protected function imageRules(): array
    {
        return [
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_image' => ['boolean'],
        ];
    }

    /** ['image' => new path] / ['image' => null] / [] (unchanged) */
    public function imageData(string $folder): array
    {
        if ($this->hasFile('image')) {
            return ['image' => $this->file('image')->store($folder, 'public')];
        }

        return $this->boolean('remove_image') ? ['image' => null] : [];
    }
}

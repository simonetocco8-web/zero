<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class InventoryPhotos
{
    public function store(UploadedFile $file): array
    {
        $mime = $file->getMimeType();
        $format = match ($mime) {
            'image/jpeg' => 'jpeg','image/png' => 'png','image/webp' => 'webp',default => null
        };
        $size = @getimagesize($file->getRealPath());
        if (! $format || ! $size || $size[0] > 20000 || $size[1] > 20000 || $size[0] * $size[1] > 40000000) {
            throw ValidationException::withMessages(['photos' => 'Foto non valida o troppo grande in pixel (massimo 40 megapixel).']);
        }
        $temporary = tempnam(sys_get_temp_dir(), 'zero-photo-');
        try {
            $process = new Process([config('inventory.image_binary'), '-limit', 'memory', '128MiB', '-limit', 'map', '256MiB', '-limit', 'disk', '512MiB', $format.':'.$file->getRealPath().'[0]', '-auto-orient', '-resize', '1800x1800>', '-background', 'white', '-alpha', 'remove', '-alpha', 'off', '-strip', '-quality', '85', 'jpeg:'.$temporary]);
            $process->setTimeout(30);
            $process->run();
            if (! $process->isSuccessful()) {
                throw ValidationException::withMessages(['photos' => 'La foto non può essere elaborata. Scegli un altro file.']);
            }
            $path = 'inventory/'.Str::uuid().'.jpg';
            if (! Storage::disk('local')->put($path, file_get_contents($temporary))) {
                throw new \RuntimeException('Unable to store inventory photo.');
            }

            return ['disk' => 'local', 'path' => $path];
        } finally {
            @unlink($temporary);
        }
    }

    public function delete(array $photos): void
    {
        foreach ($photos as $photo) {
            Storage::disk($photo['disk'])->delete($photo['path']);
        }
    }
}

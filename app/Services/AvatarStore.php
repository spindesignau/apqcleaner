<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Centre-crops an uploaded image to a square, resizes it and stores it as JPEG on the private disk.
 * Re-encoding with GD also strips EXIF data (including GPS) from phone photos.
 */
class AvatarStore
{
    public function store(User $user, UploadedFile $file): string
    {
        $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $src) {
            throw ValidationException::withMessages(['avatar' => __('validation.image', ['attribute' => __('profile.avatar')])]);
        }

        $src = $this->applyExifOrientation($src, $file);
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $size = min((int) config('apq.avatars.size'), $side);

        $dst = imagecreatetruecolor($size, $size);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $size, $size, $side, $side);

        ob_start();
        imagejpeg($dst, null, 85);
        $jpeg = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        $disk = Storage::disk(config('apq.avatars.disk'));
        $path = config('apq.avatars.directory').'/'.$user->id.'-'.Str::random(12).'.jpg';
        $disk->put($path, $jpeg);

        if ($user->avatar_path && $user->avatar_path !== $path) {
            $disk->delete($user->avatar_path);
        }

        return $path;
    }

    private function applyExifOrientation(\GdImage $img, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            return $img;
        }
        $orientation = @exif_read_data($file->getRealPath())['Orientation'] ?? 1;

        return match ((int) $orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
    }
}

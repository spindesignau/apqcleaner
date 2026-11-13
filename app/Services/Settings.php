<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Typed key/value settings stored in the `settings` table, with defaults from config/apq.php.
 */
class Settings
{
    private const CACHE_KEY = 'apq.settings';

    public function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::pluck('value', 'key')->all());

        return array_key_exists($key, $all) ? $all[$key] : config('apq.defaults.'.$key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }
}

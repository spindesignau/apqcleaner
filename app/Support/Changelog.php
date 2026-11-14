<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Reads CHANGELOG.md (admin-only "What's new" page). Cached per file modification time.
 */
class Changelog
{
    private function path(): string
    {
        return base_path('CHANGELOG.md');
    }

    private function raw(): string
    {
        return is_file($this->path()) ? (string) file_get_contents($this->path()) : '';
    }

    /** Latest version heading, e.g. "0.6.0" from "## [0.6.0] – in progress". */
    public function version(): ?string
    {
        $mtime = @filemtime($this->path()) ?: 0;

        return Cache::remember("changelog.version.{$mtime}", 86400, function () {
            return preg_match('/^##\s*\[([^\]]+)\]/m', $this->raw(), $m) ? $m[1] : null;
        });
    }

    public function html(): string
    {
        // The file is written by the developers (not user input); raw HTML in it is still stripped.
        return Str::markdown($this->raw(), ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }
}

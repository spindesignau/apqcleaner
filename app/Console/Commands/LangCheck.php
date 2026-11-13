<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/**
 * Fails when a translation key exists in one language but not the other.
 */
class LangCheck extends Command
{
    protected $signature = 'apq:lang:check';

    protected $description = 'Check that every English translation key has a Spanish translation (and vice versa)';

    public function handle(): int
    {
        $locales = array_keys(config('apq.locales'));
        $keys = [];
        foreach ($locales as $locale) {
            foreach (File::files(lang_path($locale)) as $file) {
                $group = $file->getFilenameWithoutExtension();
                foreach (array_keys(Arr::dot(require $file->getPathname())) as $k) {
                    $keys[$locale][] = "{$group}.{$k}";
                }
            }
            $json = lang_path("{$locale}.json");
            if (File::exists($json)) {
                foreach (array_keys(json_decode(File::get($json), true) ?? []) as $k) {
                    $keys[$locale][] = "json:{$k}";
                }
            }
        }

        $problems = 0;
        foreach ($locales as $a) {
            foreach ($locales as $b) {
                if ($a === $b) {
                    continue;
                }
                foreach (array_diff($keys[$a], $keys[$b]) as $missing) {
                    $this->line("<error>missing in {$b}</error> {$missing}");
                    $problems++;
                }
            }
        }

        if ($problems === 0) {
            $this->info('All translation keys present in: '.implode(', ', $locales));

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}

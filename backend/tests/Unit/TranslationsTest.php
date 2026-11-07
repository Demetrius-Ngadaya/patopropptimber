<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards the Swahili translation: every message the code can show must have
 * a Swahili version, with exactly the same :placeholders.
 */
class TranslationsTest extends TestCase
{
    private function base(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, string> key => first file it appears in */
    private function keysInCode(): array
    {
        $keys = [];
        foreach (['app', 'bootstrap', 'resources/views', 'routes'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->base().'/'.$dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $code = file_get_contents($file->getPathname());
                preg_match_all("/(?<![A-Za-z_>:])__\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*[,)]/", $code, $m);
                // Bell notifications store English text and translate it when shown (StaffNotice title/body).
                preg_match_all("/\\b(?:title|body): '((?:[^'\\\\]|\\\\.)*)'/", $code, $n);
                foreach (array_merge($m[1], $n[1]) as $raw) {
                    $keys[stripcslashes($raw)] ??= $file->getFilename();
                }
            }
        }

        return $keys;
    }

    public function test_every_message_has_a_swahili_translation(): void
    {
        $sw = json_decode(file_get_contents($this->base().'/lang/sw.json'), true, flags: JSON_THROW_ON_ERROR);
        $missing = array_diff_key($this->keysInCode(), $sw);

        $this->assertSame([], $missing, "Add these keys to lang/sw.json:\n".implode("\n", array_keys($missing)));
    }

    public function test_placeholders_match_between_languages(): void
    {
        $sw = json_decode(file_get_contents($this->base().'/lang/sw.json'), true, flags: JSON_THROW_ON_ERROR);
        $bad = [];
        foreach ($sw as $en => $translation) {
            preg_match_all('/:[a-z_]+\d*/', $en, $a);
            preg_match_all('/:[a-z_]+\d*/', $translation, $b);
            if (array_unique($a[0]) != array_unique($b[0]) && count(array_diff($a[0], $b[0])) + count(array_diff($b[0], $a[0])) > 0) {
                $bad[] = $en;
            }
        }

        $this->assertSame([], $bad, "Placeholder mismatch in lang/sw.json:\n".implode("\n", $bad));
    }
}

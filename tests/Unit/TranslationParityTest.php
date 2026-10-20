<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Plan G1/B4: en.json and bn.json must always carry identical key sets —
 * a missing key on either side fails the build.
 */
class TranslationParityTest extends TestCase
{
    private function keys(string $locale): array
    {
        $path = __DIR__.'/../../lang/'.$locale.'.json';
        $this->assertFileExists($path);
        $data = json_decode(file_get_contents($path), true);
        $this->assertIsArray($data, "lang/{$locale}.json must decode to an object");
        $keys = array_keys($data);
        sort($keys);

        return $keys;
    }

    public function test_english_and_bangla_have_identical_key_sets(): void
    {
        $this->assertSame($this->keys('en'), $this->keys('bn'));
    }

    public function test_no_empty_translation_values(): void
    {
        foreach (['en', 'bn'] as $locale) {
            $data = json_decode(file_get_contents(__DIR__.'/../../lang/'.$locale.'.json'), true);
            foreach ($data as $key => $value) {
                $this->assertNotSame('', trim((string) $value), "Empty translation: {$locale}.{$key}");
            }
        }
    }

    public function test_starter_keys_exist(): void
    {
        $en = json_decode(file_get_contents(__DIR__.'/../../lang/en.json'), true);
        foreach (['welcome', 'dashboard', 'login', 'logout', 'save', 'search', 'cancel', 'delete', 'edit', 'create', 'print', 'export', 'settings', 'language'] as $key) {
            $this->assertArrayHasKey($key, $en);
        }
        $bn = json_decode(file_get_contents(__DIR__.'/../../lang/bn.json'), true);
        $this->assertSame('স্বাগতম', $bn['welcome']);
    }
}

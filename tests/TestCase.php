<?php

namespace E9li\Fire\Tests;

use Kirby\Cms\App;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public const BASE = 'https://example.test';

    protected string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/kirby-fire-tests/' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->tmp);
    }

    /**
     * Boots a Kirby app on a throwaway index root with the shared content
     * fixtures. The site root points into the tmp dir, so no config and no
     * other plugins leak into the test.
     */
    protected function app(array $props = []): App
    {
        return new App(array_replace_recursive([
            'roots' => [
                'index' => $this->tmp,
                'content' => __DIR__ . '/fixtures/content',
                'site' => $this->tmp . '/site',
            ],
            'urls' => [
                'index' => static::BASE,
            ],
        ], $props));
    }

    /** Language set used by the multilang tests: default at /, prefixed, own domain */
    protected function languages(): array
    {
        return [
            ['code' => 'en', 'name' => 'English', 'default' => true, 'url' => '/'],
            ['code' => 'de', 'name' => 'Deutsch'],
            ['code' => 'fr', 'name' => 'Français', 'url' => 'https://fr.example.test'],
        ];
    }

    private function rm(string $path): void
    {
        if (is_dir($path) === false) {
            if (file_exists($path)) {
                unlink($path);
            }

            return;
        }

        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->rm($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}

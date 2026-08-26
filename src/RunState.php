<?php

namespace E9li\Fire;

/**
 * Shared outcome state between the CLI and the Panel: which pages could not
 * be cached last time, and why. One plain JSON file directly below
 * site/cache — deliberately not a Kirby cache instance, whose host-prefixed
 * roots would split the CLI and the Panel on multi-domain sites. Cached
 * pages are never stored here: the pages cache itself is their single
 * source of truth.
 */
class RunState
{
    public static function file(): string
    {
        return kirby()->root('cache') . '/e9li.kirby-fire.json';
    }

    /**
     * id|language — page ids may contain dots, "|" appears in neither.
     */
    public static function key(string $id, ?string $language): string
    {
        return $id . '|' . ($language ?? '');
    }

    public static function load(): array
    {
        $file = static::file();

        if (is_file($file) === false) {
            return [];
        }

        $data = json_decode((string)file_get_contents($file), true);

        return is_array($data) === true ? $data : [];
    }

    /**
     * Merges changes into the stored state: an entry records a problem, an
     * explicit null clears a previous one (the page is fine now). Written
     * atomically via tmp + rename — the CLI and the Panel may report at the
     * same time.
     */
    public static function update(array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $state = static::load();

        foreach ($changes as $key => $entry) {
            if ($entry === null) {
                unset($state[$key]);
            } else {
                $state[$key] = $entry;
            }
        }

        $file = static::file();

        if (is_dir(dirname($file)) === false) {
            mkdir(dirname($file), 0777, true);
        }

        $tmp = $file . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, json_encode($state));
        rename($tmp, $file);
    }

    /**
     * Resolves the Panel row state for every Pages::urls() item: the disk
     * cache wins (fire-on), then the recorded outcome with its reason,
     * then "no fire".
     */
    public static function apply(array $items): array
    {
        $state = static::load();

        return array_map(function (array $item) use ($state): array {
            $entry = $state[static::key($item['id'], $item['language'])] ?? null;

            return [
                'url' => $item['url'],
                'id' => $item['id'],
                'language' => $item['language'],
                'isErrorPage' => $item['isErrorPage'],
                'state' => $item['cached'] === true ? 'fire-on' : ($entry['state'] ?? 'no-fire'),
                'error' => $item['cached'] === true ? null : ($entry['error'] ?? null),
            ];
        }, $items);
    }
}

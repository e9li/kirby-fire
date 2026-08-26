<?php

namespace E9li\Fire;

use FilesystemIterator;
use Kirby\Cms\Media;
use Kirby\Cms\ModelWithContent;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Kirby's pending thumbnail jobs: rendering a page does not create thumbs —
 * it writes one job file per thumb and leaves the image to the media route,
 * which runs the darkroom the first time a browser asks for the URL.
 * Collecting the jobs lets the CLI generate them in-process instead of
 * paying an HTTP round-trip per thumb.
 */
class Jobs
{
    /**
     * Every thumbnail Kirby has been asked for but has not generated yet.
     */
    public static function all(): array
    {
        $media = kirby()->root('media');

        if (is_dir($media) === false) {
            return [];
        }

        // Streamed rather than Dir::index($media, true): the media folder
        // holds one file per thumb per size, so indexing it up front would
        // build an array of every generated image just to find the handful
        // of pending jobs.
        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($media, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $jobs = [];

        foreach ($tree as $file) {
            if ($file->isFile() === false || $file->getExtension() !== 'json') {
                continue;
            }

            $path = substr($file->getPathname(), strlen($media) + 1);

            // <type>/<id…>/<hash>/.jobs/<thumb filename>.json — the id can
            // itself contain slashes (page ids do), so split from the right.
            $parts = explode('/', $path);

            if (count($parts) < 4 || $parts[count($parts) - 2] !== '.jobs') {
                continue;
            }

            $filename = substr(array_pop($parts), 0, -5);
            array_pop($parts); // .jobs
            $hash = array_pop($parts);
            $type = array_shift($parts);
            $id = implode('/', $parts);

            // Deliberately strings only: holding thousands of resolved page
            // models here kept their loaded files collections alive for the
            // whole run and exhausted shared-hosting memory limits. thumb()
            // resolves each model just in time and purges it afterwards.
            $jobs[] = [
                'type' => $type,
                'id' => $id,
                'hash' => $hash,
                'filename' => $filename,
                'path' => $path,
            ];
        }

        return $jobs;
    }

    /**
     * Generates one pending thumbnail. Media::thumb() reads the job,
     * resolves the source file, runs the darkroom and removes the job file —
     * reimplementing it here would mean duplicating its path-traversal
     * guards and drifting from core.
     */
    public static function thumb(array $job): array
    {
        $model = match ($job['type']) {
            'pages' => kirby()->page($job['id']),
            'site' => site(),
            'users' => kirby()->user($job['id']),
            // custom assets are addressed by their path relative to the index
            'assets' => $job['id'],
            default => null,
        };

        // A null model means the page/user was deleted but its media folder
        // stayed behind. Reported rather than skipped: silently dropping it
        // would read as "everything rendered" while images stay missing.
        if ($model === null) {
            return ['ok' => false, 'error' => 'no page, site or user owns this job any more'];
        }

        try {
            Media::thumb($model, $job['hash'], $job['filename']);

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            // drop the memoized files/children collections again — page
            // objects stay referenced in the site tree, and without the
            // purge their weight accumulates across thousands of jobs
            if ($model instanceof ModelWithContent) {
                $model->purge();
            }
        }
    }
}

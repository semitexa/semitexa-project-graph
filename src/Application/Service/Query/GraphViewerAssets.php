<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Query;

/**
 * The graph viewer's client: one script and one stylesheet, plain files.
 *
 * They live here, not in semitexa/dev, because both places that show the
 * viewer need them and only one of those can depend on the other: dev serves
 * them to the Observatory, and `ai:review-graph:show --format=html` inlines
 * them into a self-contained file. project-graph cannot require dev.
 */
final class GraphViewerAssets
{
    /** Everything the viewer is made of, and what each file is. */
    public const FILES = [
        'graph-view.js' => 'text/javascript; charset=utf-8',
        'graph-view.css' => 'text/css; charset=utf-8',
    ];

    public static function dir(): string
    {
        // src/Application/Service/Query → package root is four levels up.
        return dirname(__DIR__, 4) . '/resources/viewer';
    }

    /**
     * A file's contents, by its name in {@see FILES}. The name is a map key,
     * never a path fragment: anything else is null without touching the disk.
     */
    public static function read(string $name): ?string
    {
        if (!isset(self::FILES[$name])) {
            return null;
        }

        $body = @file_get_contents(self::dir() . '/' . $name);

        return $body === false ? null : $body;
    }
}

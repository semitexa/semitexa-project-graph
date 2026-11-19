<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Index;

/**
 * Which packages the project has installed, as one hash stored in the graph
 * meta beside build_version.
 *
 * Extractors read attribute INSTANCES (UiNavigationExtractor asks an AsCrud
 * for permissionFor()), and attribute classes come from installed packages.
 * A refresh re-reads only the project files that changed, so after a
 * `composer update` every unchanged file kept the facts the old attribute
 * class gave. A changed fingerprint makes the next refresh a full build.
 *
 * Known limit: a package installed from a path repository (this workspace's
 * packages/*) can change without installed.json changing; an edit to an
 * attribute class there still needs a full build by hand.
 */
final class InstalledPackages
{
    public const META = 'installed_packages';

    /** installed.json is what is on disk; composer.lock stands in before an install. Empty when the project has neither. */
    private const SOURCES = ['/vendor/composer/installed.json', '/composer.lock'];

    public static function fingerprint(string $projectRoot): string
    {
        foreach (self::SOURCES as $source) {
            $path = rtrim($projectRoot, '/') . $source;
            $hash = is_file($path) ? @hash_file('xxh3', $path) : false;
            if (is_string($hash)) {
                return $hash;
            }
        }

        return '';
    }
}

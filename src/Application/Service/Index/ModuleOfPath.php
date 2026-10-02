<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Index;

/**
 * The module a file belongs to, from its path alone: packages/<dir> is the
 * package (semitexa- dropped, StudlyCased), src/modules/<Name> a local
 * module, the rest of src/ and tests/ the app, anything else ''.
 *
 * One rule for the build (every node and indexed file carries it) and for
 * whoever must name the module of a file that declares nothing the graph
 * holds (UnreadCode: an ignored class, a shadowed duplicate).
 */
final class ModuleOfPath
{
    public static function of(string $projectRoot, string $filePath): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $normalizedPath = str_replace('\\', '/', $filePath);

        if (str_starts_with($normalizedPath, $normalizedRoot . '/packages/')) {
            $relative = substr($normalizedPath, strlen($normalizedRoot . '/packages/'));
            $package = explode('/', $relative, 2)[0];

            if ($package !== '') {
                $package = preg_replace('/^semitexa-/', '', $package) ?? $package;
                return self::studly($package);
            }
        }

        // A local module is a module: everything under src/ used to be "App"
        // (579 classes), so --module=Playground found nothing and a file moved
        // between two local modules was "no structural change".
        if (str_starts_with($normalizedPath, $normalizedRoot . '/src/modules/')) {
            $name = explode('/', substr($normalizedPath, strlen($normalizedRoot . '/src/modules/')), 2)[0];
            if ($name !== '' && str_contains(substr($normalizedPath, strlen($normalizedRoot . '/src/modules/')), '/')) {
                return $name;
            }
        }

        if (str_starts_with($normalizedPath, $normalizedRoot . '/src/')
            || str_starts_with($normalizedPath, $normalizedRoot . '/tests/')
        ) {
            return 'App';
        }

        return '';
    }

    private static function studly(string $value): string
    {
        $parts = preg_split('/[^a-zA-Z0-9]+/', $value) ?: [];
        $parts = array_filter($parts, static fn (string $part): bool => $part !== '');

        return implode('', array_map(
            static fn (string $part): string => ucfirst(strtolower($part)),
            $parts,
        ));
    }
}

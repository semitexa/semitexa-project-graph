<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor;

/**
 * The Twig namespaces of a project, read off the disk: `@Shop/page.html.twig`
 * is a file under the template directory of the module that owns `Shop`.
 *
 * The same map ModuleTemplateCatalog builds at boot (semitexa/ssr), without a
 * container: a local module's `Application/View/templates` (or legacy
 * `Layout`), then every semitexa-module or semitexa-theme package under
 * packages/ and vendor/ with its composer `template_alias` and
 * `template_paths`. Each alias is a namespace on its own and as
 * `project-layouts-<alias>`; the first module to claim a namespace keeps it.
 *
 * What it leaves out is what only a request knows: a theme's override of a
 * module's template (`src/theme/<theme>/<alias>/templates`). The edge points
 * at the module's own file, which is the one rendered when no theme says
 * otherwise.
 */
final class TemplateNamespaces
{
    /** @param array<string, string> $directories namespace => directory, no trailing slash */
    private function __construct(
        private readonly array $directories,
    ) {}

    public static function ofProject(string $projectRoot): self
    {
        $root = rtrim($projectRoot, '/');
        /** @var array<string, array{aliases: list<string>, path: string}> $modules */
        $modules = [];

        foreach (self::directories($root . '/src/modules') as $dir) {
            $name = basename($dir);
            foreach (['/Application/View/templates', '/Layout'] as $templates) {
                if (is_dir($dir . $templates)) {
                    $modules[$name] = ['aliases' => [$name], 'path' => $dir . $templates];
                    break;
                }
            }
        }

        $seen = [];
        foreach (self::registered($root) as [$path, $name, $local]) {
            $composer = self::composer($path);
            if (!$local && !in_array($composer['type'] ?? null, ['semitexa-module', 'semitexa-theme'], true)) {
                continue;
            }
            // A package linked into vendor/ is the package, not a second module.
            $real = realpath($path) ?: $path;
            if (isset($seen[$real])) {
                continue;
            }
            $seen[$real] = true;

            $extra = is_array($composer['extra'] ?? null) && is_array($composer['extra']['semitexa-module'] ?? null) ? $composer['extra']['semitexa-module'] : [];
            $aliases = [$name];
            foreach (['semitexa-module-', 'module-', 'semitexa-'] as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $aliases[] = substr($name, strlen($prefix));
                    break;
                }
            }
            if (is_string($extra['template_alias'] ?? null) && $extra['template_alias'] !== '') {
                $aliases[] = $extra['template_alias'];
            }

            $paths = [is_dir($path . '/src/Application/View/templates') ? $path . '/src/Application/View/templates' : $path . '/Application/View/templates'];
            foreach (is_array($extra['template_paths'] ?? null) ? $extra['template_paths'] : [] as $relative) {
                if (is_string($relative) && $relative !== '') {
                    $paths[] = $path . '/' . ltrim($relative, '/');
                }
            }
            // The catalog keeps one path per module: the last that exists.
            foreach ($paths as $templates) {
                if (is_dir($templates)) {
                    $modules[$name] = ['aliases' => $aliases, 'path' => rtrim($templates, '/')];
                }
            }
        }

        $directories = [];
        foreach ($modules as $name => $module) {
            $aliases = [...$module['aliases'], $name];
            if (!str_starts_with($name, 'semitexa-')) {
                $aliases[] = 'semitexa-' . $name;
            }
            foreach (array_unique(array_filter(array_map(trim(...), $aliases), static fn (string $alias): bool => $alias !== '')) as $alias) {
                $directories[$alias] ??= $module['path'];
                if (!str_starts_with($alias, 'project-layouts-')) {
                    $directories['project-layouts-' . $alias] ??= $module['path'];
                }
            }
        }

        return new self($directories);
    }

    /**
     * The file a template name stands for, or null when the name has no
     * namespace, the namespace is nobody's, or no such file is there.
     */
    public function fileOf(string $name): ?string
    {
        if (preg_match('#\A@([^/]+)/(.+)\z#s', $name, $m) !== 1 || str_contains($m[2], '..')) {
            return null;
        }
        $directory = $this->directories[$m[1]] ?? null;
        if ($directory === null) {
            return null;
        }
        $file = $directory . '/' . ltrim($m[2], '/');

        return is_file($file) ? $file : null;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->directories;
    }

    /**
     * Every module the registry would register, in its order: local modules,
     * packages/, vendor/.
     *
     * @return list<array{string, string, bool}> [path, name, is local]
     */
    private static function registered(string $root): array
    {
        $found = [];
        foreach (self::directories($root . '/src/modules') as $dir) {
            $found[] = [$dir, basename($dir), true];
        }
        foreach ([$root . '/packages', $root . '/vendor'] as $base) {
            foreach (self::directories($base) as $dir) {
                if (is_file($dir . '/composer.json')) {
                    $found[] = [$dir, basename($dir), false];
                    continue;
                }
                foreach (self::directories($dir) as $package) {
                    $found[] = [$package, basename($dir) . '-' . basename($package), false];
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private static function directories(string $in): array
    {
        return is_dir($in) ? (glob($in . '/*', GLOB_ONLYDIR) ?: []) : [];
    }

    /** @return array<array-key, mixed> */
    private static function composer(string $path): array
    {
        $file = $path . '/composer.json';
        if (!is_file($file)) {
            return [];
        }
        $json = json_decode((string) @file_get_contents($file), true);

        return is_array($json) ? $json : [];
    }
}

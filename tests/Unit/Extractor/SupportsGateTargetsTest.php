<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Unit\Extractor;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An extractor's supports() gate must ask about attributes where they live.
 *
 * ParsedFile::hasAttribute() sees CLASS attributes only. Gating on it with an
 * attribute that targets properties or methods means the extractor never
 * runs, and nothing says so: the graph simply lacks those edges. That is how
 * every injects_* edge went missing from the workspace graph.
 */
final class SupportsGateTargetsTest extends TestCase
{
    #[Test]
    public function every_class_level_gate_names_an_attribute_that_can_sit_on_a_class(): void
    {
        $offenders = [];
        $checked = 0;
        $files = glob(__DIR__ . '/../../../src/Application/Service/Extractor/Attribute/*.php') ?: [];
        self::assertNotSame([], $files, 'precondition: the attribute extractors are found');

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (!preg_match('/function supports\(.*?\n    }\n/s', $source, $gate)) {
                continue;
            }
            preg_match_all('/->hasAttribute\(\s*([A-Za-z_\\\\]+)::class/', $gate[0], $names);

            foreach ($names[1] as $short) {
                $fqcn = self::resolve($short, $source);
                if (!class_exists($fqcn)) {
                    continue; // an optional package's attribute; nothing to check here
                }
                $attribute = (new \ReflectionClass($fqcn))->getAttributes(\Attribute::class)[0] ?? null;
                $flags = $attribute?->newInstance()->flags ?? \Attribute::TARGET_ALL;
                $checked++;
                if (($flags & \Attribute::TARGET_CLASS) === 0) {
                    $offenders[] = basename($file) . ' gates on ' . $short . ', which cannot target a class';
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'precondition: at least one gate was actually checked');
        self::assertSame([], $offenders);
    }

    private static function resolve(string $short, string $source): string
    {
        if (str_contains($short, '\\')) {
            return ltrim($short, '\\');
        }
        if (preg_match('/^use ([A-Za-z_\\\\]+\\\\' . preg_quote($short, '/') . ');$/m', $source, $m)) {
            return $m[1];
        }

        return $short;
    }
}

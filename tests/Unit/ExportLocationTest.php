<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Query\ExportLocation;

/**
 * The graph export maps the whole codebase; `--output` took any path, so
 * `public/graph.html` published it on the site and a container `/tmp` path
 * wrote a file nobody on the host could open (ep-agent-private-review-evidence).
 */
final class ExportLocationTest extends TestCase
{
    private const ROOT = '/var/www/html';

    #[Test]
    public function without_output_the_file_goes_under_var_named_after_its_slice(): void
    {
        self::assertSame('/var/www/html/var/evidence/inbox/graph-ProjectGraph.html', ExportLocation::resolve(self::ROOT, null, 'ProjectGraph', false));
        self::assertSame('/var/www/html/var/evidence/inbox/graph-whole.html', ExportLocation::resolve(self::ROOT . '/', '', 'whole', false));
        // A focus is an FQCN or a path: it names the file, it does not pick the directory.
        self::assertSame('/var/www/html/var/evidence/inbox/graph-App-Orders-PlaceOrder.html', ExportLocation::resolve(self::ROOT, null, 'App\\Orders\\PlaceOrder', false));
        self::assertSame('/var/www/html/var/evidence/inbox/graph-whole.html', ExportLocation::resolve(self::ROOT, null, '../..', false));
    }

    #[Test]
    public function a_file_in_the_inbox_gets_the_hint_ai_evidence_adopts_it_by(): void
    {
        $root = sys_get_temp_dir() . '/semitexa-export-hint-' . bin2hex(random_bytes(4));
        mkdir($root . '/var/evidence/inbox', 0777, true);
        mkdir($root . '/var/tmp', 0777, true);
        try {
            $hint = ExportLocation::hintFor($root, $root . '/var/evidence/inbox/graph-Orders.html', 'Orders');
            self::assertSame($root . '/var/evidence/inbox/graph-Orders.html.evidence.json', $hint);
            self::assertSame(
                ['schema' => 'semitexa.evidence-inbox/v1', 'kind' => 'graph-export', 'data' => 'real', 'note' => 'Project graph export: Orders', 'ttl_days' => 14, 'created_by' => 'ai:review-graph:show'],
                json_decode((string) file_get_contents((string) $hint), true),
            );
            self::assertNull(ExportLocation::hintFor($root, $root . '/var/tmp/g.html', 'Orders'), 'a place the operator chose is theirs');
        } finally {
            self::remove($root);
        }
    }

    #[Test]
    public function a_symlink_inside_var_does_not_lead_out_of_it(): void
    {
        $root = sys_get_temp_dir() . '/semitexa-export-link-' . bin2hex(random_bytes(4));
        mkdir($root . '/var/tmp', 0777, true);
        mkdir($root . '/public', 0777, true);
        mkdir($root . '/elsewhere', 0777, true);
        symlink('../public', $root . '/var/out');
        touch($root . '/public/graph.html');
        symlink('../public/graph.html', $root . '/var/linked.html');
        symlink('../public/missing.html', $root . '/var/dangling.html');
        try {
            foreach (['var/out/graph.html', 'var/out/new/graph.html', 'var/linked.html', 'var/dangling.html'] as $requested) {
                try {
                    ExportLocation::resolve($root, $requested, 'whole', false);
                    self::fail($requested . ' was accepted: it is written outside var/');
                } catch (\InvalidArgumentException $e) {
                    self::assertStringContainsString('--allow-anywhere', $e->getMessage());
                }
            }
            self::assertSame($root . '/var/out/graph.html', ExportLocation::resolve($root, 'var/out/graph.html', 'whole', true));
            // A real directory under var/, existing or not yet, is still fine.
            self::assertSame($root . '/var/tmp/g.html', ExportLocation::resolve($root, 'var/tmp/g.html', 'whole', false));
            self::assertSame($root . '/var/new/dir/g.html', ExportLocation::resolve($root, 'var/new/dir/g.html', 'whole', false));
        } finally {
            self::remove($root);
        }
    }

    #[Test]
    public function a_symlinked_var_is_still_var(): void
    {
        $root = sys_get_temp_dir() . '/semitexa-export-var-' . bin2hex(random_bytes(4));
        mkdir($root . '/storage/var/tmp', 0777, true);
        symlink('storage/var', $root . '/var');
        try {
            self::assertSame($root . '/var/tmp/g.html', ExportLocation::resolve($root, 'var/tmp/g.html', 'whole', false));
        } finally {
            self::remove($root);
        }
    }

    /** Without a shell: `rm -rf` failed silently where there is no rm. */
    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    /** @return iterable<string, array{string, string}> */
    public static function allowed(): iterable
    {
        yield 'relative under var' => ['var/tmp/g.html', '/var/www/html/var/tmp/g.html'];
        yield 'absolute under var' => ['/var/www/html/var/g.html', '/var/www/html/var/g.html'];
        yield 'dot segments that stay in var' => ['./var/a/../g.html', '/var/www/html/var/g.html'];
    }

    #[Test]
    #[DataProvider('allowed')]
    public function a_path_under_var_is_written(string $requested, string $expected): void
    {
        self::assertSame($expected, ExportLocation::resolve(self::ROOT, $requested, 'whole', false));
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'the web root' => ['public/graph.html'];
        yield 'a tracked directory' => ['docs/graph.html'];
        yield 'the project root' => ['graph.html'];
        yield 'container tmp, invisible on the host' => ['/tmp/graph.html'];
        yield 'climbing out of var' => ['var/../public/graph.html'];
        yield 'a sibling that only starts like var' => ['variant/graph.html'];
        yield 'var itself as the file' => ['var'];
    }

    #[Test]
    #[DataProvider('refused')]
    public function anywhere_else_is_refused_unless_allowed(string $requested): void
    {
        try {
            ExportLocation::resolve(self::ROOT, $requested, 'whole', false);
            self::fail($requested . ' was accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('--allow-anywhere', $e->getMessage());
        }

        self::assertNotSame('', ExportLocation::resolve(self::ROOT, $requested, 'whole', true));
    }
}

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
            exec('rm -rf ' . escapeshellarg($root));
        }
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

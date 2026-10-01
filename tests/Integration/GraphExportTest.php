<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Query\GraphExport;
use Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService;
use Semitexa\ProjectGraph\Application\Service\Query\ReviewGraphRenderer;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * `ai:review-graph:show --format=html`: the graph view as one file.
 *
 * The rules these pin down: the file carries the viewer and the data inline
 * (nothing to fetch, so it opens from file://), a focus keeps it to that
 * node's walk, nothing in the data can close the script block, and an
 * unknown format is an error rather than a silent summary.
 */
final class GraphExportTest extends TestCase
{
    #[Test]
    public function the_file_embeds_the_viewer_and_the_data(): void
    {
        $fixture = GraphFixture::built();
        $export = new GraphExport($fixture->storage, $fixture->root);
        $path = tempnam(sys_get_temp_dir(), 'graph-export-');

        try {
            $bytes = $export->write($path, $export->data(), 'Fixture </script><b>');
            $html = (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }

        self::assertSame(strlen($html), $bytes);
        self::assertStringContainsString('window.SemitexaGraphView', $html);
        self::assertStringContainsString('.gv{', $html);
        self::assertStringNotContainsString('src="http', $html, 'nothing is fetched');
        self::assertMatchesRegularExpression('#<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'nonce-([^\']+)\'#', $html);
        preg_match("#'nonce-([^']+)'#", $html, $nonce);
        self::assertSame(2, substr_count($html, '<script nonce="' . $nonce[1] . '">'), 'both executable scripts carry the policy nonce');
        self::assertStringContainsString('<title>Fixture &lt;/script&gt;&lt;b&gt;</title>', $html);

        preg_match('#<script type="application/json" id="graph-data">(.*?)</script>#s', $html, $m);
        $data = json_decode($m[1] ?? '', true);
        self::assertIsArray($data);
        self::assertContains(GraphFixture::classId('Orders\\PlaceOrderHandler'), array_column($data['nodes'], 'id'));
        self::assertNotSame([], $data['edges']);
    }

    #[Test]
    public function a_focus_keeps_only_its_walk(): void
    {
        $fixture = GraphFixture::built();
        $data = (new GraphExport($fixture->storage, $fixture->root))
            ->data(GraphFixture::NS . 'Orders\\PlaceOrderHandler', 1);

        $ids = array_column($data['nodes'], 'id');
        self::assertContains(GraphFixture::classId('Orders\\OrderRepository'), $ids);
        self::assertNotContains(GraphFixture::classId('Cycle\\Ping'), $ids);
        foreach ($data['edges'] as $edge) {
            self::assertContains($edge['s'], $ids);
            self::assertContains($edge['t'], $ids);
        }
    }

    #[Test]
    public function an_unknown_focus_is_refused(): void
    {
        $fixture = GraphFixture::built();

        $this->expectException(\InvalidArgumentException::class);
        (new GraphExport($fixture->storage, $fixture->root))->data('No\\Such\\Klass');
    }

    #[Test]
    public function an_unknown_text_format_is_an_error_not_a_summary(): void
    {
        $view = (new GraphQueryService(GraphFixture::built()->storage))->buildView();

        $this->expectException(\InvalidArgumentException::class);
        (new ReviewGraphRenderer())->render($view, 'html');
    }
}

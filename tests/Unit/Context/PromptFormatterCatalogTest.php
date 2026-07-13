<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Context\ContextNode;
use Semitexa\ProjectGraph\Application\Service\Context\ContextPackage;
use Semitexa\ProjectGraph\Application\Service\Context\PromptFormatter;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Domain\Model\Node;

/**
 * Byte-identity guard for the PromptFormatter -> prompt-catalog migration. The
 * golden fixtures were captured from the pre-migration formatter; the catalog
 * templates (with the assembled {{ body }} / {{ goal }}) must reproduce them
 * exactly.
 */
final class PromptFormatterCatalogTest extends TestCase
{
    private function package(): ContextPackage
    {
        $node = new Node('id1', NodeType::File, 'App\\Foo\\Bar', 'src/Foo/Bar.php', 10, 20, 'Foo', [], false);
        $ctxNode = new ContextNode($node, 0.876, "class Bar {\n    public int \$x = 1;\n}");

        return new ContextPackage([$ctxNode], [], 100, ['src/Foo/Bar.php', 'src/Foo/Baz.php']);
    }

    private function golden(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name . '.golden.txt');
    }

    public function testReviewIsByteIdenticalToLegacy(): void
    {
        self::assertSame($this->golden('review'), (new PromptFormatter())->formatForReview($this->package()));
    }

    public function testRefactorIsByteIdenticalToLegacy(): void
    {
        self::assertSame(
            $this->golden('refactor'),
            (new PromptFormatter())->formatForRefactor($this->package(), 'extract a service'),
        );
    }

    public function testTestsIsByteIdenticalToLegacy(): void
    {
        self::assertSame($this->golden('tests'), (new PromptFormatter())->formatForTests($this->package()));
    }

    public function testEmptyPackageRendersCleanlyWithNoUnboundTokens(): void
    {
        $empty = new ContextPackage([], [], 0, []);
        $review = (new PromptFormatter())->formatForReview($empty);

        self::assertStringNotContainsString('{{', $review);
        self::assertStringContainsString('You are a senior PHP code reviewer.', $review);
        self::assertStringContainsString("## Affected Code (by relevance)\n\n## Instructions", $review);
    }
}

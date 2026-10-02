<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Unit\Extractor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Extractor\Attribute\DomainContextExtractor;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Parser\PhpParserAdapter;

/**
 * DomainContextExtractor matched module names against keywords by SUBSTRING
 * (measured 2026-10-02 on the workspace): local module EventsDemo became
 * domain:Ledger ("event"), AuthDemo merged into the real domain:Auth, CmsDemo
 * into Content — a demo's classes counted as the product domain's, with its
 * criticality. A local module (src/modules/<Name>) is its own module, so it
 * is its own domain; so is anything named *Demo, *Harness, *Playground,
 * *Probe. Keywords match whole words of the module name only.
 */
final class Round2DomainContextTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string, 2: string}> relative path, module, expected domain */
    public static function modules(): iterable
    {
        yield 'local EventsDemo is not Ledger' => ['src/modules/EventsDemo/src/Thing.php', 'EventsDemo', 'domain:EventsDemo'];
        yield 'local AuthDemo is not Auth' => ['src/modules/AuthDemo/src/Thing.php', 'AuthDemo', 'domain:AuthDemo'];
        yield 'local CmsDemo is not Content' => ['src/modules/CmsDemo/src/Thing.php', 'CmsDemo', 'domain:CmsDemo'];
        yield 'local module is its own domain even with a keyword name' => ['src/modules/Billing/src/Thing.php', 'Billing', 'domain:Billing'];
        yield 'a package *Harness is its own' => ['packages/semitexa-order-harness/src/Thing.php', 'OrderHarness', 'domain:OrderHarness'];
        yield 'exact package name' => ['packages/semitexa-auth/src/Thing.php', 'Auth', 'domain:Auth'];
        yield 'keyword as a whole word' => ['packages/semitexa-rbac/src/Thing.php', 'Rbac', 'domain:Auth'];
        yield 'keyword word in a studly name' => ['packages/semitexa-platform-user/src/Thing.php', 'PlatformUser', 'domain:User'];
        yield 'plural keyword' => ['packages/semitexa-tasks/src/Thing.php', 'Tasks', 'domain:Scheduler'];
        yield 'substring is not a word' => ['packages/semitexa-webhooks/src/Thing.php', 'Webhooks', 'domain:Webhooks'];
        yield 'prefix of a word is not the word' => ['packages/semitexa-authorization/src/Thing.php', 'Authorization', 'domain:Authorization'];
    }

    #[Test]
    #[DataProvider('modules')]
    public function a_module_gets_the_domain_its_name_says(string $relative, string $module, string $domain): void
    {
        $root = sys_get_temp_dir() . '/pg-r2-domain-' . bin2hex(random_bytes(4));
        $path = $root . '/' . $relative;
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, "<?php\nnamespace R2Domain;\nfinal class Thing {}\n");
        try {
            $result = (new DomainContextExtractor())->extract((new PhpParserAdapter())->parse($path, $module));
        } finally {
            unlink($path);
        }

        $targets = [];
        foreach ($result->edges as $edge) {
            if ($edge->getType() === EdgeType::BelongsToDomain) {
                $targets[] = $edge->getTargetId();
            }
        }
        self::assertSame([$domain], $targets);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Findings\UnusedClassFinder;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

final class RegistryDiscoveryFindingTest extends TestCase
{
    #[Test]
    public function a_generated_contract_resolver_is_kept_alive_by_the_container(): void
    {
        // registry:sync:contracts writes these; the container loads them by name.
        $fixture = GraphFixture::create();
        $fixture->write('src/registry/Contracts/MailerResolver.php', "<?php\nnamespace App\\Registry\\Contracts;\nfinal class MailerResolver {}\n");
        $fixture->build();

        $finding = array_values(array_filter(
            (new UnusedClassFinder())->find($fixture->storage),
            static fn (array $f): bool => $f['fqcn'] === 'App\\Registry\\Contracts\\MailerResolver',
        ))[0] ?? null;

        self::assertNotNull($finding);
        self::assertNotSame('high', $finding['confidence'], $finding['evidence']);
        self::assertStringContainsString('loaded by the container by name', $finding['evidence']);
    }
}

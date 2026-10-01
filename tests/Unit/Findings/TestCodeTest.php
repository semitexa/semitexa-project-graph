<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Unit\Findings;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Findings\TestCode;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * "Is this test code" is a question about the path INSIDE the project: a
 * project that itself lives under a `tests` directory must not have every
 * class counted as a test (and every finding silently dropped).
 */
final class TestCodeTest extends TestCase
{
    #[Test]
    public function a_project_under_a_tests_directory_still_has_production_code(): void
    {
        $tests = TestCode::forRoot('/srv/tests/shop');

        self::assertFalse($tests->contains('/srv/tests/shop/src/Orders/PlaceOrderHandler.php'));
        self::assertTrue($tests->contains('/srv/tests/shop/tests/Orders/PlaceOrderTest.php'));
        self::assertTrue($tests->contains('/srv/tests/shop/packages/orders/tests/Unit/FooTest.php'));
    }

    #[Test]
    public function without_a_recorded_root_the_absolute_path_decides(): void
    {
        self::assertTrue(TestCode::forRoot('')->contains('/app/tests/FooTest.php'));
        self::assertFalse(TestCode::forRoot('')->contains('/app/src/Foo.php'));
    }

    #[Test]
    public function a_build_records_the_project_root_it_scanned(): void
    {
        $fixture = GraphFixture::built();

        self::assertSame(rtrim($fixture->root, '/'), $fixture->storage->getMeta('project_root'));
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Unit\Parser;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Parser\ConstructedArgument;
use Semitexa\ProjectGraph\Application\Service\Parser\PhpParserAdapter;

/** A class whose constructor must never run during a scan. */
final class ScanGadget
{
    public static int $constructed = 0;

    public function __construct(public string $path = '')
    {
        self::$constructed++;
    }
}

/**
 * Reading a file's attributes must not execute code from it.
 *
 * The rules these pin down: `new X(...)` in an attribute argument is
 * recorded (class + arguments), never constructed — a scan reads every file,
 * so construction would run any loadable class's constructor named in any
 * file; and whatever a lookup throws costs that one attribute, not the file.
 */
final class AttributeArgumentsAreNotExecutedTest extends TestCase
{
    #[Test]
    public function new_in_an_attribute_argument_is_recorded_not_constructed(): void
    {
        ScanGadget::$constructed = 0;
        $classes = $this->parse(<<<'PHP'
            <?php
            namespace Fixture\Gadget;

            #[\Fixture\Gadget\Marker(new \Semitexa\ProjectGraph\Tests\Unit\Parser\ScanGadget('/tmp/x'), label: 'kept')]
            final class Target {}
            PHP);

        $args = $classes[0]->getAttribute('Fixture\\Gadget\\Marker')?->getArguments();

        self::assertSame(0, ScanGadget::$constructed, 'the constructor ran during the scan');
        self::assertIsArray($args);
        self::assertInstanceOf(ConstructedArgument::class, $args[0]);
        self::assertSame(ScanGadget::class, $args[0]->class);
        self::assertSame(['/tmp/x'], $args[0]->arguments);
        self::assertSame('kept', $args['label']);
    }

    #[Test]
    public function a_throwing_autoloader_costs_one_attribute_not_the_file(): void
    {
        $loader = static function (string $class): void {
            if (str_starts_with($class, 'Boom\\')) {
                throw new \RuntimeException('autoloader exploded on ' . $class);
            }
        };
        spl_autoload_register($loader);
        try {
            $classes = $this->parse(<<<'PHP'
                <?php
                namespace Fixture\Boom;

                #[\Fixture\Boom\First(\Boom\Thing::VALUE)]
                #[\Fixture\Boom\Second('fine')]
                final class Target {}
                PHP);
        } finally {
            spl_autoload_unregister($loader);
        }

        self::assertCount(1, $classes);
        self::assertSame(['fine'], $classes[0]->getAttribute('Fixture\\Boom\\Second')?->getArguments());
        self::assertSame('autoloader exploded on Boom\\Thing', $classes[0]->getAttribute('Fixture\\Boom\\First')?->unreadableReason());
    }

    /** @return list<\Semitexa\ProjectGraph\Application\Service\Parser\ClassInfo> */
    private function parse(string $php): array
    {
        $file = tempnam(sys_get_temp_dir(), 'pg') . '.php';
        file_put_contents($file, $php);
        try {
            return (new PhpParserAdapter())->parse($file)->getClasses();
        } finally {
            unlink($file);
        }
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;

/**
 * A command nobody can run is worse than one that does not exist — it gets documented.
 *
 * Six of this package's thirteen commands were unreachable for exactly that reason, and the
 * only visible symptom was docs:lint reporting 33 claims with nothing behind them. Two causes
 * were stacked, and each hides the other.
 *
 * First, discovery matches Semitexa's own #[AsCommand] by exact class (Console/Application.php),
 * so a command carrying Symfony's identically-named attribute is never even looked at. Fix that
 * alone and the second cause appears: those commands asked the container for a GraphQueryService,
 * which needs a GraphStorage bound to the project-graph connection — something only
 * ConnectionRegistry can open. The container threw, Application swallowed it as a boot
 * diagnostic, and the commands silently stayed missing from `bin/semitexa list`.
 *
 * Both failure modes are invisible at runtime by design: nothing is supposed to crash when one
 * command cannot be built. So they are pinned here instead.
 */
final class CommandsAreReachableTest extends TestCase
{
    private const COMMAND_DIR = __DIR__ . '/../../../src/Application/Console/Command';

    /** Namespaces the DI container has no way to construct — they need an opened graph. */
    private const UNBUILDABLE_NAMESPACES = [
        'Semitexa\\ProjectGraph\\Application\\Service\\Query\\',
        'Semitexa\\ProjectGraph\\Application\\Service\\Graph\\',
        'Semitexa\\ProjectGraph\\Application\\Service\\Intelligence\\',
    ];

    /** @return array<string, array{0: string}> */
    public static function commandClasses(): array
    {
        $files = glob(self::COMMAND_DIR . '/*Command.php');
        self::assertIsArray($files);
        self::assertNotEmpty($files, 'no command sources found — this guard would pass vacuously');

        $cases = [];
        foreach ($files as $file) {
            $class = 'Semitexa\\ProjectGraph\\Application\\Console\\Command\\' . basename($file, '.php');
            if (class_exists($class)) {
                $cases[basename($file, '.php')] = [$class];
            }
        }

        self::assertNotEmpty($cases);

        return $cases;
    }

    #[Test]
    #[DataProvider('commandClasses')]
    public function every_command_is_declared_with_the_attribute_discovery_looks_for(string $class): void
    {
        $reflection = new ReflectionClass($class);

        self::assertNotEmpty(
            $reflection->getAttributes(AsCommand::class),
            $reflection->getShortName() . ' does not carry Semitexa\\Core\\Attribute\\AsCommand. '
                . 'Symfony ships an attribute of the same name and discovery matches by exact '
                . 'class, so the command would never be registered and nothing would say so.',
        );
    }

    #[Test]
    #[DataProvider('commandClasses')]
    public function no_command_asks_the_container_for_an_opened_graph(string $class): void
    {
        // Both DI channels, because the failure is identical either way and only the channel
        // changed when these commands were repaired: a constructor parameter throws at resolve
        // time, an injected property throws at injectInto() time, and Application swallows both.
        $reflection = new ReflectionClass($class);
        $types = [];

        $constructor = self::ownConstructor($reflection);
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                $types['constructor parameter $' . $parameter->getName()] = $parameter->getType();
            }
        }

        foreach ($reflection->getProperties() as $property) {
            if ($property->getAttributes(InjectAsReadonly::class) === []) {
                continue;
            }
            $types['injected property $' . $property->getName()] = $property->getType();
        }

        foreach ($types as $where => $type) {
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            foreach (self::UNBUILDABLE_NAMESPACES as $namespace) {
                self::assertStringStartsNotWith(
                    $namespace,
                    $type->getName(),
                    $reflection->getShortName() . " asks the container for {$type->getName()} via "
                        . "its {$where}, and that needs a graph the container cannot open. Take "
                        . 'ConnectionRegistry and build it inside the command instead — otherwise '
                        . 'the container throws and the command vanishes quietly.',
                );
            }
        }
    }

    #[Test]
    #[DataProvider('commandClasses')]
    public function no_command_uses_constructor_injection(string $class): void
    {
        // semitexa.injectionViaConstructor: #[AsCommand] is container-managed, so properties are
        // the DI channel. Worth pinning next to the reachability checks, because the obvious
        // repair for an unregistered command — move the dependency into the constructor — trades
        // one silent failure for a PHPStan one.
        $constructor = self::ownConstructor(new ReflectionClass($class));
        if ($constructor === null) {
            self::assertTrue(true, 'no constructor of its own — nothing to inject through');

            return;
        }

        self::assertSame(
            0,
            $constructor->getNumberOfParameters(),
            (new ReflectionClass($class))->getShortName() . ' injects through its constructor',
        );
    }

    #[Test]
    #[DataProvider('commandClasses')]
    public function every_command_can_reach_the_project_root_helpers(string $class): void
    {
        // UsesProjectGraphConnection calls $this->getProjectRoot(), which lives on BaseCommand.
        // Extending Symfony's Command directly compiles and then fails at first use.
        self::assertTrue(
            is_subclass_of($class, BaseCommand::class),
            (new ReflectionClass($class))->getShortName() . ' must extend Semitexa BaseCommand',
        );
    }

    /**
     * The class's OWN constructor, ignoring the one it inherits from Symfony's Command —
     * which takes a name argument and would otherwise read as constructor injection.
     *
     * @param ReflectionClass<object> $reflection
     */
    private static function ownConstructor(ReflectionClass $reflection): ?ReflectionMethod
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getDeclaringClass()->getName() !== $reflection->getName()) {
            return null;
        }

        return $constructor;
    }
}

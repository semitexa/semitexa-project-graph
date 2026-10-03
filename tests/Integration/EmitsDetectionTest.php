<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;

/**
 * "Who emits event X" was answered only for $this->prop->dispatch(new X):
 * the workspace graph carried 6 emits edges (measured 2026-10-02). A
 * nullsafe receiver, a local or injected variable, a static dispatcher, or
 * the event built one line earlier were all invisible — while
 * $factory->create(new Options) counted as emitting Options.
 */
final class EmitsDetectionTest extends TestCase
{
    use ExtractorCampaign;

    private const EMITTER = <<<'PHP'
        <?php
        namespace Campaign\Emit;

        use Campaign\Ev\AliasedEvent;

        final class Emitter
        {
            private $events;

            public function nullsafe(): void { $this->events?->dispatch(new \Campaign\Ev\NullsafeEvent()); }
            public function nullsafeProperty(): void { $this?->events->dispatch(new \Campaign\Ev\NullsafePropertyEvent()); }
            public function plain(): void { $this->events->dispatch(new \Campaign\Ev\PlainEvent()); }
            public function local(\Campaign\Lib\Bus $bus): void { $bus->dispatch(new AliasedEvent()); }
            public function assigned(\Campaign\Lib\Bus $bus): void
            {
                $event = new \Campaign\Ev\AssignedEvent(1);
                $bus->emit($event);
            }
            public function statically(): void { \Campaign\Lib\Bus::dispatch(new \Campaign\Ev\StaticEvent()); }
            public function published(\Campaign\Lib\Publisher $p): void { $p->publish(new \Campaign\Ev\PublishedMessage()); }
            public function factory(\Campaign\Lib\Factory $f): void { $f->create(new \Campaign\Lib\Options()); }
            public function builds(): void { $scoped = new \Campaign\Ev\ScopedEvent(); }
            public function otherScope(\Campaign\Lib\Bus $bus): void { $bus->dispatch($scoped); }
            public function reassigned(\Campaign\Lib\Bus $bus): void
            {
                $e = new \Campaign\Ev\Overwritten();
                $e = $this->other();
                $bus->dispatch($e);
            }
        }
        PHP;

    #[Test]
    public function every_receiver_shape_emits(): void
    {
        $fixture = self::graphOf(['Emit/Emitter.php' => self::EMITTER]);

        self::assertSame([
            'class:Campaign\\Ev\\AliasedEvent',
            'class:Campaign\\Ev\\AssignedEvent',
            'class:Campaign\\Ev\\NullsafeEvent',
            'class:Campaign\\Ev\\NullsafePropertyEvent',
            'class:Campaign\\Ev\\PlainEvent',
            'class:Campaign\\Ev\\PublishedMessage',
            'class:Campaign\\Ev\\StaticEvent',
        ], self::targetsFrom($fixture, 'class:Campaign\\Emit\\Emitter', EdgeType::Emits));
    }

    #[Test]
    public function an_event_emitted_from_top_level_code_is_the_file_s(): void
    {
        $fixture = self::graphOf(['bootstrap/events.php' => "<?php\n\$bus->dispatch(new \\Campaign\\Ev\\BootEvent());\n"]);

        self::assertSame(
            ['class:Campaign\\Ev\\BootEvent'],
            self::targetsFrom($fixture, 'file:' . $fixture->path('bootstrap/events.php'), EdgeType::Emits),
        );
    }
}

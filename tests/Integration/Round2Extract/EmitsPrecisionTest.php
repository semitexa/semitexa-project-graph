<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration\Round2Extract;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;

/**
 * "Who emits X", round 2 (measured 2026-10-02 on the workspace, 27 emits):
 *  - 9 were false, all in tests: a test's own `private function
 *    dispatch(\Throwable $e)` called as $this->dispatch(new
 *    NotFoundException(..)) "emitted" six exceptions, two tests "emitted"
 *    Semitexa\Core\Request through their own dispatch(Request), and
 *    `$adapter->dispatch($envelope, $claims)` — a UI request router —
 *    "emitted" UiEventEnvelope;
 *  - real emissions were missed: WorkflowEngine's 7 events go through
 *    `$this->dispatchEvent(X::class, [...])`, a helper doing
 *    `$event = $this->eventDispatcher->create($eventClass, $data);
 *    $this->eventDispatcher->dispatch($event)`; ExecutionArenaLaunchHandler
 *    picks its event in a `match`; ConflictAnnouncer dispatches
 *    `ReplicationConflictDetected::of(...)`; `#[AsComponent(event: X::class)]`
 *    names the event the component raises.
 */
final class EmitsPrecisionTest extends TestCase
{
    use ExtractorCampaign;

    private const SOURCES = <<<'PHP'
        <?php
        namespace Campaign\Emit2;

        final class OwnDispatchTest
        {
            public function a(): void { $this->dispatch(new \Campaign\Ev2\NotFoundException()); }
            public function b(): void { $this->dispatch(new \Campaign\Ev2\Request()); }
            public function c(): void { self::dispatch(new \Campaign\Ev2\AlsoNotEmitted()); }
            private function dispatch(object $e): array { return [get_class($e)]; }
        }

        final class Thrower
        {
            public function f(\Campaign\Lib2\Bus $bus): void { $bus->dispatch(new \Campaign\Ev2\BoomError()); }
        }

        final class Router
        {
            public function f(\Campaign\Lib2\Adapter $adapter, array $claims): void
            {
                $envelope = new \Campaign\Ev2\Envelope();
                $adapter->dispatch($envelope, $claims);
            }
        }

        final class Forwarder
        {
            private $bus;
            public function a(): void { $this->dispatch(new \Campaign\Ev2\Forwarded()); }
            private function dispatch(object $event): void { $this->bus->dispatch($event); }
        }

        final class Workflow
        {
            private $eventDispatcher;
            public function start(): void { $this->dispatchEvent(\Campaign\Ev2\Started::class, ['a' => 1]); }
            public function direct(): void
            {
                $e = $this->eventDispatcher->create(\Campaign\Ev2\Created::class, []);
                $this->eventDispatcher->dispatch($e);
            }
            private function dispatchEvent(string $eventClass, array $data): void
            {
                if (!isset($this->eventDispatcher)) {
                    return;
                }
                $event = $this->eventDispatcher->create($eventClass, $data);
                $this->eventDispatcher->dispatch($event);
            }
        }

        final class Arena
        {
            private $events;
            public function launch(string $mode): void
            {
                $event = match ($mode) {
                    'sync' => new \Campaign\Ev2\SyncRequested(),
                    'async' => new \Campaign\Ev2\AsyncRequested(),
                    default => null,
                };
                if ($event === null) {
                    return;
                }
                $this->events->dispatch($event);
                $other = $mode === 'x' ? new \Campaign\Ev2\TernaryA() : new \Campaign\Ev2\TernaryB();
                $this->events->dispatch($other);
            }
        }

        final class Announcer
        {
            public static function announce(?\Campaign\Lib2\Bus $events, array $c): void
            {
                $events?->dispatch(\Campaign\Ev2\ConflictDetected::of($c));
            }
        }

        #[\Semitexa\Ssr\Attribute\AsComponent(name: 'widget')]
        final class Widget
        {
            public function onClick(): \Campaign\Lib2\Result
            {
                return \Campaign\Lib2\UiInteractionResult::ack()->dispatching(new \Campaign\Ev2\Clicked(), new \Campaign\Ev2\Opened());
            }

            public function onClose(\Campaign\Lib2\UiInteractionResult $result): \Campaign\Lib2\UiInteractionResult
            {
                return $result->dispatching(new \Campaign\Ev2\Closed());
            }
        }

        final class Scheduler
        {
            private $queue;
            public function f(): void
            {
                $this->queue->dispatching(new \Campaign\Ev2\NotAUiEvent());
                \Campaign\Lib2\Jobs::make()->dispatching(new \Campaign\Ev2\NotAUiEventEither());
            }
        }

        #[\Semitexa\Ssr\Attribute\AsComponent(name: 'button')]
        final class Button {}
        PHP;

    #[Test]
    public function only_real_emissions_are_emits(): void
    {
        $fixture = self::graphOf(['Emit2/Sources.php' => self::SOURCES]);
        $from = static fn (string $class): array => self::targetsFrom($fixture, 'class:Campaign\\Emit2\\' . $class, EdgeType::Emits);

        self::assertSame([], $from('OwnDispatchTest'), 'its own dispatch() helper does not forward to anything');
        self::assertSame([], $from('Thrower'), 'an exception handed over is not an event');
        self::assertSame([], $from('Router'), 'a two-argument dispatch routes a request, it emits no event');
        self::assertSame(['class:Campaign\\Ev2\\Forwarded'], $from('Forwarder'));
        self::assertSame(['class:Campaign\\Ev2\\Created', 'class:Campaign\\Ev2\\Started'], $from('Workflow'));
        self::assertSame([
            'class:Campaign\\Ev2\\AsyncRequested',
            'class:Campaign\\Ev2\\SyncRequested',
            'class:Campaign\\Ev2\\TernaryA',
            'class:Campaign\\Ev2\\TernaryB',
        ], $from('Arena'));
        self::assertSame(['class:Campaign\\Ev2\\ConflictDetected'], $from('Announcer'));
        // verify:accept-test-change #[AsComponent(event:)] is retired (one component model); a component emits through its UI handler's dispatching() instead
        self::assertSame(['class:Campaign\\Ev2\\Clicked', 'class:Campaign\\Ev2\\Closed', 'class:Campaign\\Ev2\\Opened'], $from('Widget'), 'a UI handler emits what it dispatching()s');
        self::assertSame([], $from('Scheduler'), 'dispatching() on anything but a UiInteractionResult emits nothing');
        self::assertSame([], $from('Button'), 'a component without a handler emits nothing');
    }
}

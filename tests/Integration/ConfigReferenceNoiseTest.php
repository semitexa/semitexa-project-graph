<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;

/**
 * A class name in a config file is a reference only where the file wires
 * it. PHPStan's baseline says "Class X is never used" — that made X used
 * and hid real dead code (measured 2026-10-02: the Orm traits HasUuid,
 * Seedable, SoftDeletes, FilterableTrait). A comment in docker-compose.yml
 * made a phantom class:Server\TaskTickTimerListener.
 */
final class ConfigReferenceNoiseTest extends TestCase
{
    use ExtractorCampaign;

    private const DEAD = "<?php\nnamespace Campaign\\Rel;\nfinal class DeadCode {}\n";

    #[Test]
    public function a_phpstan_baseline_references_nothing(): void
    {
        $fixture = self::graphOf([
            'src/DeadCode.php' => self::DEAD,
            'phpstan-baseline.neon' => "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: '#^Class Campaign\\\\Rel\\\\DeadCode is never used\\.$#'\n\t\t\tpath: src/DeadCode.php\n",
        ]);

        self::assertSame([], self::edgesFrom($fixture, NodeId::forFile($fixture->path('phpstan-baseline.neon'))));
        self::assertSame('high', self::unused($fixture)['Campaign\\Rel\\DeadCode'] ?? null);
    }

    #[Test]
    public function error_messages_patterns_and_comments_in_neon_are_not_references(): void
    {
        $fixture = self::graphOf([
            'phpstan.neon' => <<<'NEON'
                # Campaign\Rel\Commented is mentioned in a comment only
                parameters:
                    ignoreErrors:
                        -
                            message: '#^Class Campaign\\Rel\\InMessage is never used#'
                        -
                            rawMessage: 'Class Campaign\Rel\InRawMessage is never used.'
                        - '#^Call to Campaign\\Rel\\InPattern::x\(\)#'
                rules:
                    - Campaign\Rel\RealRule # Campaign\Rel\TrailingComment
                NEON,
        ]);

        self::assertSame(
            ['class:Campaign\\Rel\\RealRule'],
            self::targetsFrom($fixture, NodeId::forFile($fixture->path('phpstan.neon')), EdgeType::References),
        );
    }

    #[Test]
    public function a_yaml_comment_makes_no_phantom_class(): void
    {
        $fixture = self::graphOf([
            'docker-compose.yml' => <<<'YAML'
                services:
                  app:
                    # a Swoole Timer armed at worker start (Tasks\…\Server\TaskTickTimerListener),
                    image: "php:8.4" # not Campaign\Yaml\Trailing either
                    environment:
                      HANDLER: Campaign\Yaml\Wired
                      QUOTED: "Campaign\\Yaml\\Hash#NotAComment"
                YAML,
        ]);

        self::assertNull($fixture->storage->nodes->findById('class:Server\\TaskTickTimerListener'));
        self::assertSame(
            ['class:Campaign\\Yaml\\Hash', 'class:Campaign\\Yaml\\Wired'],
            self::targetsFrom($fixture, NodeId::forFile($fixture->path('docker-compose.yml')), EdgeType::References),
        );
    }
}

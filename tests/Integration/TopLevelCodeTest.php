<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;

/**
 * Code outside any class — config/*.php returning objects, bootstrap
 * scripts, functions declared next to a class — used to produce either no
 * edges (nothing to attribute them to, so the classes it uses were graded
 * HIGH unused with absence_is_proof) or edges credited to the class declared
 * above it, which never mentions them.
 */
final class TopLevelCodeTest extends TestCase
{
    use ExtractorCampaign;

    private const FROM_CONFIG = "<?php\nnamespace Campaign\\Cfg;\nfinal class FromConfig {}\n";
    private const FROM_BOOT = "<?php\nnamespace Campaign\\Cfg;\nfinal class FromBoot { public static function register(): void {} }\n";

    /** @return array<string, string> */
    private static function project(): array
    {
        return [
            'src/Cfg/FromConfig.php' => self::FROM_CONFIG,
            'src/Cfg/FromBoot.php' => self::FROM_BOOT,
            'config/app.php' => "<?php\n\nreturn [\n    'thing' => new \\Campaign\\Cfg\\FromConfig(),\n];\n",
            'bootstrap/boot.php' => "<?php\n\n\\Campaign\\Cfg\\FromBoot::register();\n",
        ];
    }

    #[Test]
    public function a_config_file_that_builds_a_class_uses_it(): void
    {
        $fixture = self::graphOf(self::project());
        $config = NodeId::forFile($fixture->path('config/app.php'));

        self::assertContains('class:Campaign\\Cfg\\FromConfig', self::targetsFrom($fixture, $config, EdgeType::Instantiates));
        $node = $fixture->storage->nodes->findById($config);
        self::assertNotNull($node, 'the file is a node, so its edges have an owner');
        self::assertSame(NodeType::File, $node->getType());
        self::assertSame($fixture->path('config/app.php'), $node->getFile());
        self::assertFalse($node->getIsPlaceholder());
    }

    #[Test]
    public function classes_used_only_from_top_level_code_are_not_unused(): void
    {
        $fixture = self::graphOf(self::project());
        $unused = self::unused($fixture);

        self::assertArrayHasKey('Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orders\\SqlOrderRepository', $unused, 'precondition: the finder still reports unused classes');
        self::assertArrayNotHasKey('Campaign\\Cfg\\FromConfig', $unused);
        self::assertArrayNotHasKey('Campaign\\Cfg\\FromBoot', $unused);
    }

    #[Test]
    public function a_function_after_a_class_is_not_that_class_s_code(): void
    {
        $fixture = self::graphOf(['After/First.php' => <<<'PHP'
            <?php
            namespace Campaign\After;

            class First {}

            function helper(\Campaign\Lib\Hinted $h): \Campaign\Lib\HintedRet
            {
                $x = new \Campaign\Lib\OnlyHelperUses();
                \Campaign\Lib\Statics::call();
                return $x;
            }
            PHP]);
        $first = 'class:Campaign\\After\\First';
        $file = NodeId::forFile($fixture->path('After/First.php'));

        self::assertSame([], self::edgesFrom($fixture, $first, EdgeType::Instantiates));
        self::assertSame([], self::edgesFrom($fixture, $first, EdgeType::References));
        self::assertSame([], self::edgesFrom($fixture, $first, EdgeType::Accepts));
        self::assertContains('class:Campaign\\Lib\\OnlyHelperUses', self::targetsFrom($fixture, $file, EdgeType::Instantiates));
        self::assertContains('class:Campaign\\Lib\\Statics', self::targetsFrom($fixture, $file, EdgeType::References));
        self::assertContains('class:Campaign\\Lib\\Hinted', self::targetsFrom($fixture, $file, EdgeType::Accepts));
        self::assertContains('class:Campaign\\Lib\\HintedRet', self::targetsFrom($fixture, $file, EdgeType::Returns));
        self::assertSame([], self::danglingSources($fixture));
    }

    #[Test]
    public function deleting_the_script_removes_its_node_and_edges(): void
    {
        $fixture = self::graphOf(self::project());
        $config = NodeId::forFile($fixture->path('config/app.php'));
        self::assertNotNull($fixture->storage->nodes->findById($config), 'precondition: the script has its node');
        self::assertNotSame([], self::edgesFrom($fixture, $config), 'precondition: and its edges');
        self::assertArrayNotHasKey('Campaign\\Cfg\\FromConfig', self::unused($fixture), 'precondition: the script keeps it used');

        $fixture->delete('config/app.php');
        $fixture->refresh();

        self::assertNull($fixture->storage->nodes->findById($config));
        self::assertSame([], self::edgesFrom($fixture, $config));
        self::assertArrayHasKey('Campaign\\Cfg\\FromConfig', self::unused($fixture), 'with its only user gone, it is unused again');
    }

    #[Test]
    public function a_class_file_without_top_level_code_gets_no_file_node(): void
    {
        $fixture = self::graphOf(self::project());

        self::assertNull($fixture->storage->nodes->findById(NodeId::forFile($fixture->path('src/Cfg/FromConfig.php'))));
    }
}

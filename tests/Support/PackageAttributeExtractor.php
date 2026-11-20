<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Support;

use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;
use Semitexa\ProjectGraph\Domain\Model\Edge;

/**
 * Reads {@see PackageAttribute} the way UiNavigationExtractor reads AsCrud:
 * it asks the attribute instance, so the edge depends on the installed class
 * and not only on the project file.
 */
final class PackageAttributeExtractor implements ExtractorInterface
{
    /** Run while a file is read: a test changes the installed packages in the middle of a refresh with it. */
    public static ?\Closure $whileExtracting = null;

    public function supports(ParsedFile $file): bool
    {
        return $file->hasAttribute(PackageAttribute::class);
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        if (self::$whileExtracting !== null) {
            (self::$whileExtracting)();
        }
        $result = new ExtractionResult();
        foreach ($file->getClassesWithAttribute(PackageAttribute::class) as $class) {
            $instance = $class->getAttribute(PackageAttribute::class)?->newInstance();
            if ($instance instanceof PackageAttribute) {
                $result->addEdge(new Edge(NodeId::forClass($class->fqcn), 'permission:' . $instance->permissionFor('read'), EdgeType::RequiresPermission, []));
            }
        }

        return $result;
    }
}

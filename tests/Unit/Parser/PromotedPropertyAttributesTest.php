<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Unit\Parser;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Parser\PhpParserAdapter;

final class PromotedPropertyAttributesTest extends TestCase
{
    #[Test]
    public function a_promoted_constructor_parameter_is_a_property_with_its_attributes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pg') . '.php';
        file_put_contents($file, <<<'PHP'
            <?php
            namespace Fixture\Promoted;

            use Semitexa\Orm\Attribute\BelongsTo;

            final class ArticleResource
            {
                public function __construct(
                    #[BelongsTo(target: CategoryResource::class, foreignKey: 'category_id')]
                    public ?CategoryResource $category = null,
                    string $notPromoted = '',
                ) {}
            }
            PHP);

        try {
            $classes = (new PhpParserAdapter())->parse($file)->getClasses();
        } finally {
            unlink($file);
        }

        $properties = $classes[0]->properties;
        self::assertCount(1, $properties);
        self::assertSame('category', $properties[0]->name);
        self::assertSame('Fixture\\Promoted\\CategoryResource', $properties[0]->typeFqcn);
        self::assertSame('Fixture\\Promoted\\CategoryResource', $properties[0]->getAttribute('Semitexa\\Orm\\Attribute\\BelongsTo')?->getArguments()['target']);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Support;

/**
 * Stands for an attribute class an installed package ships, like AsCrud: the
 * project file only writes #[PackageAttribute(...)], and what the slug is
 * comes from the class. A process cannot load two versions of one class, so
 * the package's release is a switch a test turns as `composer update` would.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PackageAttribute
{
    /** The release "installed": 1 names the read permission "<x>.read", 2 "<x>.view". */
    public static int $release = 1;

    public function __construct(public readonly string $permission)
    {
    }

    public function permissionFor(string $operation): string
    {
        return $this->permission . '.' . ($operation === 'read' && self::$release > 1 ? 'view' : $operation);
    }
}

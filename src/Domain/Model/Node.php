<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Domain\Model;

use Semitexa\ProjectGraph\Application\Graph\NodeType;

final readonly class Node
{
    public function __construct(
        public string   $id,
        public NodeType $type,
        public string   $fqcn,
        public string   $file,
        public int      $line,
        public int      $endLine,
        public string   $module,
        public array    $metadata,
        public bool     $isPlaceholder = false,
    ) {}

    public function name(): string
    {
        $pos = strrpos($this->fqcn, '\\');
        return $pos !== false ? substr($this->fqcn, $pos + 1) : $this->fqcn;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Parser;

use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class PhpParserAdapter
{
    private readonly Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function parse(string $filePath, string $module = ''): ParsedFile
    {
        $code = file_get_contents($filePath);
        $ast  = $this->parser->parse($code) ?? [];

        $nameResolver = new NameResolver();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($nameResolver);
        $traverser->traverse($ast);

        return new ParsedFile(
            path:   $filePath,
            ast:    $ast,
            code:   $code,
            module: $module,
        );
    }
}

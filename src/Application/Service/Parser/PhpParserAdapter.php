<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Parser;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
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
        // verify:accept-cross-file-read filesystem IncrementalRefreshIntegrityTest::a_refresh_after_touching_every_file_one_by_one_equals_a_full_build the file read is the one being extracted: the engine re-reads it whenever its hash changes
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

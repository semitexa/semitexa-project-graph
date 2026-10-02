<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Scanner\FileScanner;
use Semitexa\ProjectGraph\Application\Service\Scanner\IgnorePatternLoader;

/** What the scanner reads and leaves out — regressions from 2026-10-02. */
final class FileScannerRulesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-scan-rules-' . bin2hex(random_bytes(4));
        foreach (['src/Legacy/Old.php', 'src/Legacy/Deep/Keep.php', 'src/New.php', 'pkg/a/tests/fixtures/Fix.php', 'Secret.php'] as $file) {
            @mkdir(dirname($this->root . '/' . $file), 0777, true);
            file_put_contents($this->root . '/' . $file, "<?php\n");
        }
        file_put_contents($this->root . '/.graphignore', "src/Legacy/*.php\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return list<string> */
    private function scanned(FileScanner $scanner): array
    {
        $paths = array_map(fn ($r): string => substr($r->path, strlen((string) realpath($this->root)) + 1), $scanner->scan($this->root, []));
        sort($paths);

        return $paths;
    }

    #[Test]
    public function a_pattern_with_a_slash_is_a_path_from_the_root(): void
    {
        $paths = $this->scanned(new FileScanner(new IgnorePatternLoader()));

        self::assertNotContains('src/Legacy/Old.php', $paths, '"src/Legacy/*.php" matched only basenames, so never');
        self::assertContains('src/Legacy/Deep/Keep.php', $paths, '* does not cross a directory');
        self::assertContains('src/New.php', $paths);
    }

    #[Test]
    public function the_default_fixture_directory_is_left_out_at_any_depth(): void
    {
        self::assertNotContains('pkg/a/tests/fixtures/Fix.php', $this->scanned(new FileScanner(new IgnorePatternLoader())));
    }

    #[Test]
    public function an_unreadable_file_is_left_out_and_counted_not_fatal(): void
    {
        // A mode-000 file aborted the whole build. The hasher stands in for
        // the failing read, so the test means the same run as root or not.
        $scanner = new FileScanner(
            new IgnorePatternLoader(),
            static fn (string $path): string|false => str_ends_with($path, '/Secret.php') ? false : (string) hash_file('xxh3', $path),
        );

        $paths = $this->scanned($scanner);

        self::assertNotContains('Secret.php', $paths);
        self::assertSame(1, $scanner->lastExclusions()['unreadable'] ?? 0);
    }

    #[Test]
    public function an_executable_php_script_without_an_extension_is_read(): void
    {
        // bin/semitexa names the server commands; it was never scanned.
        mkdir($this->root . '/bin');
        file_put_contents($this->root . '/bin/tool', "#!/usr/bin/env php\n<?php\n");
        file_put_contents($this->root . '/bin/notes', "just text\n");

        $paths = $this->scanned(new FileScanner(new IgnorePatternLoader()));

        self::assertContains('bin/tool', $paths);
        self::assertNotContains('bin/notes', $paths);
    }

    #[Test]
    public function an_unreadable_directory_is_left_out_and_counted_not_fatal(): void
    {
        // It aborted the whole build from inside the directory iterator.
        mkdir($this->root . '/Locked');
        file_put_contents($this->root . '/Locked/Hidden.php', "<?php\n");
        chmod($this->root . '/Locked', 0000);
        $scanner = new FileScanner(new IgnorePatternLoader());

        try {
            $paths = $this->scanned($scanner);
        } finally {
            chmod($this->root . '/Locked', 0755);
        }

        // Root reads it anyway; anyone else must get it counted, not a crash.
        $root = function_exists('posix_getuid') && posix_getuid() === 0;
        self::assertSame($root, in_array('Locked/Hidden.php', $paths, true));
        self::assertSame($root ? 0 : 1, $scanner->lastExclusions()['unreadable'] ?? 0);
    }
}

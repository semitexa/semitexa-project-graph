<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Support;

/**
 * The fixture project as a throwaway git repository: the fixture committed
 * once as the base, then edited in the working tree (and committed again when
 * a test needs a second ref).
 */
final class GitFixtureRepo
{
    public readonly string $root;

    private function __construct()
    {
        $this->root = sys_get_temp_dir() . '/semitexa-graph-repo-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        self::copyTree(__DIR__ . '/../Fixture/GraphProject', $this->root);
        unlink($this->root . '/Broken.php.txt');
        $this->git('init', '--quiet', '--initial-branch=main');
        $this->commit('base');
    }

    public static function create(): self
    {
        return new self();
    }

    public function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    public function read(string $relative): string
    {
        return (string) file_get_contents($this->root . '/' . $relative);
    }

    public function delete(string $relative): void
    {
        unlink($this->root . '/' . $relative);
    }

    public function commit(string $message): void
    {
        $this->git('add', '-A');
        $this->git('-c', 'user.name=fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '--quiet', '--allow-empty', '-m', $message);
    }

    /** @return list<string> paths of the repository's worktrees */
    public function worktrees(): array
    {
        $out = $this->git('worktree', 'list', '--porcelain');

        return array_values(array_map(
            static fn (string $line): string => substr($line, strlen('worktree ')),
            array_filter(explode("\n", $out), static fn (string $line): bool => str_starts_with($line, 'worktree ')),
        ));
    }

    public function git(string ...$args): string
    {
        $process = proc_open(['git', '-C', $this->root, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . ' failed: ' . $err);
        }

        return $out;
    }

    public function __destruct()
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    private static function copyTree(string $from, string $to): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            $item->isDir() ? mkdir($target, 0777, true) : copy($item->getPathname(), $target);
        }
    }
}

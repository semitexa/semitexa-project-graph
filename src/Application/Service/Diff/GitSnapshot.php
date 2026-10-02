<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Diff;

/**
 * The files of one git ref, exported to a scratch directory for as long as a
 * callback runs — and the file set of the working tree as git sees it.
 *
 * This used to be a `git worktree add`. A worktree is registered in the
 * repository, so its cleanup ran `git worktree prune`, which is GLOBAL: inside
 * the container every worktree a developer made on the host points at a path
 * that does not exist there, and prune unregistered all of them — their branch
 * then deletable, their uncommitted work orphaned (measured 2026-10-02). A
 * killed run also left the registration behind (no `finally` on SIGINT), and a
 * checkout ran the user's post-checkout hook.
 *
 * Nor is it `git archive`, which came next: it honours `export-ignore`, so a
 * package that keeps tooling out of its dist archive (semitexa-dev's
 * resources/phpstan) had files the head side lists and the base never could —
 * every edge in them "added" on every PR. The tree is read into a throwaway
 * index (GIT_INDEX_FILE) and checked out under a prefix: nothing is written
 * into the repository, no hook runs, and no attribute decides what exists.
 *
 * A snapshot is held by an flock on `<snapshot>.lock` for as long as it lives.
 * The next run sweeps only what it can lock: a pid says nothing across PID
 * namespaces (a one-off container, the host), and a live export was deleted
 * under a concurrent run that judged it by /proc.
 */
final class GitSnapshot
{
    private const PREFIX = 'graph-base-';

    /** Leftovers of killed runs older than this are removed on the next run. */
    private const STALE_AFTER_SECONDS = 3600;

    /** The top level of the git repository containing $path, or null when it is not in one. */
    public static function repositoryRoot(string $path): ?string
    {
        [$code, $out] = self::git($path, ['rev-parse', '--show-toplevel']);

        return $code === 0 && trim($out) !== '' ? trim($out) : null;
    }

    /**
     * Files under $scope that git would commit: tracked, plus untracked ones
     * that are not ignored. The base side can only ever hold tracked files, so
     * a head side that also scanned build output or a gitignored generated/
     * directory reported edges no commit could have, and could block a merge
     * on them. Submodule contents are not listed, matching `git archive`.
     *
     * @return array<string, true> absolute path => true, or null when git cannot say
     */
    public static function workingFiles(string $repositoryRoot, string $scope): ?array
    {
        $args = ['ls-files', '-z', '--cached', '--others', '--exclude-standard'];
        if ($scope !== '') {
            $args[] = '--';
            $args[] = $scope;
        }
        [$code, $out] = self::git($repositoryRoot, $args);
        if ($code !== 0) {
            return null;
        }

        $files = [];
        foreach (explode("\0", $out) as $relative) {
            if ($relative === '') {
                continue;
            }
            // The scanner keys files by their resolved path.
            $path = rtrim($repositoryRoot, '/') . '/' . $relative;
            $files[realpath($path) ?: $path] = true;
        }

        return $files;
    }

    /**
     * @template T
     * @param callable(string $snapshotRoot): T $callback receives the root of the exported tree
     * @param string $scope a directory relative to the repository: only it is exported
     * @return T
     */
    public static function with(string $repositoryRoot, string $ref, string $parentDir, callable $callback, string $scope = ''): mixed
    {
        // A ref is never an option: `--output=…` would otherwise reach rev-parse as one.
        if ($ref === '' || str_starts_with($ref, '-')) {
            throw new \InvalidArgumentException(sprintf('Not a commit in %s: %s', $repositoryRoot, $ref));
        }
        [$code, $sha, $err] = self::git($repositoryRoot, ['rev-parse', '--verify', '--quiet', $ref . '^{commit}']);
        $sha = trim($sha);
        if ($code !== 0 || $sha === '') {
            throw new \InvalidArgumentException(rtrim(sprintf('Not a commit in %s: %s %s', $repositoryRoot, $ref, trim($err))));
        }

        if (!is_dir($parentDir) && !mkdir($parentDir, 0777, true) && !is_dir($parentDir)) {
            throw new \RuntimeException('Cannot create ' . $parentDir);
        }
        self::sweep($parentDir);

        $snapshot = rtrim($parentDir, '/') . '/' . self::PREFIX . getmypid() . '-' . bin2hex(random_bytes(6));
        $lock = @fopen($snapshot . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot lock ' . $snapshot . '.lock');
        }
        if (!flock($lock, LOCK_EX)) {
            // sweep() skips .lock files and no snapshot exists to judge this
            // one by: left behind, nothing would ever remove it.
            fclose($lock);
            @unlink($snapshot . '.lock');
            throw new \RuntimeException('Cannot lock ' . $snapshot . '.lock');
        }
        $index = $snapshot . '.index';

        try {
            if (!mkdir($snapshot, 0777) && !is_dir($snapshot)) {
                throw new \RuntimeException('Cannot create ' . $snapshot);
            }
            // A scope the ref does not have (a module this change adds) is an
            // empty base, so everything is "added" — the export refused it
            // with "pathspec did not match" and the gate failed every PR that
            // adds a module (round 2).
            if ($scope !== '' && self::git($repositoryRoot, ['cat-file', '-e', $sha . ':' . $scope])[0] !== 0) {
                return $callback($snapshot);
            }
            // The resolved SHA, not the user's ref. A scope is read as the root
            // of the index and checked out under its own name, so the callback
            // finds <snapshot>/<scope> exactly as with a full export.
            $env = ['GIT_INDEX_FILE' => $index];
            [$code, , $err] = self::git($repositoryRoot, ['read-tree', $scope === '' ? $sha : $sha . ':' . $scope], $env);
            if ($code !== 0) {
                throw new \RuntimeException(sprintf('Reading %s failed: %s', $ref, trim($err)));
            }
            $prefix = $snapshot . '/' . ($scope === '' ? '' : rtrim($scope, '/') . '/');
            [$code, , $err] = self::git($repositoryRoot, ['checkout-index', '--all', '--force', '--prefix=' . $prefix], $env);
            if ($code !== 0) {
                throw new \RuntimeException(sprintf('Exporting %s failed: %s', $ref, trim($err)));
            }

            return $callback($snapshot);
        } finally {
            @unlink($index);
            self::remove($snapshot);
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($snapshot . '.lock');
        }
    }

    /**
     * What killed runs left behind: a snapshot whose lock nobody holds, or —
     * from before locks, or a lock that cannot be opened — one over an hour old.
     */
    private static function sweep(string $parentDir): void
    {
        foreach (glob(rtrim($parentDir, '/') . '/' . self::PREFIX . '*') ?: [] as $path) {
            if (str_ends_with($path, '.lock') || str_ends_with($path, '.index')) {
                continue; // judged with the snapshot they belong to
            }
            $base = preg_replace('/\.tar$/', '', $path);
            $mtime = @filemtime($path);
            $old = $mtime !== false && $mtime < time() - self::STALE_AFTER_SECONDS;
            $held = null;
            $lock = is_file($base . '.lock') ? @fopen($base . '.lock', 'c') : false;
            if ($lock !== false) {
                $held = !flock($lock, LOCK_EX | LOCK_NB);
            }
            if ($held === false || ($held === null && $old)) {
                is_dir($path) && !is_link($path) ? self::remove($path) : @unlink($path);
                @unlink($base . '.index');
                @unlink($base . '.lock');
            }
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * The caller's environment without the variables that redirect git to
     * another repository. A git hook or CI wrapper exports GIT_DIR and friends;
     * inherited, they would make `-C <repo>` operate on the outer checkout.
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $env = getenv();
        // Paths are paths: a scope named ":odd" was read as pathspec magic.
        $env['GIT_LITERAL_PATHSPECS'] = '1';
        foreach (['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_OBJECT_DIRECTORY', 'GIT_ALTERNATE_OBJECT_DIRECTORIES', 'GIT_COMMON_DIR', 'GIT_NAMESPACE', 'GIT_CEILING_DIRECTORIES', 'GIT_PREFIX'] as $name) {
            unset($env[$name]);
        }

        return $env;
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env set on top of the scrubbed environment
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private static function git(string $cwd, array $args, array $env = []): array
    {
        return self::run(['git', '-C', $cwd, ...$args], $env);
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private static function run(array $command, array $env = []): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + self::environment());
        if (!is_resource($process)) {
            return [127, '', $command[0] . ' could not be started'];
        }
        // Read both pipes without letting a full stderr block stdout.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $read = array_filter([$pipes[1], $pipes[2]], static fn ($p): bool => !feof($p));
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 5) === false) {
                break;
            }
            foreach ($read as $pipe) {
                $chunk = (string) fread($pipe, 65536);
                $pipe === $pipes[1] ? $out .= $chunk : $err .= $chunk;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}

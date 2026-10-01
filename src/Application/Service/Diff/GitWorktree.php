<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Diff;

/**
 * A detached checkout of one git ref next to the working tree, for as long as
 * a callback runs. Always removed afterwards, whether the callback succeeded
 * or not — a leftover worktree would show up in the repository's
 * `git worktree list` and hold the ref's files on disk.
 */
final class GitWorktree
{
    /** The top level of the git repository containing $path, or null when it is not in one. */
    public static function repositoryRoot(string $path): ?string
    {
        [$code, $out] = self::git($path, ['rev-parse', '--show-toplevel']);

        return $code === 0 && trim($out) !== '' ? trim($out) : null;
    }

    /**
     * @template T
     * @param callable(string $checkoutRoot): T $callback receives the root of the checkout
     * @return T
     */
    public static function with(string $repositoryRoot, string $ref, string $parentDir, callable $callback): mixed
    {
        [$code, $sha, $err] = self::git($repositoryRoot, ['rev-parse', '--verify', '--quiet', $ref . '^{commit}']);
        $sha = trim($sha);
        if ($code !== 0 || $sha === '') {
            throw new \InvalidArgumentException(sprintf('Not a commit in %s: %s %s', $repositoryRoot, $ref, trim($err)));
        }

        if (!is_dir($parentDir) && !mkdir($parentDir, 0777, true) && !is_dir($parentDir)) {
            throw new \RuntimeException('Cannot create ' . $parentDir);
        }
        $checkout = rtrim($parentDir, '/') . '/graph-base-' . bin2hex(random_bytes(6));

        // The resolved SHA, not the user's ref: it cannot be read as an option,
        // and the checkout is exactly the commit that was verified above.
        [$code, , $err] = self::git($repositoryRoot, ['worktree', 'add', '--detach', '--quiet', $checkout, $sha]);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf('git worktree add failed for %s: %s', $ref, trim($err)));
        }

        try {
            return $callback($checkout);
        } finally {
            self::git($repositoryRoot, ['worktree', 'remove', '--force', $checkout]);
            self::git($repositoryRoot, ['worktree', 'prune']);
        }
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
        foreach (['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_OBJECT_DIRECTORY', 'GIT_ALTERNATE_OBJECT_DIRECTORIES', 'GIT_COMMON_DIR', 'GIT_NAMESPACE', 'GIT_CEILING_DIRECTORIES', 'GIT_PREFIX'] as $name) {
            unset($env[$name]);
        }

        return $env;
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private static function git(string $cwd, array $args): array
    {
        $process = proc_open(['git', '-C', $cwd, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, self::environment());
        if (!is_resource($process)) {
            return [127, '', 'git could not be started'];
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}

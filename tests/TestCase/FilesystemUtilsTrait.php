<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage\Test\TestCase;

use RuntimeException;

/**
 * Trait with utilities for filesystem testing.
 */
trait FilesystemUtilsTrait
{
    /**
     * Result of all invocations to `flock`.
     *
     * @var array<array{stdout: string, stderr: string}>
     */
    protected array $flock = [];

    /**
     * Create temporary directory.
     *
     * @param int $mode Permissions.
     * @return string Path.
     * @throws \Random\RandomException
     */
    protected static function tempDir(int $mode = 0700): string
    {
        $path = TMP . bin2hex(random_bytes(16)) . DIRECTORY_SEPARATOR;
        mkdir($path, $mode & 0777, true);

        return $path;
    }

    /**
     * Invoke the passed callback while retaining an exclusive lock on the passed file.
     *
     * @param string $file File to retain lock upon.
     * @param callable $cb Callback to invoke while lock is retained.
     * @return void
     */
    protected function withFlock(string $file, callable $cb): void
    {
        // Open a process that does nothing while retaining exclusive lock on `$file`:
        $proc = proc_open(
            [dirname(__DIR__) . DIRECTORY_SEPARATOR . 'flock', $file, 'tail', '-f', '/dev/null'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w'], 9 => ['pipe', 'w']],
            $pipes,
        );
        if ($proc === false) {
            throw new RuntimeException('Cannot open sub-process to retain file lock');
        }
        fclose($pipes[0]); // Immediately close STDIN.
        stream_set_blocking($pipes[1], false); // Set STDOUT to non-blocking.
        stream_set_blocking($pipes[2], false); // Set STDERR to non-blocking.
        fread($pipes[9], 1); // Wait until the subprocess signals it has acquired the lock.

        try {
            $cb();
        } finally {
            $this->flock[] = [
                'stdout' => stream_get_contents($pipes[1]) ?: '',
                'stderr' => stream_get_contents($pipes[2]) ?: '',
            ];

            // Close all pipes.
            fclose($pipes[1]);
            fclose($pipes[2]);
            fclose($pipes[9]);

            proc_terminate($proc);
            proc_close($proc);
        }
    }
}

<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage\Utils;

use Chialab\ObjectStorage\Exception\StorageException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Webmozart\Assert\Assert;

/**
 * Filesystem-related utility methods.
 *
 * @internal
 */
class Filesystem
{
    /**
     * Private constructor to disable instantiating this class.
     *
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Touch a file and set its permissions.
     *
     * @param string $path File path.
     * @param int $permissions File permissions.
     * @return void
     */
    public static function chmod(string $path, int $permissions): void
    {
        Assert::range($permissions, 0, 0777, 'Permissions should be a three-digit octal number');

        if (touch($path) !== true) {
            throw new StorageException(sprintf('Cannot touch: %s', $path));
        }
        if (chmod($path, $permissions) !== true) {
            throw new StorageException(sprintf('Cannot set permission %o: %s', $permissions, $path));
        }
    }

    /**
     * Open a local file for reading and acquire shared lock.
     *
     * @param string $path File path.
     * @return resource
     */
    public static function lockingRead(string $path)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new StorageException(sprintf('File does not exist: %s', $path));
        }

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new StorageException(sprintf('Cannot open: %s', $path));
        }
        if (flock($fh, LOCK_SH | LOCK_NB, $wouldBlock) === false || $wouldBlock === 1) {
            Stream::close($fh);

            throw new StorageException(sprintf('Cannot acquire shared lock: %s', $path));
        }

        return $fh;
    }

    /**
     * Open a local file for writing, acquire exclusive lock, and copy data from a source stream.
     *
     * @param string $path File path.
     * @param int $permissions File permissions.
     * @param callable(resource): void|null $cb Callback to be invoked with open file handler while still retaining exclusive lock.
     * @param resource ...$data Resource where data should be copied from.
     * @return void
     */
    public static function lockingWrite(string $path, int $permissions, ?callable $cb, ...$data): void
    {
        Assert::allResource($data, 'stream');

        static::chmod($path, $permissions);
        $fh = fopen($path, 'cb+');
        if ($fh === false) {
            throw new StorageException(sprintf('Cannot open: %s', $path));
        }
        try {
            if (flock($fh, LOCK_EX | LOCK_NB, $wouldBlock) !== true || $wouldBlock === 1) {
                throw new StorageException(sprintf('Cannot acquire exclusive lock: %s', $path));
            }
            if (ftruncate($fh, 0) !== true) {
                throw new StorageException(sprintf('Cannot truncate file: %s', $path));
            }
            array_walk($data, fn ($datum) => Stream::streamCopyToStream($datum, $fh));

            if ($cb !== null) {
                $cb($fh);
            }
        } finally {
            Stream::close($fh);
        }
    }

    /**
     * Recursive list a directory's contents.
     *
     * @param string $path Directory path.
     * @return iterable<string, \SplFileInfo>
     */
    public static function recursiveLs(string $path): iterable
    {
        $it = new RecursiveDirectoryIterator(
            $path,
            FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS,
        );
        /** @var \Iterator<string, \SplFileInfo> $it */
        $it = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

        yield from $it;
        yield $path => new SplFileInfo($path);
    }

    /**
     * Ensure a directory exists.
     *
     * @param string $path Directory path.
     * @param int $permissions Permissions.
     * @return void
     */
    public static function mkDir(string $path, int $permissions): void
    {
        Assert::range($permissions, 0, 0777, 'Permissions should be a three-digit octal number');

        if (is_dir($path)) {
            return;
        }
        if (mkdir($path, $permissions, true) !== true) {
            throw new StorageException(sprintf('Cannot create directory: %s', $path));
        }
    }

    /**
     * Recursively remove a directory and its contents.
     *
     * @param string $path Directory path.
     * @return void
     */
    public static function rmDir(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        foreach (static::recursiveLs($path) as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
    }
}

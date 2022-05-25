<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage\Utils;

use Chialab\ObjectStorage\Exception\StorageException;
use Webmozart\Assert\Assert;

/**
 * Stream-related utility methods.
 *
 * @internal
 */
class Stream
{
    /**
     * Private constructor to disable instantiating this class.
     */
    private function __construct()
    {
    }

    /**
     * Copy a stream contents into a temporary resource.
     *
     * @param resource $resource Resource handler as opened by {@see fopen()}.
     * @return resource
     */
    public static function temporaryStreamCopy($resource)
    {
        Assert::resource($resource, 'stream');

        $copy = fopen('php://temp', 'rb+');
        if ($copy === false) {
            throw new StorageException('Failed to open temporary stream');
        }
        if (stream_copy_to_stream($resource, $copy) === false) {
            static::close($copy);

            throw new StorageException('Failed copy to temporary stream');
        }
        if (rewind($copy) === false) {
            static::close($copy);

            throw new StorageException('Failed to rewind temporary stream');
        }

        return $copy;
    }

    /**
     * Close resource.
     *
     * @param resource $resource Resource handler as opened by {@see fopen()}.
     * @return void
     */
    public static function close($resource): void
    {
        Assert::resource($resource, 'stream');

        if (fclose($resource) === false) {
            trigger_error('Failed to free system resources', E_USER_WARNING);
        }
    }

    /**
     * Compute hash for stream. This method will leave the internal pointer at the end of the stream.
     *
     * @param resource $resource Resource handler as opened by {@see fopen()}.
     * @param string $algo Hashing algorithm.
     * @return string Hex-encoded hash.
     */
    public static function hash($resource, string $algo): string
    {
        Assert::resource($resource, 'stream');

        $ctx = hash_init($algo);
        hash_update_stream($ctx, $resource);

        return hash_final($ctx);
    }
}

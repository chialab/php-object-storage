<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage\Utils;

use Chialab\ObjectStorage\Exception\StorageException;
use Psr\Http\Message\StreamInterface;
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
     * Initialize a new temporary stream.
     *
     * @return resource
     */
    public static function newTemporaryStream()
    {
        $fh = fopen('php://temp', 'rb+');
        if ($fh === false) {
            throw new StorageException('Failed to open temporary stream');
        }

        return $fh;
    }

    /**
     * Copy a stream contents to another stream.
     *
     * @param resource $source Source stream resource handler as opened by {@see fopen()}.
     * @param resource $dest Destination stream resource handler as opened by {@see fopen()}.
     * @return void
     */
    public static function streamCopyToStream($source, $dest): void
    {
        Assert::resource($source, 'stream');
        Assert::resource($dest, 'stream');

        if (stream_copy_to_stream($source, $dest) === false) {
            throw new StorageException('Failed copy to destination stream');
        }
    }

    /**
     * Copy a PSR stream to another stream.
     *
     * @param \Psr\Http\Message\StreamInterface $source Source stream.
     * @param resource $dest Destination stream resource handler as opened by {@see \fopen()}
     * @return void
     */
    public static function psrCopyToStream(StreamInterface $source, $dest): void
    {
        Assert::resource($dest, 'stream');
        Assert::true($source->isReadable(), 'PSR source stream must be readable');

        while (!$source->eof()) {
            $chunk = $source->read(1 << 13);
            if (fwrite($dest, $chunk) < strlen($chunk)) {
                throw new StorageException('Failed copy to destination stream');
            }
        }
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

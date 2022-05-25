<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage;

use Psr\Http\Message\StreamInterface;

/**
 * Class representing a single object file in the object storage.
 */
class FileObject
{
    /**
     * Constructor.
     *
     * @param string $key Object key.
     * @param \Psr\Http\Message\StreamInterface|null $data Object data.
     * @param array<string, mixed> $metadata Object metadata.
     */
    public function __construct(public readonly string $key, public readonly StreamInterface|null $data, public readonly array $metadata = [])
    {
    }
}

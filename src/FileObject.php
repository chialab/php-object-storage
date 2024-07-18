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
     * Default content type.
     *
     * @var string
     */
    public const DEFAULT_CONTENT_TYPE = 'application/octet-stream';

    /**
     * Constructor.
     *
     * @param string $key Object key.
     * @param \Psr\Http\Message\StreamInterface|null $data Object data.
     * @param array<string, mixed> $metadata Object metadata.
     * @codeCoverageIgnore
     */
    public function __construct(public readonly string $key, public readonly ?StreamInterface $data, public readonly array $metadata = [])
    {
    }

    /**
     * Get the content type of the object.
     *
     * @return string
     */
    public function getContentType(): string
    {
        return $this->metadata['ContentType'] ?? static::DEFAULT_CONTENT_TYPE;
    }
}

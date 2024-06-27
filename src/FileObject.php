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
    public static $defaultContentType = 'application/octet-stream';

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

    /**
     * Get the content type of the object.
     *
     * @return string
     */
    public function getContentType(): string
    {
        if ($this->metadata['ContentType'] !== null) {
            return $this->metadata['ContentType'];
        }
        return mime_content_type($this->key) ?: static::$defaultContentType;
    }
}

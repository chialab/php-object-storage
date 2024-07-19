<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage;

use Psr\Http\Message\StreamInterface;

/**
 * Class representing a part of a multipart upload.
 */
class FilePart
{
    /**
     * Constructor.
     *
     * @param int $part Part number.
     * @param \Psr\Http\Message\StreamInterface|null $data Object data.
     * @param string|null $hash Hash as returned by the server when the part had been originally uploaded.
     * @param array<string, mixed> $metadata Object metadata.
     * @codeCoverageIgnore
     */
    public function __construct(public readonly int $part, public readonly ?StreamInterface $data, public readonly ?string $hash = null, public readonly array $metadata = [])
    {
    }
}

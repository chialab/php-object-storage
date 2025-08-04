<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage;

use Chialab\ObjectStorage\Exception\BadDataException;
use Chialab\ObjectStorage\Exception\ObjectNotFoundException;
use Chialab\ObjectStorage\Exception\StorageException;
use Chialab\ObjectStorage\Utils\Promise;
use Chialab\ObjectStorage\Utils\Stream;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Stream as PsrStream;
use Webmozart\Assert\Assert;

/**
 * In-memory object storage, for testing purposes.
 */
class InMemoryAdapter implements MultipartUploadInterface
{
    /**
     * In-memory storage.
     *
     * @var array<string, \Chialab\ObjectStorage\FileObject>
     */
    protected array $storage = [];

    /**
     * Multipart uploads.
     *
     * @var array<string, array{\Chialab\ObjectStorage\FileObject, array<int, \Chialab\ObjectStorage\FilePart>}>
     */
    protected array $multipart = [];

    /**
     * Get a copy of a file object.
     *
     * @param \Chialab\ObjectStorage\FileObject $object File object to copy.
     * @return \Chialab\ObjectStorage\FileObject
     */
    protected static function copyObject(FileObject $object): FileObject
    {
        $stream = null;
        if ($object->data !== null) {
            $copy = Stream::newTemporaryStream();
            Stream::psrCopyToStream($object->data, $copy);
            rewind($copy);
            $stream = new PsrStream($copy);
        }

        return new FileObject($object->key, $stream, $object->metadata);
    }

    /**
     * Adapter constructor.
     *
     * @param string $baseUrl Base URL.
     * @codeCoverageIgnore
     */
    public function __construct(protected readonly string $baseUrl)
    {
    }

    /**
     * @inheritDoc
     */
    public function url(string $key): string
    {
        return $this->baseUrl . $key;
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): PromiseInterface
    {
        return Promise::async(fn(): bool => isset($this->storage[$key]));
    }

    /**
     * @inheritDoc
     */
    public function get(string $key): PromiseInterface
    {
        return Promise::async(fn(): FileObject => static::copyObject(
            $this->storage[$key] ?? throw new ObjectNotFoundException(sprintf('Object not found: %s', $key)),
        ));
    }

    /**
     * @inheritDoc
     */
    public function put(FileObject $object): PromiseInterface
    {
        return Promise::async(function () use ($object): void {
            if ($object->data === null) {
                throw new BadDataException('Missing object data');
            }

            $this->storage[$object->key] = static::copyObject($object);
        });
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): PromiseInterface
    {
        return Promise::async(function () use ($key): void {
            unset($this->storage[$key]);
        });
    }

    /**
     * @inheritDoc
     */
    public function multipartInit(FileObject $object): PromiseInterface
    {
        return Promise::async(function () use ($object): string {
            $token = bin2hex(random_bytes(32));
            $this->multipart[$token] = [$object, []];

            return $token;
        });
    }

    /**
     * @inheritDoc
     */
    public function multipartUpload(FileObject $object, string $token, FilePart $part): PromiseInterface
    {
        Assert::positiveInteger($part->part);

        return Promise::async(function () use ($object, $token, $part): string {
            if (!isset($this->multipart[$token]) || $this->multipart[$token][0]->key !== $object->key) {
                throw new BadDataException(sprintf('Multipart upload not initialized: %s', $token));
            }

            $data = $part->data ?? throw new BadDataException('Missing part data');
            $copy = Stream::newTemporaryStream();
            Stream::psrCopyToStream($data, $copy);
            rewind($copy);
            $hash = Stream::hash($copy, 'sha256');
            rewind($copy);

            $this->multipart[$token][1][$part->part] = new FilePart($part->part, new PsrStream($copy), $hash, $part->metadata);

            return $hash;
        });
    }

    /**
     * @inheritDoc
     */
    public function multipartFinalize(FileObject $object, string $token, FilePart ...$parts): PromiseInterface
    {
        return Promise::async(function () use ($object, $token, $parts): void {
            $initialObject = $this->multipart[$token][0] ?? throw new BadDataException(sprintf('Multipart upload not initialized: %s', $token));
            if ($initialObject->key !== $object->key) {
                throw new BadDataException(sprintf('Multipart upload not initialized: %s', $token));
            }

            $dest = Stream::newTemporaryStream();
            $partNo = 0;
            foreach ($parts as $part) {
                if ($part->part <= $partNo) {
                    throw new BadDataException('Parts must be sorted monotonically');
                }

                $uploaded = $this->multipart[$token][1][$part->part] ?? throw new BadDataException(sprintf('Part not uploaded: %d', $part->part));
                if ($uploaded->hash !== $part->hash) {
                    throw new BadDataException(sprintf('Hash mismatch for part %d', $part->part));
                }

                $data = $uploaded->data ?? throw new StorageException('Missing part data');
                Stream::psrCopyToStream($data, $dest);

                $partNo = $part->part;
            }
            rewind($dest);

            $this->storage[$object->key] = new FileObject($object->key, new PsrStream($dest), $initialObject->metadata ?: $object->metadata);
            unset($this->multipart[$token]);
        });
    }

    /**
     * @inheritDoc
     */
    public function multipartAbort(FileObject $object, string $token): PromiseInterface
    {
        return Promise::async(function () use ($token): void {
            unset($this->multipart[$token]);
        });
    }
}

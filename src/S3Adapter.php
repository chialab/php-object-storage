<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage;

use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\S3Client;
use Chialab\ObjectStorage\Exception\BadDataException;
use Chialab\ObjectStorage\Exception\ObjectNotFoundException;
use Chialab\ObjectStorage\Exception\StorageException;
use Chialab\ObjectStorage\Utils\Stream;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Stream as PsrStream;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use UnexpectedValueException;

/**
 * Adapter for Amazon S3.
 */
class S3Adapter implements MultipartUploadInterface
{
    /**
     * S3 adapter constructor.
     *
     * @param \Aws\S3\S3Client $client S3 client instance.
     * @param string $bucket Bucket name.
     * @param string $prefix Key prefix.
     */
    public function __construct(
        protected readonly S3Client $client,
        protected readonly string $bucket,
        protected readonly string $prefix = '',
    ) {
    }

    /**
     * Apply prefix to key.
     *
     * @param string $key Key to be prefixed.
     * @return string
     */
    protected function prefix(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * @inheritDoc
     */
    public function url(string $key): string
    {
        return $this->client->getObjectUrl($this->bucket, $this->prefix($key));
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): PromiseInterface
    {
        return $this->client->headObjectAsync(['Bucket' => $this->bucket, 'Key' => $this->prefix($key)])
            ->then(
                fn (): bool => true,
                fn (AwsException $e): PromiseInterface => $e->getStatusCode() === 404
                    ? new FulfilledPromise(false)
                    : new RejectedPromise(
                        new StorageException(sprintf('Cannot check object existence: %s', $key), previous: $e),
                    ),
            );
    }

    /**
     * @inheritDoc
     */
    public function get(string $key): PromiseInterface
    {
        $toStream = function (mixed $body): StreamInterface {
            if ($body instanceof StreamInterface) {
                return $body;
            } elseif (is_string($body)) {
                $body = Stream::fromString($body);
            } elseif (!is_resource($body)) {
                throw new InvalidArgumentException(sprintf('Unexpected object body format: expected one of string, resource or %s, got %s', StreamInterface::class, get_debug_type($body)));
            }

            return new PsrStream($body);
        };

        return $this->client->getObjectAsync(['Bucket' => $this->bucket, 'Key' => $this->prefix($key)])
            ->then(
                fn (Result $result): FileObject => new FileObject(
                    $key,
                    $toStream($result['Body']),
                    array_diff_key($result->toArray(), ['Body' => null])
                ),
                fn (AwsException $e) => new RejectedPromise(
                    $e->getStatusCode() === 404
                        ? new ObjectNotFoundException(sprintf('Object not found: %s', $key))
                        : new StorageException(sprintf('Cannot download object: %s', $key), previous: $e)
                ),
            );
    }

    /**
     * @inheritDoc
     */
    public function put(FileObject $object): PromiseInterface
    {
        $body = $object->data?->detach();
        if (!is_resource($body)) {
            return new RejectedPromise(new BadDataException('Missing object data'));
        }

        return $this->client
            ->putObjectAsync([
                'Bucket' => $this->bucket,
                'Key' => $this->prefix($object->key),
                'Body' => $body,
            ])
            ->then(
                fn () => null,
                fn (AwsException $e): PromiseInterface => new RejectedPromise(
                    new StorageException(sprintf('Cannot upload object: %s', $object->key), previous: $e),
                ),
            );
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): PromiseInterface
    {
        return $this->client
            ->deleteObjectAsync([
                'Bucket' => $this->bucket,
                'Key' => $this->prefix($key),
            ])
            ->then(
                fn () => null,
                fn (AwsException $e): PromiseInterface => new RejectedPromise(
                    new StorageException(sprintf('Cannot delete object: %s', $key), previous: $e),
                ),
            );
    }

    /**
     * @inheritDoc
     */
    public function multipartInit(FileObject $object): PromiseInterface
    {
        return $this->client
            ->createMultipartUploadAsync([
                'Bucket' => $this->bucket,
                'Key' => $this->prefix($object->key),
            ])
            ->then(
                fn (Result $result): string => is_string($result['UploadId']) ? $result['UploadId'] : throw new UnexpectedValueException(sprintf('Expected %s to be string, got %s', 'UploadId', get_debug_type($result['UploadId']))),
                fn (AwsException $e): PromiseInterface => new RejectedPromise(
                    new StorageException(sprintf('Cannot initialize multipart upload: %s', $object->key), previous: $e),
                ),
            );
    }

    /**
     * @inheritDoc
     */
    public function multipartUpload(FileObject $object, string $token, FilePart $part): PromiseInterface
    {
        $body = $part->data?->detach();
        if (!is_resource($body)) {
            return new RejectedPromise(new BadDataException('Missing part data'));
        }

        return $this->client
            ->uploadPartAsync([
                'Bucket' => $this->bucket,
                'Key' => $this->prefix($object->key),
                'UploadId' => $token,
                'PartNumber' => $part->part,
                'Body' => $body,
            ])
            ->then(
                fn (Result $result): string => is_string($result['ETag']) ? $result['ETag'] : throw new UnexpectedValueException(sprintf('Expected %s to be string, got %s', 'ETag', get_debug_type($result['ETag']))),
                fn (AwsException $e): PromiseInterface => new RejectedPromise(
                    new StorageException(sprintf('Cannot upload part %d: %s', $part->part, $object->key), previous: $e),
                ),
            );
    }

    /**
     * @inheritDoc
     */
    public function multipartFinalize(FileObject $object, string $token, FilePart ...$parts): PromiseInterface
    {
        return $this->client
            ->completeMultipartUploadAsync([
                'Bucket' => $this->bucket,
                'Key' => $this->prefix($object->key),
                'UploadId' => $token,
                'MultipartUpload' => [
                    'Parts' => array_map(
                        fn (FilePart $part): array => ['PartNumber' => $part->part, 'ETag' => $part->hash],
                        $parts,
                    ),
                ],
            ])
            ->then(
                fn () => null,
                fn (AwsException $e): PromiseInterface => new RejectedPromise(
                    new StorageException(sprintf('Cannot complete multipart upload: %s', $object->key), previous: $e),
                ),
            );
    }

    /**
     * @inheritDoc
     */
    public function multipartAbort(FileObject $object, string $token): PromiseInterface
    {
        return $this->client
            ->abortMultipartUploadAsync([
                'Bucket' => $this->bucket,
                'Key' => $this->prefix($object->key),
                'UploadId' => $token,
            ])
            ->then(
                fn () => null,
                fn (AwsException $e): PromiseInterface => new RejectedPromise(new StorageException('AWS API error', previous: $e)),
            );
    }
}

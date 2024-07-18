<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage;

use Chialab\ObjectStorage\Exception\BadDataException;
use Chialab\ObjectStorage\Exception\ObjectNotFoundException;
use Chialab\ObjectStorage\Exception\StorageException;
use Chialab\ObjectStorage\Utils\Filesystem;
use Chialab\ObjectStorage\Utils\Path;
use Chialab\ObjectStorage\Utils\Promise;
use Chialab\ObjectStorage\Utils\Stream;
use Generator;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Stream as PsrStream;
use Webmozart\Assert\Assert;

/**
 * Simulate an object storage using local filesystem.
 */
class FilesystemAdapter implements MultipartUploadInterface
{
    /**
     * Get preferred hashing algorithm.
     *
     * @return string
     */
    protected static function hashAlgorithm(): string
    {
        /** @var non-empty-array<string> $algorithms */
        $algorithms = array_intersect(['sha256', 'sha1', 'sha512', 'md5'], hash_algos()) ?: hash_algos();

        return array_shift($algorithms);
    }

    /**
     * Get filename for part.
     *
     * @param \Chialab\ObjectStorage\FilePart $part File part.
     * @return string
     */
    protected static function partFilename(FilePart $part): string
    {
        return sprintf('part%05d', $part->part - 1);
    }

    /**
     * Umask for created resources.
     *
     * @var int
     */
    protected readonly int $umask;

    /**
     * Adapter constructor.
     *
     * @param string $root Root path where files will be stored.
     * @param string $multipartRoot Temporary path where incomplete multipart uploads should be stored.
     * @param string $baseUrl Public base URL for resources.
     * @param int $umask Umask for created resources.
     * @codeCoverageIgnore
     */
    public function __construct(protected readonly string $root, protected readonly string $multipartRoot, protected readonly string $baseUrl, int $umask = 0077)
    {
        $this->umask = 0777 & $umask;
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
        $path = Path::join($this->root, $key);

        return Promise::async(fn (): bool => is_file($path));
    }

    /**
     * @inheritDoc
     */
    public function get(string $key): PromiseInterface
    {
        return Promise::async(function () use ($key): FileObject {
            $path = Path::join($this->root, $key);
            if (!is_file($path)) {
                throw new ObjectNotFoundException(sprintf('Object not found: %s', $key));
            }

            $file = Filesystem::lockingRead($path);
            try {
                $copy = Stream::newTemporaryStream();
                Stream::streamCopyToStream($file, $copy);
            } finally {
                Stream::close($file);
            }

            return new FileObject($key, new PsrStream($copy), [
                'ContentType' => mime_content_type($path) ?: null,
            ]);
        });
    }

    /**
     * Write a file to disk.
     *
     * @param string $key Key where it should be stored.
     * @param resource ...$sources Sorted list of streams to be concatenated.
     * @return void
     */
    protected function write(string $key, ...$sources): void
    {
        $path = Path::join($this->root, $key);

        try {
            Filesystem::mkDir(dirname($path), 0777 & ~$this->umask);
            Filesystem::lockingWrite($path, 0666 & ~$this->umask, null, ...$sources);
        } finally {
            array_walk($sources, Stream::close(...));
        }
    }

    /**
     * @inheritDoc
     */
    public function put(FileObject $object): PromiseInterface
    {
        return Promise::async(function () use ($object): void {
            $source = $object->data?->detach();
            if ($source === null) {
                throw new BadDataException('Missing object data');
            }

            $this->write($object->key, $source);
        });
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): PromiseInterface
    {
        return Promise::async(function () use ($key): void {
            $path = Path::join($this->root, $key);
            if (!file_exists($path)) {
                return;
            }
            if (!is_file($path) || unlink($path) === false) {
                throw new StorageException(sprintf('Cannot delete %s', $key));
            }
        });
    }

    /**
     * Return path to multipart upload directory.
     *
     * @param \Chialab\ObjectStorage\FileObject $object File object.
     * @param string $token Multipart upload token.
     * @param bool $checkInitialized Check that multipart upload has been already initialized.
     * @return string
     */
    protected function multipartPath(FileObject $object, string $token, bool $checkInitialized = false): string
    {
        $path = Path::join(
            $this->multipartRoot,
            $token,
            hash(static::hashAlgorithm(), $object->key),
        );
        if ($checkInitialized && !is_dir($path)) {
            throw new BadDataException(sprintf('Multipart upload not initialized: %s', $token));
        }

        return $path;
    }

    /**
     * @inheritDoc
     */
    public function multipartInit(FileObject $object): PromiseInterface
    {
        return Promise::async(function () use ($object): string {
            $token = bin2hex(random_bytes(32));
            $path = $this->multipartPath($object, $token);
            Filesystem::mkDir($path, 0777 & ~$this->umask);

            return $token;
        });
    }

    /**
     * @inheritDoc
     */
    public function multipartUpload(FileObject $object, string $token, FilePart $part): PromiseInterface
    {
        Assert::range($part->part, 1, 100000, 'Part number must be an integer between 1 and 10000');

        return Promise::async(function () use ($object, $token, $part): string {
            $path = $this->multipartPath($object, $token, true);
            $path = Path::join($path, static::partFilename($part));
            $source = $part->data?->detach();
            if ($source === null) {
                throw new BadDataException('Missing part data');
            }

            try {
                $hash = '';
                Filesystem::lockingWrite(
                    $path,
                    0666 & ~$this->umask,
                    function ($fh) use (&$hash): void {
                        rewind($fh);
                        $hash = Stream::hash($fh, static::hashAlgorithm());
                    },
                    $source
                );
            } finally {
                Stream::close($source);
            }

            return $hash;
        });
    }

    /**
     * Read all uploaded parts, yielding them in the correct order and checking hashes.
     *
     * @param \Chialab\ObjectStorage\FileObject $object File object.
     * @param string $token Multipart upload token.
     * @param \Chialab\ObjectStorage\FilePart ...$parts File parts.
     * @return \Generator<resource>
     */
    protected function readParts(FileObject $object, string $token, FilePart ...$parts): Generator
    {
        $basePath = $this->multipartPath($object, $token, true);

        $partNo = 0;
        foreach ($parts as $part) {
            if ($part->part <= $partNo) {
                throw new BadDataException('Parts must be sorted monotonically');
            }

            $path = Path::join($basePath, static::partFilename($part));
            if (!is_file($path)) {
                throw new BadDataException(sprintf('Part not uploaded: %d', $part->part));
            }

            $fh = Filesystem::lockingRead($path);
            if ($part->hash !== Stream::hash($fh, static::hashAlgorithm())) {
                throw new BadDataException(sprintf('Hash mismatch for part %d', $part->part));
            }

            rewind($fh);

            yield $fh;

            $partNo = $part->part;
        }
    }

    /**
     * @inheritDoc
     */
    public function multipartFinalize(FileObject $object, string $token, FilePart ...$parts): PromiseInterface
    {
        return Promise::async(function () use ($object, $token, $parts): void {
            $this->write($object->key, ...$this->readParts($object, $token, ...$parts));
            $this->multipartCleanup($token);
        });
    }

    /**
     * @inheritDoc
     */
    public function multipartAbort(FileObject $object, string $token): PromiseInterface
    {
        return Promise::async(fn () => $this->multipartCleanup($token));
    }

    /**
     * Cleanup all uploaded parts for a multipart upload.
     *
     * @param string $token Multipart upload token.
     * @return void
     */
    protected function multipartCleanup(string $token): void
    {
        Filesystem::rmDir(Path::join($this->multipartRoot, $token));
    }
}

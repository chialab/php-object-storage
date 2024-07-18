<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage\Test\TestCase;

use Aws\Command;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Chialab\ObjectStorage\Exception\ObjectNotFoundException;
use Chialab\ObjectStorage\Exception\StorageException;
use Chialab\ObjectStorage\FileObject;
use Chialab\ObjectStorage\FilePart;
use Chialab\ObjectStorage\S3Adapter;
use Chialab\ObjectStorage\Utils\Promise;
use Chialab\ObjectStorage\Utils\Stream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream as PsrStream;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * {@see \Chialab\ObjectStorage\S3Adapter} Test Case
 */
#[CoversClass(S3Adapter::class)]
#[UsesClass(FileObject::class)]
#[UsesClass(FilePart::class)]
#[UsesClass(Promise::class)]
#[UsesClass(Stream::class)]
class S3AdapterTest extends TestCase
{
    /**
     * Factory for S3 clients.
     *
     * @param callable|null $handler Handler function, for mocking responses.
     * @return \Aws\S3\S3Client
     */
    protected static function s3ClientFactory(?callable $handler = null): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => 'eu-south-1',
            'credentials' => [
                'key' => 'AKIAEXAMPLE',
                'secret' => 'example',
            ],
            'handler' => $handler,
        ]);
    }

    /**
     * Test {@see S3Adapter::url()} method.
     *
     * @return void
     */
    public function testUrl(): void
    {
        $cases = [
            ['https://example-bucket.s3.eu-south-1.amazonaws.com/prefix/read-me-tenderly.txt', null, 'example-bucket', 'prefix/', 'read-me-tenderly.txt'],
            ['https://example.xyz/profix/pro-players-csgo-major.txt', 'https://example.xyz', 'another-bucket', 'profix/', 'pro-players-csgo-major.txt'],
        ];

        foreach ($cases as [$expected, $baseUrl, $bucket, $prefix, $file]) {
            $client = static::s3ClientFactory();
            $adapter = new S3Adapter($client, $bucket, $prefix, $baseUrl);
            $url = $adapter->url($file);

            static::assertEquals($expected, $url);
        }
    }

    /**
     * Test {@see S3Adapter::has()} method.
     *
     * @return void
     */
    public function testHas(): void
    {
        $cases = [
            [true, 'my-file.png'],
            [false, 'missing.pdf'],
        ];

        foreach ($cases as [$expected, $key]) {
            $invocations = [];
            $client = static::s3ClientFactory(function (Command $command) use (&$invocations): Result {
                $name = $command->getName();
                $invocations[] = $name;
                if ($name === 'HeadObject') {
                    static::assertSame('example-bucket', $command['Bucket']);

                    return match ($command['Key']) {
                        'prefix/my-file.png' => new Result(['ContentLength' => 1]),
                        'prefix/missing.pdf' => throw new S3Exception('NoSuchKey', $command, [
                            'code' => 'NoSuchKey',
                            'response' => new Response(404),
                        ]),
                        default => throw new InvalidArgumentException(sprintf('Unexpected Key: %s', $command['Key'])),
                    };
                }

                throw new InvalidArgumentException(sprintf('Unexpected command: %s', $name));
            });

            $adapter = new S3Adapter($client, 'example-bucket', 'prefix/');

            $actual = $adapter->has($key)->wait();

            static::assertSame($expected, $actual);
            static::assertSame(['HeadObject'], $invocations);
        }
    }

    /**
     * Test {@see S3Adapter::has()} method with an unauthorized error.
     *
     * @return void
     */
    public function testHasUnauthorized(): void
    {
        $this->expectExceptionObject(new StorageException('Cannot check object existence: unauthorized.php'));

        $invocations = [];
        $client = static::s3ClientFactory(function (Command $command) use (&$invocations): Result {
            $name = $command->getName();
            $invocations[] = $name;
            if ($name === 'HeadObject') {
                static::assertSame('example-bucket', $command['Bucket']);

                match ($command['Key']) {
                    'prefix/unauthorized.php' => throw new S3Exception('AccessDenied', $command, [
                        'code' => 'AccessDenied',
                        'response' => new Response(403),
                    ]),
                    default => throw new InvalidArgumentException(sprintf('Unexpected Key: %s', $command['Key'])),
                };
            }

            throw new InvalidArgumentException(sprintf('Unexpected command: %s', $name));
        });

        $adapter = new S3Adapter($client, 'example-bucket', 'prefix/');

        try {
            $adapter->has('unauthorized.php')->wait();
        } finally {
            static::assertSame(['HeadObject'], $invocations);
        }
    }

    /**
     * Test {@see S3Adapter::get()} method.
     *
     * @return void
     */
    public function testGet(): void
    {
        $invocations = [];
        $client = static::s3ClientFactory(function (Command $command) use (&$invocations): Result {
            $name = $command->getName();
            $invocations[] = $name;
            if ($name === 'GetObject') {
                static::assertSame('example-bucket', $command['Bucket']);

                return match ($command['Key']) {
                    'prefix/hello-world.txt' => new Result([
                        'ContentLength' => 12,
                        'ContentType' => 'text/plain',
                        'Body' => Stream::fromString('hello world!'),
                    ]),
                    default => throw new InvalidArgumentException(sprintf('Unexpected Key: %s', $command['Key'])),
                };
            }

            throw new InvalidArgumentException(sprintf('Unexpected command: %s', $name));
        });

        $adapter = new S3Adapter($client, 'example-bucket', 'prefix/');

        $object = $adapter->get('hello-world.txt')->wait();

        static::assertSame('hello-world.txt', $object->key);
        static::assertSame('hello world!', $object->data?->getContents());
        static::assertSame('text/plain', $object->getContentType());
        static::assertSame(['GetObject'], $invocations);
    }

    /**
     * Test {@see S3Adapter::get()} method with an object that does not exist.
     *
     * @return void
     */
    public function testGetNotFound(): void
    {
        $this->expectExceptionObject(new ObjectNotFoundException('Object not found: missing.pdf'));

        $invocations = [];
        $client = static::s3ClientFactory(function (Command $command) use (&$invocations): Result {
            $name = $command->getName();
            $invocations[] = $name;
            if ($name === 'GetObject') {
                static::assertSame('example-bucket', $command['Bucket']);

                match ($command['Key']) {
                    'prefix/missing.pdf' => throw new S3Exception('NoSuchKey', $command, [
                        'code' => 'NoSuchKey',
                        'response' => new Response(404),
                    ]),
                    default => throw new InvalidArgumentException(sprintf('Unexpected Key: %s', $command['Key'])),
                };
            }

            throw new InvalidArgumentException(sprintf('Unexpected command: %s', $name));
        });

        $adapter = new S3Adapter($client, 'example-bucket', 'prefix/');

        try {
            $adapter->get('missing.pdf')->wait();
        } finally {
            static::assertSame(['GetObject'], $invocations);
        }
    }

    /**
     * Test {@see S3Adapter::get()} method with an unauthorized error.
     *
     * @return void
     */
    public function testGetUnauthorized(): void
    {
        $this->expectExceptionObject(new StorageException('Cannot download object: unauthorized.php'));

        $invocations = [];
        $client = static::s3ClientFactory(function (Command $command) use (&$invocations): Result {
            $name = $command->getName();
            $invocations[] = $name;
            if ($name === 'GetObject') {
                static::assertSame('example-bucket', $command['Bucket']);

                match ($command['Key']) {
                    'prefix/unauthorized.php' => throw new S3Exception('AccessDenied', $command, [
                        'code' => 'AccessDenied',
                        'response' => new Response(403),
                    ]),
                    default => throw new InvalidArgumentException(sprintf('Unexpected Key: %s', $command['Key'])),
                };
            }

            throw new InvalidArgumentException(sprintf('Unexpected command: %s', $name));
        });

        $adapter = new S3Adapter($client, 'example-bucket', 'prefix/');

        try {
            $adapter->get('unauthorized.php')->wait();
        } finally {
            static::assertSame(['GetObject'], $invocations);
        }
    }

    /**
     * Test {@see S3Adapter::put()} method.
     *
     * @return void
     */
    public function testPut(): void
    {
        $invocations = [];
        $client = static::s3ClientFactory(function (Command $command) use (&$invocations): Result {
            $name = $command->getName();
            $invocations[] = $name;
            if ($name === 'PutObject') {
                static::assertSame('example-bucket', $command['Bucket']);

                if ($command['Key'] === 'prefix/hello-world.txt') {
                    $body = $command['Body'];
                    static::assertIsResource($body);
                    static::assertSame('hello world!', stream_get_contents($body));
                    static::assertSame('text/plain', $command['ContentType']);

                    return new Result([]);
                }

                throw new InvalidArgumentException(sprintf('Unexpected Key: %s', $command['Key']));
            }

            throw new InvalidArgumentException(sprintf('Unexpected command: %s', $name));
        });

        $adapter = new S3Adapter($client, 'example-bucket', 'prefix/');

        $adapter->put(new FileObject('hello-world.txt', new PsrStream(Stream::fromString('hello world!')), [
            'ContentType' => 'text/plain',
        ]))->wait();

        static::assertSame(['PutObject'], $invocations);
    }
}

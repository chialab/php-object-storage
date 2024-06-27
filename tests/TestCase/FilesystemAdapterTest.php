<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage\Test\TestCase;

use Chialab\ObjectStorage\Exception\BadDataException;
use Chialab\ObjectStorage\Exception\ObjectNotFoundException;
use Chialab\ObjectStorage\Exception\StorageException;
use Chialab\ObjectStorage\FileObject;
use Chialab\ObjectStorage\FilePart;
use Chialab\ObjectStorage\FilesystemAdapter;
use Chialab\ObjectStorage\Utils\Filesystem;
use Chialab\ObjectStorage\Utils\Stream;
use GuzzleHttp\Psr7\Stream as PsrStream;
use PHPUnit\Framework\TestCase;

/**
 * {@see FilesystemAdapter} Test Case
 *
 * @coversDefaultClass \Chialab\ObjectStorage\FilesystemAdapter
 * @uses \Chialab\ObjectStorage\Utils\Filesystem
 * @uses \Chialab\ObjectStorage\Utils\Path
 * @uses \Chialab\ObjectStorage\Utils\Promise
 * @uses \Chialab\ObjectStorage\Utils\Stream
 * @uses \Chialab\ObjectStorage\FileObject
 * @uses \Chialab\ObjectStorage\FilePart
 * @uses \Chialab\ObjectStorage\FilesystemAdapter::__construct()
 */
class FilesystemAdapterTest extends TestCase
{
    use FilesystemUtilsTrait;

    /**
     * Unique temporary directory for the test case.
     *
     * @var string
     */
    protected string $tmp;

    /**
     * Adapter test subject.
     *
     * @var \Chialab\ObjectStorage\FilesystemAdapter
     */
    protected FilesystemAdapter $adapter;

    /**
     * @inheritDoc
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->tmp = static::tempDir();
        $root = $this->tmp . 'files';
        $multipartRoot = $this->tmp . 'parts';
        Filesystem::mkDir($root . DIRECTORY_SEPARATOR . 'foo', 0700);
        Filesystem::mkDir($multipartRoot, 0700);
        file_put_contents($root . DIRECTORY_SEPARATOR . 'example.txt', 'hello world');

        $this->adapter = new FilesystemAdapter($root, $multipartRoot, 'https://static.example.com/');
    }

    /**
     * @inheritDoc
     */
    public function tearDown(): void
    {
        unset($this->adapter);
        Filesystem::rmDir($this->tmp);

        parent::tearDown();
    }

    /**
     * Test {@see FilesystemAdapter} constructor.
     *
     * @return void
     * @covers ::__construct()
     */
    public function testConstruct(): void
    {
        $adapter = new class ($this->tmp . 'files', $this->tmp . 'parts', 'https://static.example.com/', 011007) extends FilesystemAdapter {
            public function getUmask(): int
            {
                return $this->umask;
            }
        };

        static::assertSame(0007, $adapter->getUmask());
    }

    /**
     * Test {@see FilesystemAdapter::url()} method.
     *
     * @param string $expected Expected result.
     * @param string $key Key to check.
     * @return void
     * @covers ::url()
     * @testWith ["https://static.example.com/example.txt", "example.txt"]
     *           ["https://static.example.com/foo", "foo"]
     *           ["https://static.example.com/bar", "bar"]
     */
    public function testUrl(string $expected, string $key): void
    {
        $actual = $this->adapter->url($key);

        static::assertSame($expected, $actual);
    }

    /**
     * Test {@see FilesystemAdapter::has()} method.
     *
     * @param bool $expected Expected result.
     * @param string $key Key to check.
     * @return void
     * @covers ::has()
     * @testWith [true, "example.txt"]
     *           [false, "foo"]
     *           [false, "bar"]
     */
    public function testHas(bool $expected, string $key): void
    {
        $actual = $this->adapter->has($key)->wait();

        static::assertSame($expected, $actual);
    }

    /**
     * Test {@see FilesystemAdapter::get()} method.
     *
     * @param string|false $expectedData Expected content data.
     * @param string $expectedContentData Expected content type.
     * @param string $key Key to retrieve.
     * @return void
     * @covers ::get()
     * @testWith ["hello world", "text/plain", "example.txt"]
     *           [false, "application/octet-stream", "foo"]
     *           [false, "application/octet-stream", "bar"]
     */
    public function testGet(string|false $expectedData, string $expectedContentData, string $key): void
    {
        if ($expectedData === false) {
            $this->expectExceptionObject(new ObjectNotFoundException('Object not found: ' . $key));
        }

        /** @var FileObject $object */
        $object = $this->adapter->get($key)->wait();

        static::assertInstanceOf(FileObject::class, $object);
        static::assertSame($key, $object->key);
        static::assertSame($expectedData, (string)$object->data);
        static::assertSame($expectedContentData, $object->getContentType());
    }

    /**
     * Test {@see FilesystemAdapter::put()} method.
     *
     * @return void
     * @covers ::put()
     * @covers ::write()
     */
    public function testPut(): void
    {
        $obj = new FileObject('my-key', new PsrStream(Stream::fromString('my content')));
        $path = $this->tmp . 'files' . DIRECTORY_SEPARATOR . 'my-key';

        static::assertFileDoesNotExist($path);

        $this->adapter->put($obj)->wait();

        static::assertFileExists($path);
        static::assertSame(0600, fileperms($path) & 0777);
        static::assertSame('my content', file_get_contents($path));
    }

    /**
     * Test {@see FilesystemAdapter::put()} method with a file that should be overwritten.
     *
     * @return void
     * @covers ::put()
     * @covers ::write()
     */
    public function testPutOverwrite(): void
    {
        $obj = new FileObject('example.txt', new PsrStream(Stream::fromString('my content')));
        $path = $this->tmp . 'files' . DIRECTORY_SEPARATOR . 'example.txt';

        static::assertFileExists($path);
        static::assertNotSame('my content', file_get_contents($path));

        $this->adapter->put($obj)->wait();

        static::assertFileExists($path);
        static::assertSame(0600, fileperms($path) & 0777);
        static::assertSame('my content', file_get_contents($path));
    }

    /**
     * Test {@see FilesystemAdapter::put()} method with a {@see FileObject} whose stream had already been detached.
     *
     * @return void
     * @covers ::put()
     */
    public function testPutDetached(): void
    {
        $this->expectExceptionObject(new BadDataException('Missing object data'));

        $obj = new FileObject('my-key', new PsrStream(Stream::fromString('my content')));
        $obj->data?->detach();
        $path = $this->tmp . 'files' . DIRECTORY_SEPARATOR . 'my-key';

        static::assertFileDoesNotExist($path);

        try {
            $this->adapter->put($obj)->wait();
        } finally {
            static::assertFileDoesNotExist($path);
        }
    }

    /**
     * Test {@see FilesystemAdapter::delete()} method.
     *
     * @param bool $expected Expected result.
     * @param string $key Key to delete.
     * @return void
     * @covers ::delete()
     * @testWith [true, "example.txt"]
     *           [false, "foo"]
     *           [true, "bar"]
     */
    public function testDelete(bool $expected, string $key): void
    {
        if ($expected !== true) {
            $this->expectExceptionObject(new StorageException('Cannot delete ' . $key));
        }

        $this->adapter->delete($key)->wait();

        static::assertFileDoesNotExist($this->tmp . 'files' . DIRECTORY_SEPARATOR . $key);
    }

    /**
     * Test {@see FilesystemAdapter::multipartInit()} method.
     *
     * @return void
     * @covers ::multipartInit()
     * @covers ::multipartPath()
     * @covers ::hashAlgorithm()
     */
    public function testMultipartInit(): void
    {
        $token = $this->adapter->multipartInit(new FileObject('my-new-object.txt', null))->wait();

        static::assertIsString($token);
        static::assertNotEmpty($token);
        static::assertDirectoryExists($this->tmp . 'parts' . DIRECTORY_SEPARATOR . $token . DIRECTORY_SEPARATOR . hash('sha256', 'my-new-object.txt'));
    }

    /**
     * Test {@see FilesystemAdapter::multipartUpload()} method.
     *
     * @return void
     * @covers ::multipartUpload()
     * @covers ::multipartPath()
     * @covers ::hashAlgorithm()
     * @covers ::partFilename()
     */
    public function testMultipartUpload(): void
    {
        $path = $this->tmp . 'parts' . DIRECTORY_SEPARATOR . 'EXAMPLE-TOKEN' . DIRECTORY_SEPARATOR . hash('sha256', 'my-new-object.txt');
        Filesystem::mkDir($path, 0700);

        $object = new FileObject('my-new-object.txt', null);
        $stream = Stream::fromString('my data');
        $part = new FilePart(42, new PsrStream($stream));
        $hash = $this->adapter->multipartUpload($object, 'EXAMPLE-TOKEN', $part)->wait();

        static::assertIsString($hash);
        static::assertSame(hash('sha256', 'my data'), $hash);
        static::assertIsClosedResource($stream, 'Original stream has not been freed');
        static::assertFileExists($path . DIRECTORY_SEPARATOR . 'part00041');
        static::assertSame('my data', file_get_contents($path . DIRECTORY_SEPARATOR . 'part00041'));
    }

    /**
     * Test {@see FilesystemAdapter::multipartUpload()} method without part data.
     *
     * @return void
     * @covers ::multipartUpload()
     * @covers ::multipartPath()
     * @covers ::hashAlgorithm()
     * @covers ::partFilename()
     */
    public function testMultipartUploadMissingData(): void
    {
        Filesystem::mkDir($this->tmp . 'parts' . DIRECTORY_SEPARATOR . 'EXAMPLE-TOKEN' . DIRECTORY_SEPARATOR . hash('sha256', 'my-new-object.txt'), 0700);

        $this->expectExceptionObject(new BadDataException('Missing part data'));

        $object = new FileObject('my-new-object.txt', null);
        $part = new FilePart(42, null);
        $this->adapter->multipartUpload($object, 'EXAMPLE-TOKEN', $part)->wait();
    }

    /**
     * Test {@see FilesystemAdapter::multipartUpload()} method with an invalid multipart upload token.
     *
     * @return void
     * @covers ::multipartUpload()
     * @covers ::multipartPath()
     * @covers ::hashAlgorithm()
     * @covers ::partFilename()
     */
    public function testMultipartUploadNotInitialized(): void
    {
        $this->expectExceptionObject(new BadDataException('Multipart upload not initialized: EXAMPLE-TOKEN'));

        static::assertDirectoryDoesNotExist($this->tmp . 'parts' . DIRECTORY_SEPARATOR . 'EXAMPLE-TOKEN' . DIRECTORY_SEPARATOR . hash('sha256', 'my-new-object.txt'));
        $object = new FileObject('my-new-object.txt', null);
        $part = new FilePart(42, null);
        $this->adapter->multipartUpload($object, 'EXAMPLE-TOKEN', $part)->wait();
    }

    /**
     * Test {@see FilesystemAdapter::multipartFinalize()} method.
     *
     * @return void
     * @covers ::multipartFinalize()
     * @covers ::readParts()
     * @covers ::write()
     * @covers ::multipartCleanup()
     * @covers ::multipartPath()
     * @covers ::hashAlgorithm()
     * @covers ::partFilename()
     */
    public function testMultipartFinalize(): void
    {
        $path = $this->tmp . 'parts' . DIRECTORY_SEPARATOR . 'EXAMPLE-TOKEN' . DIRECTORY_SEPARATOR . hash('sha256', 'my-new-object.txt');
        Filesystem::mkDir($path, 0700);
        file_put_contents($path . DIRECTORY_SEPARATOR . 'part00000', 'hello ');
        file_put_contents($path . DIRECTORY_SEPARATOR . 'part00041', 'world!');

        $object = new FileObject('my-new-object.txt', null);
        $this->adapter
            ->multipartFinalize(
                $object,
                'EXAMPLE-TOKEN',
                new FilePart(1, null, hash('sha256', 'hello ')),
                new FilePart(42, null, hash('sha256', 'world!')),
            )
            ->wait();

        static::assertDirectoryDoesNotExist($this->tmp . 'parts' . DIRECTORY_SEPARATOR . 'EXAMPLE-TOKEN');
        static::assertFileExists($this->tmp . 'files' . DIRECTORY_SEPARATOR . 'my-new-object.txt');
        static::assertSame('hello world!', file_get_contents($this->tmp . 'files' . DIRECTORY_SEPARATOR . 'my-new-object.txt'));
    }

    /**
     * Test {@see FilesystemAdapter::multipartFinalize()} method with parts supplied in the wrong order.
     *
     * @return void
     * @covers ::multipartFinalize()
     * @covers ::readParts()
     * @covers ::multipartPath()
     * @covers ::hashAlgorithm()
     * @covers ::partFilename()
     */
    public function testMultipartFinalizeUnsortedParts(): void
    {
        $this->expectExceptionObject(new BadDataException('Parts must be sorted monotonically'));

        $path = $this->tmp . 'parts' . DIRECTORY_SEPARATOR . 'EXAMPLE-TOKEN' . DIRECTORY_SEPARATOR . hash('sha256', 'my-new-object.txt');
        Filesystem::mkDir($path, 0700);
        file_put_contents($path . DIRECTORY_SEPARATOR . 'part00000', 'hello ');
        file_put_contents($path . DIRECTORY_SEPARATOR . 'part00041', 'world!');
        $object = new FileObject('my-new-object.txt', null);

        try {
            $this->adapter
                ->multipartFinalize(
                    $object,
                    'EXAMPLE-TOKEN',
                    new FilePart(42, null, hash('sha256', 'world!')),
                    new FilePart(1, null, hash('sha256', 'hello ')),
                )
                ->wait();
        } finally {
            static::assertFileDoesNotExist($this->tmp . 'files' . DIRECTORY_SEPARATOR . 'my-new-object.txt');

            static::assertDirectoryExists($path);
            static::assertFileExists($path . DIRECTORY_SEPARATOR . 'part00000');
            static::assertSame('hello ', file_get_contents($path . DIRECTORY_SEPARATOR . 'part00000'));
            static::assertFileExists($path . DIRECTORY_SEPARATOR . 'part00041');
            static::assertSame('world!', file_get_contents($path . DIRECTORY_SEPARATOR . 'part00041'));
        }
    }

    /**
     * Test {@see FilesystemAdapter::multipartFinalize()} method with a part for which the hash does not match.
     *
     * @return void
     * @covers ::multipartFinalize()
     * @covers ::readParts()
     * @covers ::multipartPath()
     * @covers ::hashAlgorithm()
     * @covers ::partFilename()
     */
    public function testMultipartFinalizeHashMismatch(): void
    {
        $this->expectExceptionObject(new BadDataException('Hash mismatch for part 42'));

        $path = $this->tmp . 'parts' . DIRECTORY_SEPARATOR . 'EXAMPLE-TOKEN' . DIRECTORY_SEPARATOR . hash('sha256', 'my-new-object.txt');
        Filesystem::mkDir($path, 0700);
        file_put_contents($path . DIRECTORY_SEPARATOR . 'part00000', 'hello ');
        file_put_contents($path . DIRECTORY_SEPARATOR . 'part00041', 'world!');
        $object = new FileObject('my-new-object.txt', null);

        try {
            $this->adapter
                ->multipartFinalize(
                    $object,
                    'EXAMPLE-TOKEN',
                    new FilePart(1, null, hash('sha256', 'hello ')),
                    new FilePart(42, null, hash('sha256', 'OLD_DATA_SUPPLIED')),
                )
                ->wait();
        } finally {
            static::assertFileDoesNotExist($this->tmp . 'files' . DIRECTORY_SEPARATOR . 'my-new-object.txt');

            static::assertDirectoryExists($path);
            static::assertFileExists($path . DIRECTORY_SEPARATOR . 'part00000');
            static::assertSame('hello ', file_get_contents($path . DIRECTORY_SEPARATOR . 'part00000'));
            static::assertFileExists($path . DIRECTORY_SEPARATOR . 'part00041');
            static::assertSame('world!', file_get_contents($path . DIRECTORY_SEPARATOR . 'part00041'));
        }
    }

    /**
     * Test {@see FilesystemAdapter::multipartFinalize()} method with a part that had not been uploaded.
     *
     * @return void
     * @covers ::multipartFinalize()
     * @covers ::readParts()
     * @covers ::multipartPath()
     * @covers ::hashAlgorithm()
     * @covers ::partFilename()
     */
    public function testMultipartFinalizePartNotUploaded(): void
    {
        $this->expectExceptionObject(new BadDataException('Part not uploaded: 42'));

        $path = $this->tmp . 'parts' . DIRECTORY_SEPARATOR . 'EXAMPLE-TOKEN' . DIRECTORY_SEPARATOR . hash('sha256', 'my-new-object.txt');
        Filesystem::mkDir($path, 0700);
        file_put_contents($path . DIRECTORY_SEPARATOR . 'part00000', 'hello world!');

        static::assertFileDoesNotExist($path . DIRECTORY_SEPARATOR . 'part00041');
        $object = new FileObject('my-new-object.txt', null);

        try {
            $this->adapter
                ->multipartFinalize(
                    $object,
                    'EXAMPLE-TOKEN',
                    new FilePart(1, null, hash('sha256', 'hello world!')),
                    new FilePart(42, null, hash('sha256', 'some data that I never uploaded')),
                )
                ->wait();
        } finally {
            static::assertFileDoesNotExist($this->tmp . 'files' . DIRECTORY_SEPARATOR . 'my-new-object.txt');

            static::assertDirectoryExists($path);
            static::assertFileExists($path . DIRECTORY_SEPARATOR . 'part00000');
            static::assertSame('hello world!', file_get_contents($path . DIRECTORY_SEPARATOR . 'part00000'));
            static::assertFileDoesNotExist($path . DIRECTORY_SEPARATOR . 'part00041');
        }
    }

    /**
     * Test {@see FilesystemAdapter::multipartFinalize()} method with an invalid multipart upload token.
     *
     * @return void
     * @covers ::multipartFinalize()
     * @covers ::readParts()
     * @covers ::multipartPath()
     * @covers ::hashAlgorithm()
     * @covers ::partFilename()
     */
    public function testMultipartFinalizeNotInitialized(): void
    {
        $this->expectExceptionObject(new BadDataException('Multipart upload not initialized: EXAMPLE-TOKEN'));

        static::assertDirectoryDoesNotExist($this->tmp . 'parts' . DIRECTORY_SEPARATOR . 'EXAMPLE-TOKEN' . DIRECTORY_SEPARATOR . hash('sha256', 'my-new-object.txt'));
        $object = new FileObject('my-new-object.txt', null);

        try {
            $this->adapter
                ->multipartFinalize(
                    $object,
                    'EXAMPLE-TOKEN',
                    new FilePart(42, null, hash('sha256', 'hello world!')),
                )
                ->wait();
        } finally {
            static::assertFileDoesNotExist($this->tmp . 'files' . DIRECTORY_SEPARATOR . 'my-new-object.txt');
        }
    }

    /**
     * Test {@see FilesystemAdapter::multipartAbort()} method.
     *
     * @return void
     * @covers ::multipartAbort()
     * @covers ::multipartCleanup()
     */
    public function testMultipartAbort(): void
    {
        $path = $this->tmp . 'parts' . DIRECTORY_SEPARATOR . 'EXAMPLE-TOKEN' . DIRECTORY_SEPARATOR . hash('sha256', 'my-new-object.txt');
        Filesystem::mkDir($path, 0700);
        file_put_contents($path . DIRECTORY_SEPARATOR . 'part00000', 'hello ');
        file_put_contents($path . DIRECTORY_SEPARATOR . 'part00041', 'world!');

        $object = new FileObject('my-new-object.txt', null);
        $this->adapter->multipartAbort($object, 'EXAMPLE-TOKEN')->wait();

        static::assertDirectoryDoesNotExist($this->tmp . 'parts' . DIRECTORY_SEPARATOR . 'EXAMPLE-TOKEN');
        static::assertFileDoesNotExist($this->tmp . 'files' . DIRECTORY_SEPARATOR . 'my-new-object.txt');
    }

    /**
     * Test {@see FilesystemAdapter::multipartAbort()} method with an invalid multipart upload token.
     *
     * @return void
     * @covers ::multipartAbort()
     * @covers ::multipartCleanup()
     */
    public function testMultipartAbortNotInitialized(): void
    {
        static::assertDirectoryDoesNotExist($this->tmp . 'parts' . DIRECTORY_SEPARATOR . 'EXAMPLE-TOKEN' . DIRECTORY_SEPARATOR . hash('sha256', 'my-new-object.txt'));

        $object = new FileObject('my-new-object.txt', null);
        $this->adapter->multipartAbort($object, 'EXAMPLE-TOKEN')->wait();

        static::assertDirectoryDoesNotExist($this->tmp . 'parts' . DIRECTORY_SEPARATOR . 'EXAMPLE-TOKEN');
        static::assertFileDoesNotExist($this->tmp . 'files' . DIRECTORY_SEPARATOR . 'my-new-object.txt');
    }
}

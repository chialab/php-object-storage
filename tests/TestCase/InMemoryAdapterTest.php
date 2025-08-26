<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage\Test\TestCase;

use Chialab\ObjectStorage\Exception\BadDataException;
use Chialab\ObjectStorage\Exception\ObjectNotFoundException;
use Chialab\ObjectStorage\FileObject;
use Chialab\ObjectStorage\FilePart;
use Chialab\ObjectStorage\InMemoryAdapter;
use Chialab\ObjectStorage\Utils\Promise;
use Chialab\ObjectStorage\Utils\Stream;
use GuzzleHttp\Psr7\Stream as PsrStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Constraint\Exception;
use PHPUnit\Framework\Constraint\ExceptionCode;
use PHPUnit\Framework\Constraint\ExceptionMessageIsOrContains;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * {@see \Chialab\ObjectStorage\InMemoryAdapter} Test Case
 */
#[CoversClass(InMemoryAdapter::class)]
#[UsesClass(Promise::class)]
#[UsesClass(Stream::class)]
#[UsesClass(FileObject::class)]
#[UsesClass(FilePart::class)]
final class InMemoryAdapterTest extends TestCase
{
    /**
     * Test subject.
     *
     * @var \Chialab\ObjectStorage\InMemoryAdapter
     */
    protected InMemoryAdapter $adapter;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = new InMemoryAdapter('https://static.example.com/');
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        unset($this->adapter);

        parent::tearDown();
    }

    /**
     * Assert that a callable throws an exception.
     *
     * @param \Throwable $expected Expected exception.
     * @param callable(): mixed $callback Function that is expected to throw.
     * @return void
     */
    protected static function assertThrows(Throwable $expected, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            static::assertThat($e, new Exception(get_class($expected)));
            static::assertThat($e, new ExceptionMessageIsOrContains($expected->getMessage()));
            static::assertThat($e->getCode(), new ExceptionCode($expected->getCode()));

            return;
        }

        static::assertThat(null, new Exception(get_class($expected)));
    }

    /**
     * Test {@see InMemoryAdapter::url()} method.
     *
     * @return void
     */
    public function testUrl(): void
    {
        $expected = 'https://static.example.com/my-file.txt';
        $actual = $this->adapter->url('my-file.txt');

        static::assertSame($expected, $actual);
    }

    /**
     * Test {@see InMemoryAdapter::has()}, {@see InMemoryAdapter::get()}, {@see InMemoryAdapter::put()}
     * and {@see InMemoryAdapter::delete()} methods.
     *
     * @return void
     */
    public function testCRUD(): void
    {
        static::assertFalse($this->adapter->has('example')->wait());
        static::assertThrows(
            new ObjectNotFoundException('Object not found: example'),
            fn() => $this->adapter->get('example')->wait(),
        );
        $this->adapter->delete('example')->wait(); // This should not throw any exception.

        static::assertThrows(
            new BadDataException('Missing object data'),
            fn() => $this->adapter->put(new FileObject('example', null))->wait(),
        );

        $object = new FileObject('example', new PsrStream(Stream::fromString('hello world!')), [
            'ContentType' => 'text/plain',
        ]);
        $this->adapter->put($object)->wait();

        static::assertTrue($this->adapter->has('example')->wait());
        $stored = $this->adapter->get('example')->wait();
        static::assertInstanceOf(FileObject::class, $stored);
        static::assertNotSame($object, $stored);
        static::assertSame('example', $stored->key);
        static::assertNotSame($object->data, $stored->data);
        static::assertSame('hello world!', $stored->data?->getContents());
        static::assertSame('text/plain', $stored->getContentType());

        $this->adapter->delete('example')->wait();
        static::assertFalse($this->adapter->has('example')->wait());
    }

    /**
     * Test {@see InMemoryAdapter::multipartInit()}, {@see InMemoryAdapter::multipartUpload()}
     * and {@see InMemoryAdapter::multipartFinalize()} with a successful flow.
     *
     * @return void
     */
    public function testMultipartUpload(): void
    {
        static::assertFalse($this->adapter->has('example')->wait());

        $object = new FileObject('example', null, [
            'ContentType' => 'text/plain',
        ]);
        $token = $this->adapter->multipartInit($object)->wait();
        static::assertIsString($token);
        static::assertNotEmpty($token);

        static::assertFalse($this->adapter->has('example')->wait());

        $part = new FilePart(1, new PsrStream(Stream::fromString('hello ')));
        $hash = $this->adapter->multipartUpload($object, $token, $part)->wait();
        static::assertSame(hash('sha256', 'hello '), $hash);
        $parts[] = new FilePart(1, null, $hash);

        $part = new FilePart(42, new PsrStream(Stream::fromString('world!')));
        $hash = $this->adapter->multipartUpload($object, $token, $part)->wait();
        static::assertIsString($hash);
        static::assertSame(hash('sha256', 'world!'), $hash);
        $parts[] = new FilePart(42, null, $hash);

        static::assertFalse($this->adapter->has('example')->wait());

        $this->adapter->multipartFinalize($object, $token, ...$parts)->wait();

        static::assertTrue($this->adapter->has('example')->wait());
        $actual = $this->adapter->get('example')->wait();
        static::assertInstanceOf(FileObject::class, $actual);
        static::assertSame('hello world!', $actual->data?->getContents());
        static::assertSame('text/plain', $actual->getContentType());
    }

    /**
     * Test {@see InMemoryAdapter::multipartUpload()} with an invalid token.
     *
     * @return void
     */
    public function testMultipartUploadNotInitialized(): void
    {
        $this->expectExceptionObject(new BadDataException('Multipart upload not initialized: EXAMPLE-TOKEN'));

        $object = new FileObject('example', null);
        $part = new FilePart(1, new PsrStream(Stream::fromString('hello ')));
        $this->adapter->multipartUpload($object, 'EXAMPLE-TOKEN', $part)->wait();
    }

    /**
     * Test {@see InMemoryAdapter::multipartFinalize()} with an invalid token.
     *
     * @return void
     */
    public function testMultipartFinalizeNotInitialized(): void
    {
        $this->expectExceptionObject(new BadDataException('Multipart upload not initialized: EXAMPLE-TOKEN'));

        $object = new FileObject('example', null);
        $parts[] = new FilePart(1, null, hash('sha256', 'hello '));
        $parts[] = new FilePart(42, null, hash('sha256', 'world!'));
        $this->adapter->multipartFinalize($object, 'EXAMPLE-TOKEN', ...$parts)->wait();
    }

    /**
     * Test {@see InMemoryAdapter::multipartFinalize()} with an invalid token.
     *
     * @return void
     */
    public function testMultipartFinalizeKeyMismatch(): void
    {
        $object = new FileObject('example', null);
        $token = $this->adapter->multipartInit($object)->wait();
        static::assertIsString($token);

        $part = new FilePart(1, new PsrStream(Stream::fromString('hello ')));
        $hash = $this->adapter->multipartUpload($object, $token, $part)->wait();
        static::assertIsString($hash);
        $parts[] = new FilePart(1, null, $hash);

        $part = new FilePart(42, new PsrStream(Stream::fromString('world!')));
        $hash = $this->adapter->multipartUpload($object, $token, $part)->wait();
        static::assertIsString($hash);
        $parts[] = new FilePart(42, null, $hash);

        $this->expectExceptionObject(new BadDataException('Multipart upload not initialized: ' . $token));
        $this->adapter->multipartFinalize(new FileObject('ANOTHER-KEY', null), $token, ...$parts)->wait();
    }

    /**
     * Test {@see InMemoryAdapter::multipartFinalize()} with parts in the incorrect order.
     *
     * @return void
     */
    public function testMultipartFinalizeUnsortedParts(): void
    {
        $object = new FileObject('example', null);
        $token = $this->adapter->multipartInit($object)->wait();
        static::assertIsString($token);

        $part = new FilePart(1, new PsrStream(Stream::fromString('hello ')));
        $hash = $this->adapter->multipartUpload($object, $token, $part)->wait();
        static::assertIsString($hash);
        $parts[] = new FilePart(1, null, $hash);

        $part = new FilePart(42, new PsrStream(Stream::fromString('world!')));
        $hash = $this->adapter->multipartUpload($object, $token, $part)->wait();
        static::assertIsString($hash);
        $parts[] = new FilePart(42, null, $hash);

        $this->expectExceptionObject(new BadDataException('Parts must be sorted monotonically'));
        $this->adapter->multipartFinalize($object, $token, ...array_reverse($parts))->wait();
    }

    /**
     * Test {@see InMemoryAdapter::multipartFinalize()} with a part whose hash does not match with the hash of the
     * latest uploaded part for its index.
     *
     * @return void
     */
    public function testMultipartFinalizeHashMismatch(): void
    {
        $object = new FileObject('example', null);
        $token = $this->adapter->multipartInit($object)->wait();
        static::assertIsString($token);

        $part = new FilePart(1, new PsrStream(Stream::fromString('hello ')));
        $hash = $this->adapter->multipartUpload($object, $token, $part)->wait();
        static::assertIsString($hash);
        $parts[] = new FilePart(1, null, $hash);

        $part = new FilePart(42, new PsrStream(Stream::fromString('world!')));
        $hash = $this->adapter->multipartUpload($object, $token, $part)->wait();
        static::assertIsString($hash);
        $parts[] = new FilePart(42, null, strrev($hash)); // Hash is reversed.

        $this->expectExceptionObject(new BadDataException('Hash mismatch for part 42'));
        $this->adapter->multipartFinalize($object, $token, ...$parts)->wait();
    }

    /**
     * Test {@see InMemoryAdapter::multipartAbort()} method.
     *
     * @return void
     */
    public function testMultipartAbort(): void
    {
        $object = new FileObject('example', null);
        $part = new FilePart(1, new PsrStream(Stream::fromString('hello ')));

        $token = $this->adapter->multipartInit($object)->wait();
        static::assertIsString($token);
        $this->adapter->multipartUpload($object, $token, $part)->wait();

        $this->adapter->multipartAbort($object, $token)->wait();

        // Assert that an aborted multipart upload cannot be resumed:
        static::assertThrows(
            new BadDataException('Multipart upload not initialized: ' . $token),
            fn() => $this->adapter->multipartUpload($object, $token, $part)->wait(),
        );
    }

    /**
     * Test {@see InMemoryAdapter::multipartAbort()} method with an invalid token.
     *
     * @return void
     */
    public function testMultipartAbortNotInitialized(): void
    {
        $token = 'EXAMPLE-TOKEN';
        $object = new FileObject('example', null);
        $part = new FilePart(1, new PsrStream(Stream::fromString('hello ')));

        $this->adapter->multipartAbort($object, $token)->wait();

        // Assert that an aborted multipart upload cannot be resumed:
        static::assertThrows(
            new BadDataException('Multipart upload not initialized: ' . $token),
            fn() => $this->adapter->multipartUpload($object, $token, $part)->wait(),
        );
    }
}

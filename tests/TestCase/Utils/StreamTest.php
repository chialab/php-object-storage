<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage\Test\TestCase\Utils;

use Chialab\ObjectStorage\Utils\Stream;
use Generator;
use GuzzleHttp\Psr7\Stream as PsrStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * {@see \Chialab\ObjectStorage\Utils\Stream} Test Case
 */
#[CoversClass(Stream::class)]
class StreamTest extends TestCase
{
    /**
     * Test {@see Stream::newTemporaryStream()} method.
     *
     * @return void
     */
    public function testNewTemporaryStream(): void
    {
        $tmp = Stream::newTemporaryStream();

        static::assertIsResource($tmp);
        static::assertIsNotClosedResource($tmp);
        static::assertSame(0, ftell($tmp));
    }

    /**
     * Test {@see Stream::fromString()} method.
     *
     * @return void
     */
    public function testFromString(): void
    {
        $fh = Stream::fromString('hello world!');

        static::assertIsResource($fh);
        static::assertIsNotClosedResource($fh);
        static::assertSame(0, ftell($fh));
        static::assertSame('hello world!', stream_get_contents($fh));
    }

    /**
     * Test {@see Stream::streamCopyToStream()} method.
     *
     * @return void
     */
    public function testStreamCopyToStream(): void
    {
        $expected = 'hello world';

        $fh = fopen('php://memory', 'wb') ?: throw new RuntimeException('Cannot open temporary stream');
        fwrite($fh, 'hello world');
        rewind($fh);

        $copy = Stream::newTemporaryStream();
        Stream::streamCopyToStream($fh, $copy);
        rewind($copy);

        static::assertIsResource($copy);
        static::assertIsNotClosedResource($copy);
        static::assertNotSame($fh, $copy);
        static::assertSame(0, ftell($copy));
        static::assertSame($expected, stream_get_contents($copy));
        fclose($copy);

        static::assertIsNotClosedResource($fh, 'Original resource should not be closed');
        fclose($fh);
    }

    /**
     * Test {@see Stream::psrCopyToStream()} method.
     *
     * @return void
     * @throws \Random\RandomException
     */
    public function testPsrCopyToStream(): void
    {
        $original = new PsrStream(Stream::newTemporaryStream());
        for ($i = 0; $i < 12; $i++) { // Write approx. 12 kb of random data.
            $original->write(bin2hex(random_bytes(511)) . PHP_EOL);
        }
        $original->rewind();

        $copy = Stream::newTemporaryStream();
        Stream::psrCopyToStream($original, $copy);
        rewind($copy);
        $original->rewind();

        static::assertIsResource($copy);
        static::assertIsNotClosedResource($copy);
        static::assertSame(0, ftell($copy));
        static::assertSame($original->getContents(), stream_get_contents($copy));
    }

    /**
     * Test {@see Stream::close()} method.
     *
     * @return void
     */
    public function testClose(): void
    {
        $fh = fopen('php://memory', 'wb') ?: throw new RuntimeException('Cannot open temporary stream');
        static::assertIsResource($fh);

        Stream::close($fh);
        static::assertIsClosedResource($fh);
    }

    /**
     * Data provider for {@see StreamTest::testHash()} test case.
     *
     * @return \Generator<array{string, string, string}>
     * @throws \Random\RandomException
     */
    public static function hashProvider(): Generator
    {
        $algorithms = array_intersect(hash_algos(), ['sha1', 'sha256', 'sha512', 'md5', 'adler32', 'crc32']);
        foreach ($algorithms as $alg) {
            $strings = ['hello world', '', bin2hex(random_bytes(4))];
            foreach ($strings as $string) {
                yield sprintf('%s(%s)', $alg, $string) => [hash($alg, $string), $string, $alg];
            }
        }
    }

    /**
     * Test {@see Stream::hash()} method.
     *
     * @param string $expected Expected hash.
     * @param string $data Data to be hashed.
     * @param string $algorithm Algorithm to use.
     * @return void
     */
    #[DataProvider('hashProvider')]
    public function testHash(string $expected, string $data, string $algorithm): void
    {
        $fh = fopen('php://memory', 'wb') ?: throw new RuntimeException('Cannot open temporary stream');
        fwrite($fh, $data);
        rewind($fh);

        $actual = Stream::hash($fh, $algorithm);

        static::assertSame($expected, $actual);

        static::assertIsNotClosedResource($fh, 'Original resource should not be closed');
        fclose($fh);
    }
}

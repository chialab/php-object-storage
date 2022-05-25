<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage\Test\TestCase\Utils;

use Chialab\ObjectStorage\Utils\Stream;
use Generator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * {@see \Chialab\ObjectStorage\Utils\Stream} Test Case
 *
 * @coversDefaultClass \Chialab\ObjectStorage\Utils\Stream
 */
class StreamTest extends TestCase
{
    /**
     * Test {@see Stream::temporaryStreamCopy()} method.
     *
     * @return void
     * @covers ::temporaryStreamCopy()
     */
    public function testTemporaryStreamCopy(): void
    {
        $expected = 'hello world';

        $fh = fopen('php://memory', 'wb') ?: throw new RuntimeException('Cannot open temporary stream');
        fwrite($fh, 'hello world');
        rewind($fh);

        $copy = Stream::temporaryStreamCopy($fh);

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
     * Test {@see Stream::close()} method.
     *
     * @return void
     * @covers ::close()
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
     */
    public function hashProvider(): Generator
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
     * @dataProvider hashProvider()
     * @covers ::hash()
     */
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

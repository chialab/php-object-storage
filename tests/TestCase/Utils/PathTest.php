<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage\Test\TestCase\Utils;

use Chialab\ObjectStorage\Utils\Path;
use PHPUnit\Framework\TestCase;

/**
 * {@see \Chialab\ObjectStorage\Utils\Path} Test Case
 *
 * @coversDefaultClass \Chialab\ObjectStorage\Utils\Path
 */
class PathTest extends TestCase
{
    /**
     * Data provider for {@see PathTest::testSplit()} test case.
     *
     * @return array<string, array{string[], string}>
     */
    public function splitProvider(): array
    {
        return [
            '/dev/null' => [['', 'dev', 'null'], '/dev/null'],
            'foo/bar/baz/' => [['foo', 'bar', 'baz'], 'foo/bar/baz/'],
            './bar/baz/' => [['.', 'bar', 'baz'], './bar/baz/'],
            'just\\/a\\/regular\\/filename' => [['just\\/a\\/regular\\/filename'], 'just\\/a\\/regular\\/filename'],
            'foo' => [['foo'], 'foo'],
            '' => [[], ''],
            '\\/' => [['\\/'], '\\/'],
         ];
    }

    /**
     * Test {@see Path::split()} method.
     *
     * @param string[] $expected Expected result.
     * @param string $path Input path.
     * @return void
     * @dataProvider splitProvider()
     * @covers ::split()
     */
    public function testSplit(array $expected, string $path): void
    {
        $actual = Path::split($path);

        static::assertSame($expected, $actual);
    }

    /**
     * Data provider for {@see PathTest::testJoin()} test case.
     *
     * @return array<string, string[]>
     */
    public function joinProvider(): array
    {
        return [
            '' => [''],
            'foo' => ['foo', 'foo'],
            '/dev/null' => ['/dev/null', '/dev/null'],
            'foo/bar/baz' => ['foo/bar/baz', 'foo', 'bar', 'baz'],
            '/bin/true' => ['/bin/true', '/example', 'another/subfolder', '/bin', 'true'],
            '/foo/\\/bar' => ['/foo/\\/bar', '/bar', '/foo', '\\/bar'],
        ];
    }

    /**
     * Test {@see Path::join()} method.
     *
     * @param string $expected Expected result.
     * @param string ...$paths Input paths.
     * @return void
     * @dataProvider joinProvider()
     * @covers ::join()
     * @uses \Chialab\ObjectStorage\Utils\Path::split()
     */
    public function testJoin(string $expected, string ...$paths): void
    {
        $actual = Path::join(...$paths);

        static::assertSame($expected, $actual);
    }
}

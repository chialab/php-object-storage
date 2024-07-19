<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage\Test\TestCase\Utils;

use Chialab\ObjectStorage\Exception\StorageException;
use Chialab\ObjectStorage\Test\TestCase\FilesystemUtilsTrait;
use Chialab\ObjectStorage\Utils\Filesystem;
use Chialab\ObjectStorage\Utils\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;

/**
 * {@see \Chialab\ObjectStorage\Utils\Filesystem} Test Case
 */
#[CoversClass(Filesystem::class)]
#[UsesClass(Stream::class)]
class FilesystemTest extends TestCase
{
    use FilesystemUtilsTrait;

    /**
     * Unique temporary directory for the test case.
     *
     * @var string
     */
    protected string $tmp;

    /**
     * @inheritDoc
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->tmp = static::tempDir();
        file_put_contents($this->tmp . 'example.txt', 'hello world');
    }

    /**
     * @inheritDoc
     */
    public function tearDown(): void
    {
        Filesystem::rmDir($this->tmp);

        parent::tearDown();
    }

    /**
     * Test {@see Filesystem::chmod()} method.
     *
     * @return void
     */
    public function testChmod(): void
    {
        $path = $this->tmp . 'new-file.txt';
        static::assertFileDoesNotExist($path);

        Filesystem::chmod($path, 0600);

        static::assertFileExists($path);
        static::assertEmpty(file_get_contents($path));
        static::assertSame(0600, fileperms($path) & 0777);
    }

    /**
     * Test {@see Filesystem::chmod()} method with an existing file.
     *
     * @return void
     */
    public function testChmodExistingFile(): void
    {
        $path = $this->tmp . 'example.txt';
        static::assertFileExists($path);

        Filesystem::chmod($path, 0600);

        static::assertFileExists($path);
        static::assertSame('hello world', file_get_contents($path));
        static::assertSame(0600, fileperms($path) & 0777);
    }

    /**
     * Test {@see Filesystem::lockingRead()} method with a file without concurrent access.
     *
     * @return void
     */
    public function testLockingRead(): void
    {
        $expected = 'hello world';

        $fh = Filesystem::lockingRead($this->tmp . 'example.txt');
        $actual = fread($fh, 1024);

        static::assertSame($expected, $actual);

        fclose($fh);
    }

    /**
     * Test {@see Filesystem::lockingRead()} method with a file that does not exist.
     *
     * @return void
     */
    public function testLockingReadMissingFile(): void
    {
        $this->expectExceptionObject(new StorageException('File does not exist: ' . $this->tmp . 'this-file-does-not-exist.md'));

        Filesystem::lockingRead($this->tmp . 'this-file-does-not-exist.md');
    }

    /**
     * Test {@see Filesystem::lockingRead()} method with a file that does not exist.
     *
     * @return void
     */
    public function testLockingReadFileInUse(): void
    {
        $this->withFlock($this->tmp . 'example.txt', function (): void {
            $this->expectExceptionObject(new StorageException('Cannot acquire shared lock: ' . $this->tmp . 'example.txt'));

            Filesystem::lockingRead($this->tmp . 'example.txt');
        });
    }

    /**
     * Test {@see Filesystem::lockingWrite()} method with a file without concurrent access.
     *
     * @return void
     */
    public function testLockingWrite(): void
    {
        $expected = 'foo bar';

        $filename = $this->tmp . 'foos-and-bars.txt';
        static::assertFileDoesNotExist($filename);

        Filesystem::lockingWrite($filename, 0600, null, Stream::fromString('foo bar'));

        static::assertFileExists($filename);
        static::assertSame($expected, file_get_contents($filename));
    }

    /**
     * Test {@see Filesystem::lockingWrite()} method with a file without concurrent access, and a callback.
     *
     * @return void
     */
    public function testLockingWriteCallback(): void
    {
        $expected = 'foo bar';

        $filename = $this->tmp . 'foos-and-bars.txt';
        static::assertFileDoesNotExist($filename);

        $read = null;
        Filesystem::lockingWrite(
            $filename,
            0600,
            function ($fh) use (&$read): void {
                static::assertIsResource($fh);
                static::assertIsNotClosedResource($fh);
                static::assertTrue(rewind($fh), 'File handler could not be rewind');

                $read = fread($fh, 1024);
            },
            Stream::fromString('foo bar')
        );

        static::assertFileExists($filename);
        static::assertSame($expected, file_get_contents($filename));
        static::assertSame($expected, $read);
    }

    /**
     * Test {@see Filesystem::lockingWrite()} method with a file without concurrent access and multiple data sources.
     *
     * @return void
     */
    public function testLockingWriteMultipleDataSources(): void
    {
        $expected = "foo\nbar\nbaz\n";

        $filename = $this->tmp . 'foos-and-bars.txt';
        static::assertFileDoesNotExist($filename);

        /**
         * @param string ...$data Data.
         * @return iterable<resource>
         */
        $gen = function (string ...$data): iterable {
            foreach ($data as $datum) {
                yield Stream::fromString($datum);
            }
        };
        Filesystem::lockingWrite($filename, 0600, null, ...$gen("foo\n", "bar\n", "baz\n"));

        static::assertFileExists($filename);
        static::assertSame($expected, file_get_contents($filename));
    }

    /**
     * Test {@see Filesystem::lockingWrite()} method with a previously existing file without concurrent access.
     *
     * @return void
     */
    public function testLockingWriteExistingFile(): void
    {
        $expected = 'foo bar';

        $filename = $this->tmp . 'example.txt';
        static::assertFileExists($filename);
        static::assertNotSame($expected, file_get_contents($filename));

        Filesystem::lockingWrite($filename, 0600, null, Stream::fromString('foo bar'));

        static::assertFileExists($filename);
        static::assertSame($expected, file_get_contents($filename));
    }

    /**
     * Test {@see Filesystem::lockingWrite()} method with a file that does not exist.
     *
     * @return void
     */
    public function testLockingWriteFileInUse(): void
    {
        $this->withFlock($this->tmp . 'example.txt', function (): void {
            $this->expectExceptionObject(new StorageException('Cannot acquire exclusive lock: ' . $this->tmp . 'example.txt'));

            Filesystem::lockingWrite($this->tmp . 'example.txt', 0600, null, Stream::fromString('foo bar'));
        });
    }

    /**
     * Test {@see Filesystem::mkDir()} method.
     *
     * @return void
     */
    public function testMkDir(): void
    {
        $path = $this->tmp . 'new-dir' . DIRECTORY_SEPARATOR . 'sub-directory';

        static::assertDirectoryDoesNotExist($path);
        Filesystem::mkDir($path, 0700);
        static::assertDirectoryExists($path);
    }

    /**
     * Test {@see Filesystem::mkDir()} method with an existing directory.
     *
     * @return void
     */
    public function testMkDirExisting(): void
    {
        $path = $this->tmp . 'new-dir';
        mkdir($path, 0700) ?: throw new RuntimeException('Cannot create directory');

        static::assertDirectoryExists($path);
        Filesystem::mkDir($path, 0700);
        static::assertDirectoryExists($path);
    }

    /**
     * Test {@see Filesystem::recursiveLs()} method.
     *
     * @return void
     */
    public function testRecursiveLs(): void
    {
        mkdir(
            $this->tmp . 'foo' . DIRECTORY_SEPARATOR . 'bar' . DIRECTORY_SEPARATOR . 'baz',
            0700,
            true
        );
        file_put_contents(
            $this->tmp . 'foo' . DIRECTORY_SEPARATOR . 'bar' . DIRECTORY_SEPARATOR . 'example.txt',
            'hello world',
        );
        file_put_contents(
            $this->tmp . 'foo' . DIRECTORY_SEPARATOR . 'example.txt',
            'hello world',
        );

        $expected = [
            $this->tmp . 'foo' . DIRECTORY_SEPARATOR . 'example.txt',
            $this->tmp . 'foo' . DIRECTORY_SEPARATOR . 'bar' . DIRECTORY_SEPARATOR . 'example.txt',
            $this->tmp . 'foo' . DIRECTORY_SEPARATOR . 'bar' . DIRECTORY_SEPARATOR . 'baz',
            $this->tmp . 'foo' . DIRECTORY_SEPARATOR . 'bar',
            $this->tmp . 'foo',
        ];

        $actual = [...Filesystem::recursiveLs($this->tmp . 'foo')];
        $paths = array_keys($actual);

        static::assertEqualsCanonicalizing($expected, $paths);
        static::assertSame($this->tmp . 'foo', array_key_last($actual), 'Expected foo/ to be the last element');
        static::assertLessThan(
            array_search($this->tmp . 'foo' . DIRECTORY_SEPARATOR . 'bar', $paths),
            array_search($this->tmp . 'foo' . DIRECTORY_SEPARATOR . 'bar' . DIRECTORY_SEPARATOR . 'example.txt', $paths),
            'Expected foo/bar/example.txt to be listed before foo/bar/',
        );
        static::assertLessThan(
            array_search($this->tmp . 'foo' . DIRECTORY_SEPARATOR . 'bar', $paths),
            array_search($this->tmp . 'foo' . DIRECTORY_SEPARATOR . 'bar' . DIRECTORY_SEPARATOR . 'baz', $paths),
            'Expected foo/bar/baz/ to be listed before foo/bar/',
        );
        static::assertContainsOnlyInstancesOf(SplFileInfo::class, $actual);
    }

    /**
     * Test {@see Filesystem::rmDir()} method.
     *
     * @return void
     */
    public function testRmDir(): void
    {
        mkdir(
            $this->tmp . 'foo' . DIRECTORY_SEPARATOR . 'bar' . DIRECTORY_SEPARATOR . 'baz',
            0700,
            true
        );
        file_put_contents(
            $this->tmp . 'foo' . DIRECTORY_SEPARATOR . 'bar' . DIRECTORY_SEPARATOR . 'example.txt',
            'hello world',
        );
        file_put_contents(
            $this->tmp . 'foo' . DIRECTORY_SEPARATOR . 'example.txt',
            'hello world',
        );

        Filesystem::rmDir($this->tmp . 'foo');

        static::assertDirectoryDoesNotExist($this->tmp . 'foo');
    }

    /**
     * Test {@see Filesystem::rmDir()} method with a directory that does not exist.
     *
     * @return void
     */
    public function testRmDirNotExisting(): void
    {
        static::assertDirectoryDoesNotExist($this->tmp . 'foo');

        Filesystem::rmDir($this->tmp . 'foo');

        static::assertDirectoryDoesNotExist($this->tmp . 'foo');
    }
}

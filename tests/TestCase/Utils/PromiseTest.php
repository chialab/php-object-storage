<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage\Test\TestCase\Utils;

use Chialab\ObjectStorage\Utils\Promise;
use Exception;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use PHPUnit\Framework\TestCase;

/**
 * {@see \Chialab\ObjectStorage\Utils\Promise} Test Case
 *
 * @coversDefaultClass \Chialab\ObjectStorage\Utils\Promise
 */
class PromiseTest extends TestCase
{
    /**
     * Test {@see Promise::async()} method with a callback that returns a value.
     *
     * @return void
     * @covers ::async()
     */
    public function testAsyncResolve(): void
    {
        $expected = 'example';

        $invoked = 0;
        $actual = Promise::async(function () use (&$invoked): string {
            $invoked++;

            return 'example';
        })->wait();

        static::assertSame(1, $invoked, 'Callback should be invoked exactly once');
        static::assertSame($expected, $actual);
    }

    /**
     * Test {@see Promise::async()} method with a callback that returns a promise.
     *
     * @return void
     * @covers ::async()
     */
    public function testAsyncResolvePromise(): void
    {
        $expected = 'example';

        $invoked = 0;
        $actual = Promise::async(function () use (&$invoked): PromiseInterface {
            $invoked++;

            return new FulfilledPromise('example');
        })->wait();

        static::assertSame(1, $invoked, 'Callback should be invoked exactly once');
        static::assertSame($expected, $actual);
    }

    /**
     * Test {@see Promise::async()} method with a callback that throws a value.
     *
     * @return void
     * @covers ::async()
     */
    public function testAsyncReject(): void
    {
        $this->expectExceptionObject(new Exception('Test exception'));

        $invoked = 0;
        try {
            Promise::async(function () use (&$invoked): PromiseInterface {
                $invoked++;

                throw new Exception('Test exception');
            })->wait();
        } finally {
            static::assertSame(1, $invoked, 'Callback should be invoked exactly once');
        }
    }
}

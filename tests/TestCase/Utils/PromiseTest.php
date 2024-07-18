<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage\Test\TestCase\Utils;

use Chialab\ObjectStorage\Utils\Promise;
use Exception;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * {@see \Chialab\ObjectStorage\Utils\Promise} Test Case
 */
#[CoversClass(Promise::class)]
class PromiseTest extends TestCase
{
    /**
     * Test {@see Promise::async()} method with a callback that returns a value.
     *
     * @return void
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
     */
    public function testAsyncResolvePromise(): void
    {
        $expected = 'example';

        $invoked = 0;
        /** @var string $actual */
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

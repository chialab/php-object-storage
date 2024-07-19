<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage\Utils;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use Throwable;

/**
 * Promise-related utility methods.
 *
 * @internal
 */
class Promise
{
    /**
     * Private constructor to disable instantiating this class.
     *
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Wrap a callable in a promise.
     *
     * @param callable(): mixed $cb Callable.
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public static function async(callable $cb): PromiseInterface
    {
        try {
            $result = $cb();
            if ($result instanceof PromiseInterface) {
                return $result;
            }

            return new FulfilledPromise($result);
        } catch (Throwable $e) {
            return new RejectedPromise($e);
        }
    }
}

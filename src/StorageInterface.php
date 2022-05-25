<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage;

use GuzzleHttp\Promise\PromiseInterface;

/**
 * Base storage interface.
 */
interface StorageInterface
{
    /**
     * Get URL for an object.
     *
     * @param string $key Object key.
     * @return string
     */
    public function url(string $key): string;

    /**
     * Check if an object exists in the storage.
     *
     * @param string $key Object key.
     * @return \GuzzleHttp\Promise\PromiseInterface<bool>
     */
    public function has(string $key): PromiseInterface;

    /**
     * Get an object from the storage.
     *
     * @param string $key Object key.
     * @return \GuzzleHttp\Promise\PromiseInterface<\Chialab\ObjectStorage\FileObject>
     */
    public function get(string $key): PromiseInterface;

    /**
     * Put (insert or replace) an object to the object storage.
     *
     * @param \Chialab\ObjectStorage\FileObject $object Object key.
     * @return \GuzzleHttp\Promise\PromiseInterface<void>
     */
    public function put(FileObject $object): PromiseInterface;

    /**
     * Delete an object from the storage.
     *
     * @param string $key Object key.
     * @return \GuzzleHttp\Promise\PromiseInterface<void>
     */
    public function delete(string $key): PromiseInterface;
}

<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage;

use GuzzleHttp\Promise\PromiseInterface;

/**
 * Interface for storage adapters that supports multipart upload.
 */
interface MultipartUploadInterface extends StorageInterface
{
    /**
     * Initialize multipart upload session.
     *
     * @param \Chialab\ObjectStorage\FileObject $object Object that will be uploaded.
     * @return \GuzzleHttp\Promise\PromiseInterface Promise that resolves to the unique token for the upload.
     */
    public function multipartInit(FileObject $object): PromiseInterface;

    /**
     * Upload part for a multipart upload.
     *
     * @param \Chialab\ObjectStorage\FileObject $object Object that will be uploaded.
     * @param string $token Multipart upload token.
     * @param \Chialab\ObjectStorage\FilePart $part File part.
     * @return \GuzzleHttp\Promise\PromiseInterface Promise that resolves to a token for the part.
     */
    public function multipartUpload(FileObject $object, string $token, FilePart $part): PromiseInterface;

    /**
     * Finalize multipart upload.
     *
     * @param \Chialab\ObjectStorage\FileObject $object Object that will be uploaded.
     * @param string $token Multipart upload token.
     * @param \Chialab\ObjectStorage\FilePart ...$parts List of tokens returned by single parts uploads.
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function multipartFinalize(FileObject $object, string $token, FilePart ...$parts): PromiseInterface;

    /**
     * Abort a multipart upload.
     *
     * @param \Chialab\ObjectStorage\FileObject $object Object.
     * @param string $token Multipart upload token.
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function multipartAbort(FileObject $object, string $token): PromiseInterface;
}

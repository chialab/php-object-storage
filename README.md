# PHP Object Storage

This library provides a PHP implementation for using various object storage backends.

## Installation

You can install this library using [composer](https://getcomposer.org):
```shell
composer install chialab/object-storage
```

To use AWS S3 as a backend storage, the SDK is also needed:
```shell
composer install aws/aws-sdk-php
```

## Adapters

The plugin currently provides the following adapters.

You can create other adapters by implementing `MultipartUploadInterface`.

### `FilesystemAdapter`

This adapter uses the filesystem to store objects.

Takes an ordered array of arguments:
1. path to the root files folder
2. path to the temporary folder where multipart uploads are stored until finalization
3. base for object URLs from which the webserver serves the files
4. an optional umask for created files (defaults to octal `0077`)

### `S3Adapter`

This adapter uses an AWS S3 bucket to store objects.

Takes an ordered array of arguments:
1. an `Aws\S3\S3Client` instance
2. the name of the bucket
3. an optional key prefix to use for all files (defaults to empty)
4. an optional custom base for object URLs (defaults to empty)

### `InMemoryAdapter`

This adapter uses volatile memory to store objects.

Takes only one argument:
1. base for object URLs

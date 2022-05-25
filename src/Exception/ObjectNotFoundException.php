<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage\Exception;

/**
 * Exception thrown when an attempt is made to read an object that does not exist.
 */
class ObjectNotFoundException extends StorageException
{
}

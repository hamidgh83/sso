<?php

namespace App\Exception;

use Exception;

/**
 * Exception thrown when a request is done while no session is attached
 *
 */
class NotAttachedException extends Exception implements ExceptionInterface
{
}

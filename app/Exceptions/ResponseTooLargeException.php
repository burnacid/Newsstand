<?php

namespace App\Exceptions;

use RuntimeException;

class ResponseTooLargeException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Response is larger than the allowed maximum.');
    }
}

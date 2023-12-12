<?php

namespace IgniterLabs\SquarePOS\Exceptions;

use Exception;
use Square\Http\ApiResponse;
use Throwable;

class ClientException extends Exception
{
    public function __construct($message = '', $code = 0, ?Throwable $previous = null)
    {
        if ($message instanceof ApiResponse) {
            $message = implode(PHP_EOL, array_map(function ($error) {
                return sprintf('%s: %s (%s)', $error->getCode(), $error->getDetail(), $error->getField());
            }, $message->getErrors()));
        }

        parent::__construct($message, $code, $previous);
    }
}

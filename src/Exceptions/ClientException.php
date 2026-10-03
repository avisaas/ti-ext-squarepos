<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Exceptions;

use Exception;
use Square\Exceptions\SquareApiException;
use Throwable;

class ClientException extends Exception
{
    public function __construct($message = '', $code = 0, ?Throwable $previous = null)
    {
        if ($message instanceof SquareApiException) {
            $previous = $previous ?: $message;
            $code = $code ?: (int)$message->getCode();
            $errors = $message->getErrors();
            $message = $errors !== []
                ? implode(PHP_EOL, array_map(fn($error): string => sprintf('%s: %s (%s)', $error->getCode(), $error->getDetail(), $error->getField()), $errors))
                : $message->getMessage();
        }

        parent::__construct($message, $code, $previous);
    }
}

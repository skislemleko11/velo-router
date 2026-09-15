<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions;

use Velo\Exceptions\NotFoundException;

final class NotFoundControllerException extends NotFoundException implements RouterExceptionInterface
{
    protected $message = 'The requested controller was not found.';

    public function shouldLogException(): bool
    {
        return true;
    }
}
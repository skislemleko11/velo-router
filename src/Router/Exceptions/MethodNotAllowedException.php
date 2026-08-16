<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions;

use Exception;
use Throwable;
use Velo\Exceptions\Interfaces\HttpExceptionWithHeadersInterface;
use Velo\Router\Router\Exceptions\Interfaces\RouterExceptionInterface;

/**
 * It's thrown in Router class when the requested url is not found under the requested method, but under another one.
 * It represents 405 Error.
 */
class MethodNotAllowedException extends Exception implements RouterExceptionInterface, HttpExceptionWithHeadersInterface
{
    public function __construct(
        public readonly array $allowedMethods,
        string $message = 'Method Not Allowed!',
        int $code = 0,
        ?Throwable $previous = null,
    )
    {
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return 405;
    }

    public function shouldLogException(): bool
    {
        return false;
    }

    public function getPublicMessage(): string
    {
        return 'Method Not Allowed!';
    }

    public function getHeaders(): array
    {
        return ['Allow' => implode(', ', $this->allowedMethods)];
    }
}
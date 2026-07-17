<?php
declare(strict_types=1);

namespace Velo\Router\Exceptions\Interfaces;

interface HttpExceptionInterface
{
    public function getStatusCode(): int;
    public function shouldLogException(): bool;
}
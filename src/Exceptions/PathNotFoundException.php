<?php
declare(strict_types=1);

namespace Velo\Router\Exceptions;

use Exception;

class PathNotFoundException extends Exception
{
    protected $message = 'The requested path not found!';
}
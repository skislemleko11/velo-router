<?php
declare(strict_types=1);

namespace Velo\Router\PathResolver\Exceptions;

class PathNotFoundException extends PathResolverException
{
    protected $message = 'The requested path not found!';
}
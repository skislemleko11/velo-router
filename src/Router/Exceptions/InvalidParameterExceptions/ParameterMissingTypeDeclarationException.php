<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions\InvalidParameterExceptions;

class ParameterMissingTypeDeclarationException extends InvalidParameterException
{
    protected $message = 'Parameter is missing a type declaration!';
}
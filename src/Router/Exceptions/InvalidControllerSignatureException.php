<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions;

use Exception;
use Velo\Router\Router\Exceptions\Interfaces\RouterExceptionInterface;

final class InvalidControllerSignatureException extends Exception implements RouterExceptionInterface
{
    public static function missingTypeDeclaration(string $class, string $method, string $paramName): self
    {
        return new self("Parameter '\$$paramName' in $class::$method() is missing a type declaration.");
    }

    public static function unionTypeNotSupported(string $class, string $method, string $paramName): self
    {
        return new self("Parameter '\$$paramName' in $class::$method() uses a union type, which is not supported.");
    }

    public static function intersectionTypeNotSupported(string $class, string $method, string $paramName): self
    {
        return new self("Parameter '\$$paramName' in $class::$method() uses an intersection type, which is not supported.");
    }

    public static function missingRequiredArgument(string $class, string $method, string $paramName): self
    {
        return new self("Missing required argument '\$$paramName' for method $class::$method().");
    }

    public static function mixedTypeNotSupported(string $class, string $method, string $paramName): self
    {
        return new self("Parameter '\$$paramName' in $class::$method() uses a 'mixed' type, which is not supported.");
    }

    public static function unexpectedInvalidParameter(string $class, string $method, string $paramName): self
    {
        return new self("Parameter '\$$paramName' in $class::$method() has an invalid or unsupported type.");
    }
}
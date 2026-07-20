<?php
declare(strict_types=1);

namespace Velo\Router\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Velo\Router\Route;

class RouteTest extends TestCase
{
    private Route $route;

    protected function setUp(): void
    {
        $this->route = new Route(
            'GET',
            '/',
            'controller',
            'action'
        );
    }

    #[Test]
    public function it_sets_middleware_and_returns_self(): void
    {
        $self = $this->route->setMiddleware('middleware');
        $this->assertSame('middleware', $this->getProperty('middlewares'));
        $this->assertSame($this->route, $self);
    }

    #[Test]
    public function it_gets_middleware_and_returns_null(): void
    {
        $this->assertNull($this->route->getMiddleware(1));
    }

    #[Test]
    public function it_gets_middleware_and_returns_value(): void
    {
        $this->route->setMiddleware('middleware');
        $this->assertSame('middleware', $this->getProperty('middlewares'));
    }

    #[Test]
    #[DataProvider('middlewaresCountDataProvider')]
    public function it_gets_middleware_count(array $middlewares): void
    {
        foreach ($middlewares as $middleware) {
            $this->route->setMiddleware($middleware);
        }
        $this->assertSame(count($middlewares), $this->route->getMiddlewaresCount());
    }

    public static function middlewaresCountDataProvider(): array
    {
        return [
            [['middleware']],
            [['middleware', 'aaa']],
            [['middleware', 'as', 'af']],
            [['middleware', 'aqqqe', 's', 'r']],
        ];
    }

    private function getProperty(string $propertyName): mixed
    {
        $reflection = new ReflectionClass(Route::class);
        $reflectionProperty = $reflection->getProperty($propertyName);
        return $reflectionProperty->getValue($this->route)[0];
    }
}
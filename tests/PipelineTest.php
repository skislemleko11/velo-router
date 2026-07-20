<?php
declare(strict_types=1);

namespace Velo\Router\Tests;

use Exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use stdClass;
use Velo\Http\HttpRequest;
use Velo\Http\HttpResponse;
use Velo\Http\Interfaces\MiddlewareInterface;
use Velo\Router\Exceptions\ControllerMethodInvalidReturnTypeException;
use Velo\Router\Exceptions\MustImplementMiddlewareInterfaceException;
use Velo\Router\Pipeline;
use Velo\Router\Route;

class PipelineTest extends TestCase
{
    private FakeContainer $container;
    private Pipeline $pipeline;

    protected function setUp(): void
    {
        $this->container = new FakeContainer();
        $this->pipeline = new Pipeline($this->container);
    }

    #[Test]
    public function it_executes_controller_action_when_no_middlewares_registered(): void
    {
        $controller = new PipelineFakeController();
        $this->container->set(PipelineFakeController::class, $controller);

        $route = new Route('GET', '/test', PipelineFakeController::class, 'successAction');
        $request = new HttpRequest('/test', 'GET');

        $response = $this->pipeline->executeMiddlewareChain($route, $request, []);

        $this->assertInstanceOf(HttpResponse::class, $response);
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(1, $controller->wasCalled);
    }

    #[Test]
    public function it_passes_request_and_casted_args_to_controller(): void
    {
        $controller = new PipelineFakeController();
        $this->container->set(PipelineFakeController::class, $controller);

        $route = new Route('GET', '/users/{id}', PipelineFakeController::class, 'actionWithArgs');
        $request = new HttpRequest('/users/42', 'GET');

        $response = $this->pipeline->executeMiddlewareChain($route, $request, [42, 'john']);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame([42, 'john'], $controller->lastArgs);
    }

    #[Test]
    public function it_executes_middlewares_in_order_before_controller(): void
    {
        $controller = new PipelineFakeController();
        $middleware1 = new StepMiddleware('first');
        $middleware2 = new StepMiddleware('second');

        $this->container->set(PipelineFakeController::class, $controller);
        $this->container->set('Middleware1', $middleware1);
        $this->container->set('Middleware2', $middleware2);

        $route = new Route('GET', '/test', PipelineFakeController::class, 'successAction');
        $route->setMiddleware('Middleware1');
        $route->setMiddleware('Middleware2');

        $request = new HttpRequest('/test', 'GET');

        StepMiddleware::$executionOrder = [];

        $response = $this->pipeline->executeMiddlewareChain($route, $request, []);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['first', 'second'], StepMiddleware::$executionOrder);
        $this->assertSame(1, $controller->wasCalled);
    }

    #[Test]
    public function middleware_can_short_circuit_and_prevent_controller_execution(): void
    {
        $controller = new PipelineFakeController();
        $stoppingMiddleware = new StoppingPipelineMiddleware();

        $this->container->set(PipelineFakeController::class, $controller);
        $this->container->set(StoppingPipelineMiddleware::class, $stoppingMiddleware);

        $route = new Route('GET', '/admin', PipelineFakeController::class, 'successAction');
        $route->setMiddleware(StoppingPipelineMiddleware::class);

        $request = new HttpRequest('/admin', 'GET');

        $response = $this->pipeline->executeMiddlewareChain($route, $request, []);

        $this->assertSame(403, $response->statusCode);
        $this->assertSame(0, $controller->wasCalled);
    }

    #[Test]
    public function it_throws_exception_if_middleware_does_not_implement_middleware_interface(): void
    {
        $controller = new PipelineFakeController();
        $invalidMiddleware = new stdClass();

        $this->container->set(PipelineFakeController::class, $controller);
        $this->container->set('InvalidMiddleware', $invalidMiddleware);

        $route = new Route('GET', '/test', PipelineFakeController::class, 'successAction');
        $route->setMiddleware('InvalidMiddleware');

        $request = new HttpRequest('/test', 'GET');

        $this->expectException(MustImplementMiddlewareInterfaceException::class);
        $this->pipeline->executeMiddlewareChain($route, $request, []);
    }

    #[Test]
    public function it_throws_exception_if_controller_returns_invalid_type(): void
    {
        $controller = new PipelineFakeController();
        $this->container->set(PipelineFakeController::class, $controller);

        $route = new Route('GET', '/test', PipelineFakeController::class, 'invalidAction');
        $request = new HttpRequest('/test', 'GET');

        $this->expectException(ControllerMethodInvalidReturnTypeException::class);
        $this->pipeline->executeMiddlewareChain($route, $request, []);
    }
}


class FakeContainer implements ContainerInterface
{
    /** @var array<string, object> */
    private array $services = [];

    public function set(string $id, object $service): void
    {
        $this->services[$id] = $service;
    }

    public function get(string $id): object
    {
        if (!$this->has($id)) {
            throw new class("Service not found: $id") extends Exception implements NotFoundExceptionInterface {};
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}


class PipelineFakeController
{
    public int $wasCalled = 0;
    public array $lastArgs = [];

    public function successAction(HttpRequest $request): HttpResponse
    {
        $this->wasCalled++;
        return new HttpResponse(null, 200);
    }

    public function actionWithArgs(HttpRequest $request, int $id, string $name): HttpResponse
    {
        $this->wasCalled++;
        $this->lastArgs = [$id, $name];
        return new HttpResponse(null, 200);
    }

    public function invalidAction(HttpRequest $request): string
    {
        return 'Not an HttpResponse instance';
    }
}

class StepMiddleware implements MiddlewareInterface
{
    public static array $executionOrder = [];

    public function __construct(private readonly string $name)
    {
    }

    public function handle(HttpRequest $request, callable $next): HttpResponse
    {
        self::$executionOrder[] = $this->name;
        return $next($request);
    }
}

class StoppingPipelineMiddleware implements MiddlewareInterface
{
    public function handle(HttpRequest $request, callable $next): HttpResponse
    {
        return new HttpResponse(null, 403);
    }
}
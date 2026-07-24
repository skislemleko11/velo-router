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
use Velo\Router\Pipeline\Exceptions\ControllerMethodInvalidReturnTypeException;
use Velo\Router\Pipeline\Exceptions\MustImplementMiddlewareInterfaceException;
use Velo\Router\Pipeline\Pipeline;
use Velo\Router\Route\Route;

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

        $response = $this->pipeline->executeRoutesMiddlewaresChain($route, $request, []);

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

        $response = $this->pipeline->executeRoutesMiddlewaresChain($route, $request, [42, 'john']);

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
        $route->addMiddleware('Middleware1');
        $route->addMiddleware('Middleware2');

        $request = new HttpRequest('/test', 'GET');

        StepMiddleware::$executionOrder = [];

        $response = $this->pipeline->executeRoutesMiddlewaresChain($route, $request, []);

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
        $route->addMiddleware(StoppingPipelineMiddleware::class);

        $request = new HttpRequest('/admin', 'GET');

        $response = $this->pipeline->executeRoutesMiddlewaresChain($route, $request, []);

        $this->assertSame(403, $response->statusCode);
        $this->assertSame(0, $controller->wasCalled);
    }

    #[Test]
    public function it_supports_direct_middleware_instances(): void
    {
        $controller = new PipelineFakeController();
        $this->container->set(PipelineFakeController::class, $controller);

        $directMiddleware = new StepMiddleware('direct');

        $request = new HttpRequest('/test', 'GET');
        StepMiddleware::$executionOrder = [];

        $response = $this->pipeline->executeMiddlewaresChain(
            $request,
            [$directMiddleware],
            fn(HttpRequest $req) => $controller->successAction($req)
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['direct'], StepMiddleware::$executionOrder);
        $this->assertSame(1, $controller->wasCalled);
    }

    #[Test]
    public function it_passes_modified_request_down_the_middleware_chain_to_controller(): void
    {
        $controller = new PipelineFakeController();
        $this->container->set(PipelineFakeController::class, $controller);

        $modifyingMiddleware = new ModifyingRequestMiddleware();
        $route = new Route('GET', '/test', PipelineFakeController::class, 'actionCapturingRequest');
        $route->addMiddleware(ModifyingRequestMiddleware::class);

        $this->container->set(ModifyingRequestMiddleware::class, $modifyingMiddleware);

        $request = new HttpRequest('/original-path', 'GET');

        $response = $this->pipeline->executeRoutesMiddlewaresChain($route, $request, []);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('/modified-path', $controller->lastReceivedRequest?->url);
    }

    #[Test]
    public function it_throws_exception_if_middleware_does_not_implement_middleware_interface(): void
    {
        $controller = new PipelineFakeController();
        $invalidMiddleware = new stdClass();

        $this->container->set(PipelineFakeController::class, $controller);
        $this->container->set('InvalidMiddleware', $invalidMiddleware);

        $route = new Route('GET', '/test', PipelineFakeController::class, 'successAction');
        $route->addMiddleware('InvalidMiddleware');

        $request = new HttpRequest('/test', 'GET');

        $this->expectException(MustImplementMiddlewareInterfaceException::class);
        $this->pipeline->executeRoutesMiddlewaresChain($route, $request, []);
    }

    #[Test]
    public function it_throws_exception_if_controller_returns_invalid_type(): void
    {
        $controller = new PipelineFakeController();
        $this->container->set(PipelineFakeController::class, $controller);

        $route = new Route('GET', '/test', PipelineFakeController::class, 'invalidAction');
        $request = new HttpRequest('/test', 'GET');

        $this->expectException(ControllerMethodInvalidReturnTypeException::class);
        $this->pipeline->executeRoutesMiddlewaresChain($route, $request, []);
    }

    #[Test]
    public function it_passes_arguments_to_middleware(): void
    {
        $controller = new PipelineFakeController();
        $this->container->set(PipelineFakeController::class, $controller);
        $this->container->set(MiddlewareWithArgs::class, new MiddlewareWithArgs());

        $route = new Route('GET', '/test', PipelineFakeController::class, 'successAction');
        $route->addMiddleware(MiddlewareWithArgs::class, 'hehe', 'hihi');

        $request = new HttpRequest('/test', 'GET');
        $response = $this->pipeline->executeRoutesMiddlewaresChain($route, $request, []);

        $this->assertEquals(new HttpResponse(null, 200), $response);
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
            throw new class("Service not found: $id") extends Exception implements NotFoundExceptionInterface {
            };
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
    public ?HttpRequest $lastReceivedRequest = null;

    public function successAction(HttpRequest $request): HttpResponse
    {
        $this->wasCalled++;
        $this->lastReceivedRequest = $request;
        return new HttpResponse(null, 200);
    }

    public function actionWithArgs(HttpRequest $request, int $id, string $name): HttpResponse
    {
        $this->wasCalled++;
        $this->lastArgs = [$id, $name];
        $this->lastReceivedRequest = $request;
        return new HttpResponse(null, 200);
    }

    public function actionCapturingRequest(HttpRequest $request): HttpResponse
    {
        $this->wasCalled++;
        $this->lastReceivedRequest = $request;
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

class ModifyingRequestMiddleware implements MiddlewareInterface
{
    public function handle(HttpRequest $request, callable $next): HttpResponse
    {
        $modifiedRequest = new HttpRequest('/modified-path', $request->method);
        return $next($modifiedRequest);
    }
}

class MiddlewareWithArgs implements MiddlewareInterface
{
    public function handle(HttpRequest $request, callable $next, string $arg1 = '', string $arg2 = ''): HttpResponse
    {
        if ($arg1 === 'hehe' && $arg2 === 'hihi') {
            return $next($request);
        }

        return new HttpResponse(null, 500);
    }
}

class StoppingPipelineMiddleware implements MiddlewareInterface
{
    public function handle(HttpRequest $request, callable $next): HttpResponse
    {
        return new HttpResponse(null, 403);
    }
}
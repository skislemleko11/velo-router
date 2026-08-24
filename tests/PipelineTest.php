<?php
declare(strict_types=1);

namespace Velo\Router\Tests;

use Exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use stdClass;
use Velo\Http\Request;
use Velo\Http\Responses\Concrete\TextResponse;
use Velo\Router\Middlewares\MiddlewareInterface;
use Velo\Router\Pipeline\Exceptions\ControllerMethodInvalidReturnTypeException;
use Velo\Router\Pipeline\Exceptions\MustImplementMiddlewareInterfaceException;
use Velo\Router\Pipeline\Pipeline;
use Velo\Router\Route\Route;
use Velo\Http\RequestMethod;

final class PipelineTest extends TestCase
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

        $route = new Route(RequestMethod::GET, '/test', PipelineFakeController::class, 'successAction');
        $request = new Request('/test', RequestMethod::GET);

        $response = $this->pipeline->executeRoutesMiddlewaresChain($route, $request, [$request]);

        $this->assertInstanceOf(TextResponse::class, $response);
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(1, $controller->wasCalled);
    }

    #[Test]
    public function it_passes_request_and_casted_args_to_controller(): void
    {
        $controller = new PipelineFakeController();
        $this->container->set(PipelineFakeController::class, $controller);

        $route = new Route(RequestMethod::GET, '/users/{id}', PipelineFakeController::class, 'actionWithArgs');
        $request = new Request('/users/42', RequestMethod::GET);

        $response = $this->pipeline->executeRoutesMiddlewaresChain($route, $request, [$request, 42, 'john']);

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

        $route = new Route(RequestMethod::GET, '/test', PipelineFakeController::class, 'successAction');
        $route->addMiddleware('Middleware1');
        $route->addMiddleware('Middleware2');

        $request = new Request('/test', RequestMethod::GET);

        StepMiddleware::$executionOrder = [];

        $response = $this->pipeline->executeRoutesMiddlewaresChain($route, $request, [$request]);

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

        $route = new Route(RequestMethod::GET, '/admin', PipelineFakeController::class, 'successAction');
        $route->addMiddleware(StoppingPipelineMiddleware::class);

        $request = new Request('/admin', RequestMethod::GET);

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

        $request = new Request('/test', RequestMethod::GET);
        StepMiddleware::$executionOrder = [];

        $response = $this->pipeline->executeMiddlewaresChain(
            $request,
            [$directMiddleware],
            fn(Request $req) => $controller->successAction($req)
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['direct'], StepMiddleware::$executionOrder);
        $this->assertSame(1, $controller->wasCalled);
    }

    #[Test]
    public function it_throws_exception_if_middleware_does_not_implement_middleware_interface(): void
    {
        $controller = new PipelineFakeController();
        $invalidMiddleware = new stdClass();

        $this->container->set(PipelineFakeController::class, $controller);
        $this->container->set('InvalidMiddleware', $invalidMiddleware);

        $route = new Route(RequestMethod::GET, '/test', PipelineFakeController::class, 'successAction');
        $route->addMiddleware('InvalidMiddleware');

        $request = new Request('/test', RequestMethod::GET);

        $this->expectException(MustImplementMiddlewareInterfaceException::class);
        $this->pipeline->executeRoutesMiddlewaresChain($route, $request, []);
    }

    #[Test]
    public function it_throws_exception_if_controller_returns_invalid_type(): void
    {
        $controller = new PipelineFakeController();
        $this->container->set(PipelineFakeController::class, $controller);

        $route = new Route(RequestMethod::GET, '/test', PipelineFakeController::class, 'invalidAction');
        $request = new Request('/test', RequestMethod::GET);

        $this->expectException(ControllerMethodInvalidReturnTypeException::class);
        $this->pipeline->executeRoutesMiddlewaresChain($route, $request, []);
    }

    #[Test]
    public function it_passes_arguments_to_middleware(): void
    {
        $controller = new PipelineFakeController();
        $this->container->set(PipelineFakeController::class, $controller);
        $this->container->set(MiddlewareWithArgs::class, new MiddlewareWithArgs());

        $route = new Route(RequestMethod::GET, '/test', PipelineFakeController::class, 'successAction');
        $route->addMiddleware([MiddlewareWithArgs::class, ['hehe', 'hihi']]);

        $request = new Request('/test', RequestMethod::GET);
        $response = $this->pipeline->executeRoutesMiddlewaresChain($route, $request, [$request]);

        $this->assertEquals(new TextResponse('content'), $response);
    }

    #[Test]
    public function it_supports_callable_factories_returning_middleware(): void
    {
        $controller = new PipelineFakeController();
        $this->container->set(PipelineFakeController::class, $controller);

        $factory = fn() => new StepMiddleware('from_factory');

        StepMiddleware::$executionOrder = [];

        $response = $this->pipeline->executeMiddlewaresChain(
            new Request('/test', RequestMethod::GET),
            [$factory],
            fn(Request $req) => $controller->successAction($req)
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['from_factory'], StepMiddleware::$executionOrder);
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
    public ?Request $lastReceivedRequest = null;

    public function successAction(Request $request): TextResponse
    {
        $this->wasCalled++;
        $this->lastReceivedRequest = $request;
        return new TextResponse('content');
    }

    public function actionWithArgs(Request $request, int $id, string $name): TextResponse
    {
        $this->wasCalled++;
        $this->lastArgs = [$id, $name];
        $this->lastReceivedRequest = $request;
        return new TextResponse('content');
    }

    public function actionCapturingRequest(Request $request): TextResponse
    {
        $this->wasCalled++;
        $this->lastReceivedRequest = $request;
        return new TextResponse('content');
    }

    public function invalidAction(): string
    {
        return 'Not an TextResponse instance';
    }
}

class StepMiddleware implements MiddlewareInterface
{
    public static array $executionOrder = [];

    public function __construct(private readonly string $name)
    {
    }

    public function handle(Request $request, callable $next): TextResponse
    {
        self::$executionOrder[] = $this->name;
        return $next($request);
    }
}

class ModifyingRequestMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): TextResponse
    {
        $modifiedRequest = new Request('/modified-path', $request->method);
        return $next($modifiedRequest);
    }
}

class MiddlewareWithArgs implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, string $arg1 = '', string $arg2 = ''): TextResponse
    {
        if ($arg1 === 'hehe' && $arg2 === 'hihi') {
            return $next($request);
        }

        return new TextResponse('Internal Server Error', 500);
    }
}

class StoppingPipelineMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): TextResponse
    {
        return new TextResponse('Forbidden', 403);
    }
}
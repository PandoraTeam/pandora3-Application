<?php
namespace Pandora3\Libs\Application;

use Pandora3\Core\Application\BaseApplication;
use Pandora3\Core\Container\Container;
use Pandora3\Core\Http\Request;
use Pandora3\Core\Http\Response;
use Pandora3\Core\Interfaces\DatabaseConnectionInterface;
use Pandora3\Core\Interfaces\RequestDispatcherInterface;
use Pandora3\Core\Interfaces\RequestHandlerInterface;
use Pandora3\Core\Interfaces\RequestInterface;
use Pandora3\Core\Interfaces\ResponseInterface;
use Pandora3\Core\Interfaces\RouterInterface;
use Pandora3\Core\Interfaces\SessionInterface;
use Pandora3\Core\MiddlewareRouter\MiddlewareRouter;
use Pandora3\Core\Router\Exceptions\RouteNotFoundException;
use Pandora3\Core\Router\RequestHandler;
use Pandora3\Libs\Database\DatabaseConnection;
use Pandora3\Libs\Session\Session;
use Pandora3\Plugins\Authorisation\Authorisation;
use Pandora3\Plugins\Authorisation\Interfaces\UserProviderInterface;
use Pandora3\Plugins\Authorisation\Middlewares\AuthorisedMiddleware;

/**
 * Class Application
 * @package Pandora3\Libs\Application
 *
 * @property-read string $baseUri
 * @property-read DatabaseConnectionInterface $database
 * @property-read Authorisation $auth
 * @property-read RequestInterface $request
 * @property-read RouterInterface $router
 */
abstract class Application extends BaseApplication {

	/** @var Application $instance */
	protected static $instance;
	
	/** @var RequestDispatcherInterface $dispatcher */
	protected $dispatcher;

	/**
	 * @return static
	 */
	public static function getInstance(): self {
		return self::$instance;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run(string $mode = self::MODE_DEV): void {
		self::$instance = $this;
		parent::run($mode);
	}

	/**
	 * @param string $name
	 * @param string $className
	 */
	protected function registerMiddleware(string $name, string $className): void {
		$this->container->set("middleware.$name", $className);
	}

	/**
	 * Gets application routes
	 *
	 * @return array
	 */
	protected function getRoutes(): array {
		// todo: warning - no routes defined
		return include("{$this->path}/routes.php");
	}

	/**
	 * @return string
	 */
	public function getSecret(): string {
		return $this->config->get('secret');
	}

	/**
	 * @internal
	 * @return string
	 */
	protected function getBaseUri(): string {
		return $this->config->get('baseUri') ?? '/';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function getConfig(): array {
		return array_replace(
			$this->loadConfig($this->path.'/../config/config.php'),
			$this->loadConfig($this->path.'/../config/config'.ucfirst($this->mode).'.php'),
			$this->loadConfig($this->path.'/../config/local.php')
		);
	}

	protected function page404(RequestInterface $request): ResponseInterface {
		return new Response('404 page not found');
	}
	
	/**
	 * @param RouterInterface|MiddlewareRouter $router
	 * @param array $routes
	 */
	protected function registerRoutes($router, array $routes) {
		foreach($routes as $routePath => $handler) {
			$middlewares = [];
			if (is_array($handler)) {
				[$middlewares, $handler] = $handler;
				if (!is_array($middlewares)) {
					$middlewares = [$middlewares];
				}
			}
			
			if ($router instanceof MiddlewareRouter) {
				$router->add($routePath, $handler, $middlewares);
			} else {
				$router->add($routePath, $handler);
			}
		}
	}
	
	/**
	 * @return RequestDispatcherInterface
	 */
	protected function getDispatcher(): RequestDispatcherInterface {
		if (is_null($this->dispatcher)) {
			/** @var RouterInterface|MiddlewareRouter $dispatcher */
			$dispatcher = $this->container->get(RouterInterface::class);
			$routes = $this->getRoutes();
			$this->registerRoutes($dispatcher, $routes);
			$this->dispatcher = $dispatcher;
		}
		return $this->dispatcher;
	}
	
	/**
	 * @param array $arguments
	 * @return RequestHandlerInterface
	 */
	protected function dispatch(array &$arguments): RequestHandlerInterface {
		$dispatcher = $this->getDispatcher();
		try {
			return $dispatcher->dispatch($this->request->uri, $arguments);
		} catch (RouteNotFoundException $ex) {
			return new RequestHandler( function(RequestInterface $request) {
				return $this->page404($request);
			});
		}
	}
	
	/**
	 * @param string $uri
	 * @return RequestHandlerInterface
	 */
	protected function redirectUriHandler(string $uri): RequestHandlerInterface {
		return new RequestHandler( function() use ($uri) {
			return new Response('', ['location' => $uri]);
		});
	}
	
	/**
	 * @return RequestHandlerInterface
	 */
	protected function getUnauthorisedHandler(): RequestHandlerInterface {
		// return new RequestHandler( \Closure::fromCallable([$this, 'page404']) );
		return $this->redirectUriHandler( $this->config->get('auth')['uriSignIn'] );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function dependencies(Container $container): void {
		parent::dependencies($container);

		$container->setDependencies([
			RequestInterface::class => Request::class,
			RouterInterface::class => MiddlewareRouter::class,
		]);
		$container->set(MiddlewareRouter::class, function(Container $c, $routes = []) {
			return new MiddlewareRouter($routes, $c);
		});
		$container->set(Request::class, function() {
			$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
			$uri = (strncmp($uri, '/', 1) === 0 ? '' : '/').$uri;
			return new Request($uri);
		});

		$this->setProperties([
			'request' => RequestInterface::class
		]);

		$container->setDependenciesShared([
			DatabaseConnectionInterface::class => DatabaseConnection::class,
			SessionInterface::class => Session::class
		]);

		$this->setProperty('baseUri', function() { return $this->getBaseUri(); });

		if ($this->config->has('database')) {
			$container->set(DatabaseConnection::class, function() {
				return new DatabaseConnection($this->config->get('database'));
			});
			$this->setProperty('database', DatabaseConnectionInterface::class);
		}

		// todo: default UserProviderInterface
		$container->set(UserProviderInterface::class, function() {
			return null;
		});


		$this->setProperty('auth', Authorisation::class);

		$container->set(AuthorisedMiddleware::class, function(Container $c) {
			return new AuthorisedMiddleware($this->getUnauthorisedHandler(), $c->get('auth'));
		});

		$this->registerMiddleware('auth', AuthorisedMiddleware::class);
	}
	
	protected function execute(): void {
		$arguments = [];
		$handler = $this->dispatch($arguments);
		$response = $handler->handle($this->request, $arguments);
		$response->send();
	}

}
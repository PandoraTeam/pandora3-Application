<?php
namespace Pandora3\Libs\Application;

use Pandora3\Core\Application\BaseApplication;
use Pandora3\Core\Container\Container;
use Pandora3\Core\Http\Response;
use Pandora3\Core\Interfaces\DatabaseConnectionInterface;
use Pandora3\Core\Interfaces\RequestHandlerInterface;
use Pandora3\Core\Interfaces\RequestInterface;
use Pandora3\Core\Interfaces\ResponseInterface;
use Pandora3\Core\Interfaces\SessionInterface;
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
 */
abstract class Application extends BaseApplication {

	/** @var Application $instance */
	protected static $instance;

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
	
	protected function dispatch(array &$arguments): RequestHandlerInterface {
		try {
			return parent::dispatch($arguments);
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

		$container->setShared(DatabaseConnectionInterface::class, DatabaseConnection::class);
		$container->setShared(SessionInterface::class, Session::class);

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

		$container->set(AuthorisedMiddleware::class, function() {
			return new AuthorisedMiddleware($this->getUnauthorisedHandler(), $this->container->get(Authorisation::class));
		});

		$this->registerMiddleware('auth', AuthorisedMiddleware::class);

		$this->setProperty('auth', Authorisation::class);
	}

}
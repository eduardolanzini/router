<?php

namespace EduardoLanzini;

Class Router
{
	private $route, $target, $name;

	protected $routes = array();

	protected $namedRoutes = array();

	protected $basePath;

	protected $controllerPath;

	protected $viewPath;

	protected $matchTypes = array(
		'i'  => '[0-9]++',
		'a'  => '[0-9A-Za-z]++',
		'h'  => '[0-9A-Fa-f]++',
		'*'  => '.+?',
		'**' => '.++',
		''   => '[^/\.]++'
	);

	private $errors = [];

	public function __construct($routes = array(), $basePath = '', $matchTypes = array())
	{
		$this->addRoutes($routes);
		$this->setBasePath($basePath);
		$this->addMatchTypes($matchTypes);
	}

	public function getRoutes()
	{
		return $this->routes;
	}

	public function addRoutes($routes)
	{
		if(!is_array($routes) && !$routes instanceof Traversable) {
			$this->error = 'Routes should be an array or an instance of Traversable';
			return false;
		}
		foreach($routes as $route) {
			call_user_func_array(array($this, 'map'), $route);
		}
	}

	public function setBasePath($path)
	{
		$this->basePath = $path;
	}

	public function setControllerPath($path)
	{
		$this->controllerPath = $path;
	}

	public function setViewPath($path)
	{
		$this->viewPath = $path;
	}

	public function addMatchTypes($matchTypes)
	{
		$this->matchTypes = array_merge($this->matchTypes, $matchTypes);
	}

	public function map($method, $route, $target, $middleware = null, $name = null)
	{
		$this->routes[] = array($method, $route, $target, $middleware, $name);

		if($name) {
			if(isset($this->namedRoutes[$name])) {
				$this->error = "Can not redeclare route '{$name}'";
				return false;
			} else {
				$this->namedRoutes[$name] = $route;
			}

		}

		return;
	}

	public function get($route, $target, $middleware = null, $name = null)
	{
		$this->map('GET', $route, $target, $middleware, $name);
		return;
	}

	public function post($route, $target, $name = null)
	{
		$this->map('POST', $route, $target, $name);
		return;
	}

	public function group($prefix, $tree, $middleware = null)
	{
	    foreach($tree as $node) {

	    	$method = $node[0];
	    	$route  = $prefix.$node[1];
	    	$target = $node[2];
	    	$name 	= isset($node[3]) ? $node[3] : null;

	    	$this->map($method, $route, $target, $middleware ,$name);
	    }
	    return;
	}

	public function generate($routeName, array $params = array())
	{
		if(!isset($this->namedRoutes[$routeName])) {
			$this->error = "Route '{$routeName}' does not exist.";
			return false;
		}

		$route = $this->namedRoutes[$routeName];

		$url = $this->basePath . $route;

		if (preg_match_all('`(/|\.|)\[([^:\]]*+)(?::([^:\]]*+))?\](\?|)`', $route, $matches, PREG_SET_ORDER)) {

			foreach($matches as $index => $match) {
				list($block, $pre, $type, $param, $optional) = $match;

				if ($pre) {
					$block = substr($block, 1);
				}

				if(isset($params[$param])) {

					$url = str_replace($block, $params[$param], $url);
				} elseif ($optional && $index !== 0) {

					$url = str_replace($pre . $block, '', $url);
				} else {

					$url = str_replace($block, '', $url);
				}
			}

		}

		return $url;
	}

	public function match($requestUrl = null, $requestMethod = null)
	{
		$params = array();
		$match = false;

		if($requestUrl === null) {
			$requestUrl = isset($_GET['route']) ? $_GET['route'] : '/';
		}

		if (($strpos = strpos($requestUrl, '?')) !== false) {
			$requestUrl = substr($requestUrl, 0, $strpos);
		}

		if($requestMethod === null) {
			$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
		}

		foreach($this->routes as $handler) {

			list($methods, $route, $target, $middleware, $name) = $handler;

			$method_match = (stripos($methods, $requestMethod) !== false);

			if (!$method_match) continue;

			if ($route === '*') {

				$match = true;

			} elseif (isset($route[0]) && $route[0] === '@') {

				$pattern = '`' . substr($route, 1) . '`u';
				$match = preg_match($pattern, $requestUrl, $params) === 1;

			} elseif (($position = strpos($route, '[')) === false) {

				$match = strcmp($requestUrl, $route) === 0;
			} else {

				if (strncmp($requestUrl, $route, $position) !== 0) {
					continue;
				}
				$regex = $this->compileRoute($route);
				$match = preg_match($regex, $requestUrl, $params) === 1;
			}

			if ($match) {

				if ($params) {
					foreach($params as $key => $value) {
						if(is_numeric($key)) unset($params[$key]);
					}
				}

				return array(
					'target' => $target,
					'params' => $params,
					'middleware' => $middleware,
					'name' => $name
				);
			}
		}
		return false;
	}

	protected function compileRoute($route)
	{
		if (preg_match_all('`(/|\.|)\[([^:\]]*+)(?::([^:\]]*+))?\](\?|)`', $route, $matches, PREG_SET_ORDER)) {

			$matchTypes = $this->matchTypes;
			foreach($matches as $match) {
				list($block, $pre, $type, $param, $optional) = $match;

				if (isset($matchTypes[$type])) {
					$type = $matchTypes[$type];
				}
				if ($pre === '.') {
					$pre = '\.';
				}

				$optional = $optional !== '' ? '?' : null;

				$pattern = '(?:'
						. ($pre !== '' ? $pre : null)
						. '('
						. ($param !== '' ? "?P<$param>" : null)
						. $type
						. ')'
						. $optional
						. ')'
						. $optional;

				$route = str_replace($block, $pattern, $route);
			}

		}
		return "`^$route$`u";
	}

	public function error()
	{
		return (!empty($this->errors)) ? $this->errors : false;
	}

	public function route($uri = null)
	{
		$match = $this->match($uri);

		if ($match) {

			$target = $match['target'];
			$params = $match['params'];
			$m = $match['middleware'];

			if (is_callable($m)) {

				//dd($match);
				$m();
			}

			if (is_callable($target)) {

				call_user_func_array( $target, $params );
			}

			elseif (preg_match("/:/", $target))
			{

				$class = explode(':', $target);

				$objName = 'Controllers\\'.$class[0];

				$obj = new $objName();

				$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

				$params = $requestMethod=='POST' ? array_merge($params, $_POST) : array_merge($params, $_GET);

				unset($params['route']);

				$obj->{$class[1]}((object)$params);
			}
			else
			{
				echo $target;
			}

			return true;

		}
		else {
			$this->error = 'Route not found';
			return false;
		}
	}
}
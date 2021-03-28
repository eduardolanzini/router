<?php

namespace EduardoLanzini;

Class Router
{
	protected $routes = array();

	protected $namedRoutes = array();

	protected $basePath;

	protected $controllerPath;

	private $errors = [];

	protected $matchTypes = array(
		'i'  => '[0-9]++',
		'a'  => '[0-9A-Za-z]++',
		'h'  => '[0-9A-Fa-f]++',
		'*'  => '.+?',
		'**' => '.++',
		''   => '[^/\.]++'
	);

	public function __construct($basePath = '')
	{
		$this->setBasePath($basePath);
	}

	public function getRoutes()
	{
		return $this->routes;
	}

	public function setBasePath($path)
	{
		$this->basePath = $path;
	}

	public function setControllerPath($path)
	{
		$this->controllerPath = $path;
	}

	public function map($method, $route, $target, $name = null)
	{
		$this->routes[] = array($method, $route, $target, $name);

		if($name) {
			if(isset($this->namedRoutes[$name])) {
				$this->error = "Não é possível declarar a rota '{$name}'";
				return false;
			} else {
				$this->namedRoutes[$name] = $route;
			}

		}

		return;
	}

	public function get($route, $target, $name = null)
	{
		$this->map('GET', $route, $target, $name);
		return;
	}

	public function post($route, $target, $name = null)
	{
		$this->map('POST', $route, $target, $name);
		return;
	}

	public function group($prefix, $tree)
	{
		foreach($tree as $node) {

			$method = $node[0];
			$route  = $prefix.$node[1];
			$target = $node[2];
			$name 	= isset($node[3]) ? $node[3] : null;

			$this->map($method, $route, $target, $name);
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

	public function match()
	{
		$params = array();
		$match = false;

		$requestUrl = isset($_GET['route']) ? $_GET['route'] : '/';


		if (($strpos = strpos($requestUrl, '?')) !== false) {
			$requestUrl = substr($requestUrl, 0, $strpos);
		}

		$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

		foreach($this->routes as $handler) {

			list($methods, $route, $target, $name) = $handler;

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

	public function route()
	{
		$match = $this->match();

		if ($match) {

			$target = $match['target'];
			$params = $match['params'];

			if (is_callable($target)) {

				call_user_func_array($target,$params);
			}

			elseif (preg_match("/:/", $target)){

				$class = explode(':', $target);

				$objName = 'App\\Controllers\\'.$class[0];

				if(!class_exists($objName)){
					exit("CLASSE '$class[0]' NÃO EXISTE");
				}

				$obj = new $objName();

				$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

				$params = $requestMethod == 'POST' ? array_merge($params, $_POST) : array_merge($params, $_GET);

				unset($params['route']);

				if (method_exists($obj,$class[1])) {
					$obj->{$class[1]}((object)$params);
				}else{
					exit("MÉTODO '$class[1]' NÃO EXISTE NO CONTROLLER '$class[0]' ");
				}
			}
			else
			{
				echo $target;
			}

			return true;

		}
		else {
			$this->error[] = 'Rota não encontrada';
			return false;
		}
	}

	public function getErrors()
	{
		return (!empty($this->errors)) ? $this->errors : false;
	}

	public function displayErrors()
	{
		$errors = '';

		foreach($this->getErrors() as $error)
		{
			$errors .= "<p>{$error}</p><br>";
		}

		return $errors;
	}
}
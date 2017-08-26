<?php

namespace MyAdmin\Plugins;

/**
 * Class Loader
 *
 * Here are some of the regexes ive used to change code to using this class:
 *  ('[^']*') *=> *('[^']*'),
 *  $loader->add_requirement(\1, \2);
 *
 * @package MyAdmin
 */
class Loader {
	protected $requirements;
	protected $routes;
	protected $admin_routes;
	protected $public_routes;

	/**
	 * Loader constructor.
	 */
	public function __construct() {
		$this->requirements = [];
		$this->routes = [];
		$this->admin_routes = [];
		$this->public_routes = [];
	}

	/**
	 * gets the page routes
	 *
	 * @param bool $include_admin
	 * @return array of routes
	 */
	public function get_routes($include_admin = FALSE) {
		//if ($include_admin === FALSE && $GLOBALS['tf']->ima === 'admin')
			//$include_admin = TRUE;
		return $include_admin === TRUE? array_merge($this->admin_routes, $this->routes) : $this->routes;
	}

	/**
	 * gets the admin page routes
	 *
	 * @return array of routes
	 */
	public function get_admin_routes() {
		return $this->admin_routes;
	}

	/**
	 * gets the public page routes
	 *
	 * @return array of routes
	 */
	public function get_public_routes() {
		return $this->public_routes;
	}

	/**
	 * returns the value of a setting
	 *
	 * @param string $setting
	 * @return mixed the value of the setting
	 */
	public function get_setting($setting) {
		return constant($setting);
	}

	/**
	 * adds a requirement into the loader and registers it as a page with the router
	 *
	 * @param string $function php function name or class.class_name
	 * @param string $source php source file
	 * @param string $namespace optional php namespace
	 */
	public function add_page_requirement($function, $source, $namespace = '') {
		$this->routes['/'.$function] = $namespace.$function;
		$this->admin_routes['/'.$function] = $namespace.$function;
		$this->add_requirement($function, $source, $namespace);
	}

	/**
	 * adds a requirement into the loader and registers it as a page with the router
	 *
	 * @param string $function php function name or class.class_name
	 * @param string $source php source file
	 * @param string $namespace optional php namespace
	 */
	public function add_root_page_requirement($function, $source, $namespace = '') {
		$this->routes['/'.$function] = $namespace.$function;
		$this->add_requirement($function, $source, $namespace);
	}

	/**
	 * adds a requirement into the loader and registers it as a page with the router
	 *
	 * @param string $function php function name or class.class_name
	 * @param string $path source file path
	 */
	public function add_public_path($page, $source) {
		$this->public_routes['/'.$page] = $source;
	}

	/**
	 * adds a requirement into the loader and registers it as a page with the router
	 *
	 * @param string $function php function name or class.class_name
	 * @param string $source php source file
	 * @param string $namespace optional php namespace
	 */
	public function add_ajax_page_requirement($function, $source, $namespace = '') {
		$this->routes['/ajax/'.$function] = $namespace.$function;
		$this->add_requirement($function, $source, $namespace);
	}

	/**
	 * adds a requirement into the loader and registers it as a page with the router
	 *
	 * @param string $function php function name or class.class_name
	 * @param string $source php source file
	 * @param string $namespace optional php namespace
	 */
	public function add_admin_page_requirement($function, $source, $namespace = '') {
		$this->admin_routes['/'.$function] = $namespace.$function;
		$this->add_requirement($function, $source, $namespace);
	}

	/**
	 * adds a requirement into the loader
	 *
	 * @param string $function php function name or class.class_name
	 * @param string $source php source file
	 * @param string $namespace optional php namespace
	 */
	public function add_requirement($function, $source, $namespace = '') {
		$this->requirements[$function] = $namespace.$source;
	}

	/**
	 * gets an array of requirements for loading
	 *
	 * @return array the array of requirements
	 */
	public function get_requirements() {
		return $this->requirements;
	}
}


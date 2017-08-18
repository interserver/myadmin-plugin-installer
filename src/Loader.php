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
	private $requirements;
	private $routes;

	/**
	 * Loader constructor.
	 */
	public function __construct() {
		$this->requirements = [];
		$this->routes = [];
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
		$this->routes[] = ['/'.$function, $namespace.$source];
		$this->routes[] = ['/admin/'.$function, $namespace.$source];
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
		$this->routes[] = ['/'.$function, $namespace.$source];
		$this->add_requirement($function, $source, $namespace);
	}

	/**
	 * adds a requirement into the loader and registers it as a page with the router
	 *
	 * @param string $function php function name or class.class_name
	 * @param string $source php source file
	 * @param string $namespace optional php namespace
	 */
	public function add_ajax_page_requirement($function, $source, $namespace = '') {
		$this->routes[] = ['/ajax/'.$function, $namespace.$source];
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
		$this->routes[] = ['/admin/'.$function, $namespace.$source];
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

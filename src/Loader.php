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
class Loader
{
    protected $requirements;
    protected $routes;
    protected $admin_routes;
    protected $public_routes;

    /**
     * Loader constructor.
     */
    public function __construct()
    {
        $this->requirements = [];
        $this->routes = [];
        $this->admin_routes = [];
        $this->public_routes = [];
    }

    /**
     * gets the page routes
     *
     * @return array of routes
     */
    public function get_routes()
    {
        $routes = $this->routes;
        uksort($routes, function ($a, $b) {
            if (strlen($a) == strlen($b)) {
                if ($a == $b) {
                    return 0;
                }
                return ($a > $b) ? -1 : 1;
            } else {
                return (strlen($a) > strlen($b)) ? -1 : 1;
            }
        });
        //myadmin_log('route', 'debug', json_encode($routes), __LINE__, __FILE__);
        return $routes;
    }

    /**
     * returns the value of a setting
     *
     * @param string $setting
     * @return mixed the value of the setting
     */
    public function get_setting($setting)
    {
        return constant($setting);
    }

    /**
     * adds a requirement into the loader and registers it as a page with the router
     *
     * @param string $type route type  client,admin,public,public_file,client_ajax,public_ajax,admin_ajax
     * @param string $function php function name or class.class_name
     * @param string $source php source file
     * @param string $namespace optional php namespace
     * @param string $base base path
     * @param mixed $methods request methods, string or array including get post put head patch etc..
     */
    public function add_route_requirement($type, $function, $source, $namespace = '', $path = false, $methods = false)
    {
        if ($path === false)
            $path = '/'.$function;
        if ($methods === false)
            $methods = ['GET', 'POST'];
        $this->routes[$path] = [$type, $namespace.$function, $methods];
        $this->add_requirement($function, $source, $namespace);
    }

    /**
     * adds a requirement into the loader and registers it as a page with the router
     *
     * @param string $function php function name or class.class_name
     * @param string $source php source file
     * @param string $namespace optional php namespace
     * @param mixed $methods request methods, string or array including get post put head patch etc..
     */
    public function add_page_requirement($function, $source, $namespace = '', $methods = false)
    {
        $this->add_route_requirement('client', $function, $source, $namespace, '/'.$function, $methods);
        $this->add_route_requirement('client', $function, $source, $namespace, '/admin/'.$function, $methods);
    }

    /**
     * adds a requirement into the loader and registers it as a page with the router
     *
     * @param string $function php function name or class.class_name
     * @param string $source php source file
     * @param string $namespace optional php namespace
     * @param mixed $methods request methods, string or array including get post put head patch etc..
     */
    public function add_root_page_requirement($function, $source, $namespace = '', $methods = false)
    {
        $this->add_route_requirement('client', $function, $source, $namespace, '/'.$function, $methods);
    }

    /**
     * adds a requirement into the loader and registers it as a page with the router
     *
     * @param string $function php function name or class.class_name
     * @param string $path source file path
     * @param mixed $methods request methods, string or array including get post put head patch etc..
     */
    public function add_public_requirement($function, $source, $namespace = '', $methods = false)
    {
        $this->add_route_requirement('public', $function, $source, $namespace, '/'.$function, $methods);
    }

    /**
     * adds a requirement into the loader and registers it as a page with the router
     *
     * @param string $function php function name or class.class_name
     * @param string $source source file path
     * @param mixed $methods request methods, string or array including get post put head patch etc..
     */
    public function add_public_file($function, $source, $methods = false)
    {
        $this->add_route_requirement('public_file', $function, $source, '', '/'.$function, $methods);
    }

    /**
     * adds a requirement into the loader and registers it as a page with the router
     *
     * @param string $function php function name or class.class_name
     * @param string $source php source file
     * @param string $namespace optional php namespace
     * @param mixed $methods request methods, string or array including get post put head patch etc..
     */
    public function add_ajax_page_requirement($function, $source, $namespace = '', $methods = false)
    {
        $this->add_route_requirement('client_ajax', $function, $source, $namespace, '/ajax/'.$function, $methods);
        $this->add_route_requirement('client_ajax', $function, $source, $namespace, '/admin/ajax/'.$function, $methods);
    }

    /**
     * adds a requirement into the loader and registers it as a page with the router
     *
     * @param string $function php function name or class.class_name
     * @param string $source php source file
     * @param string $namespace optional php namespace
     * @param mixed $methods request methods, string or array including get post put head patch etc..
     */
    public function add_api_page_requirement($function, $source, $namespace = '', $methods = false)
    {
        $this->add_route_requirement('client_api', $function, $source, $namespace, '/apiv2/'.$function, $methods);
        $this->add_route_requirement('client_api', $function, $source, $namespace, '/admin/apiv2/'.$function, $methods);
    }

    /**
     * adds a requirement into the loader and registers it as a page with the router
     *
     * @param string $function php function name or class.class_name
     * @param string $source php source file
     * @param string $namespace optional php namespace
     * @param mixed $methods request methods, string or array including get post put head patch etc..
     */
    public function add_apmin_api_page_requirement($function, $source, $namespace = '', $methods = false)
    {
        $this->add_route_requirement('admin_api', $function, $source, $namespace, '/admin/ajax/'.$function, $methods);
    }

    /**
     * adds a requirement into the loader and registers it as a page with the router
     *
     * @param string $function php function name or class.class_name
     * @param string $source php source file
     * @param string $namespace optional php namespace
     * @param mixed $methods request methods, string or array including get post put head patch etc..
     */
    public function add_admin_page_requirement($function, $source, $namespace = '', $methods = false)
    {
        $this->add_route_requirement('admin', $function, $source, $namespace, '/admin/'.$function, $methods);
    }

    /**
     * adds a requirement into the loader
     *
     * @param string $function php function name or class.class_name
     * @param string $source php source file
     * @param string $namespace optional php namespace
     * @param mixed $methods request methods, string or array including get post put head patch etc..
     */
    public function add_requirement($function, $source, $namespace = '', $methods = false)
    {
        $this->requirements[$function] = $namespace.$source;
    }

    /**
     * gets an array of requirements for loading
     *
     * @return array the array of requirements
     */
    public function get_requirements()
    {
        return $this->requirements;
    }
}

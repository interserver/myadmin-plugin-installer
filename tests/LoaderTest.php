<?php

namespace Tests\MyAdmin\Plugins;

use MyAdmin\Plugins\Loader;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for the Loader class.
 *
 * Covers route management, requirement registration, and sorting behavior.
 *
 * @covers \MyAdmin\Plugins\Loader
 */
class LoaderTest extends TestCase
{
    /**
     * @var Loader
     */
    private $loader;

    protected function setUp(): void
    {
        $this->loader = new Loader();
    }

    /**
     * Test that a freshly constructed Loader has empty requirements.
     */
    public function testConstructorInitializesEmptyRequirements(): void
    {
        $this->assertSame([], $this->loader->get_requirements());
    }

    /**
     * Test that a freshly constructed Loader has empty routes.
     */
    public function testConstructorInitializesEmptyRoutes(): void
    {
        $this->assertSame([], $this->loader->get_routes());
    }

    /**
     * Test adding a requirement registers the function-source mapping.
     */
    public function testAddRequirementRegistersMapping(): void
    {
        $this->loader->add_requirement('my_function', '/path/to/source.php');

        $requirements = $this->loader->get_requirements();
        $this->assertArrayHasKey('my_function', $requirements);
        $this->assertSame('/path/to/source.php', $requirements['my_function']);
    }

    /**
     * Test that add_requirement with empty source does not register.
     */
    public function testAddRequirementWithEmptySourceDoesNotRegister(): void
    {
        $this->loader->add_requirement('my_function', '');

        $this->assertSame([], $this->loader->get_requirements());
    }

    /**
     * Test add_route_requirement creates a route entry with default path and methods.
     */
    public function testAddRouteRequirementWithDefaults(): void
    {
        $this->loader->add_route_requirement('client', 'dashboard', '/path/to/dashboard.php');

        $routes = $this->loader->get_routes();
        $this->assertArrayHasKey('/dashboard', $routes);
        $this->assertSame(['client', 'dashboard', ['GET', 'POST']], $routes['/dashboard']);
    }

    /**
     * Test add_route_requirement with custom path and methods.
     */
    public function testAddRouteRequirementWithCustomPathAndMethods(): void
    {
        $this->loader->add_route_requirement('admin', 'settings', 'settings.php', '/custom/settings', ['PUT']);

        $routes = $this->loader->get_routes();
        $this->assertArrayHasKey('/custom/settings', $routes);
        $this->assertSame(['admin', 'settings', ['PUT']], $routes['/custom/settings']);
    }

    /**
     * Test add_route_requirement also registers the source as a requirement.
     */
    public function testAddRouteRequirementRegistersSource(): void
    {
        $this->loader->add_route_requirement('client', 'dashboard', '/path/to/dashboard.php');

        $requirements = $this->loader->get_requirements();
        $this->assertArrayHasKey('dashboard', $requirements);
        $this->assertSame('/path/to/dashboard.php', $requirements['dashboard']);
    }

    /**
     * Test add_route_requirement with empty source does not register a requirement.
     */
    public function testAddRouteRequirementEmptySourceSkipsRequirement(): void
    {
        $this->loader->add_route_requirement('client', 'dashboard', '');

        $this->assertSame([], $this->loader->get_requirements());
    }

    /**
     * Test add_page_requirement creates both client and admin routes.
     */
    public function testAddPageRequirementCreatesBothRoutes(): void
    {
        $this->loader->add_page_requirement('users', 'users.php');

        $routes = $this->loader->get_routes();
        $this->assertArrayHasKey('/users', $routes);
        $this->assertArrayHasKey('/admin/users', $routes);
    }

    /**
     * Test add_root_page_requirement creates only the root client route.
     */
    public function testAddRootPageRequirementCreatesOnlyRootRoute(): void
    {
        $this->loader->add_root_page_requirement('home', 'home.php');

        $routes = $this->loader->get_routes();
        $this->assertArrayHasKey('/home', $routes);
        $this->assertArrayNotHasKey('/admin/home', $routes);
    }

    /**
     * Test add_public_requirement creates a public route.
     */
    public function testAddPublicRequirementCreatesPublicRoute(): void
    {
        $this->loader->add_public_requirement('login', 'login.php');

        $routes = $this->loader->get_routes();
        $this->assertArrayHasKey('/login', $routes);
        $this->assertSame('public', $routes['/login'][0]);
    }

    /**
     * Test add_ajax_page_requirement creates ajax routes.
     */
    public function testAddAjaxPageRequirementCreatesAjaxRoutes(): void
    {
        $this->loader->add_ajax_page_requirement('get_data', 'ajax_data.php');

        $routes = $this->loader->get_routes();
        $this->assertArrayHasKey('/ajax/get_data', $routes);
        $this->assertArrayHasKey('/admin/ajax/get_data', $routes);
        $this->assertSame('client_ajax', $routes['/ajax/get_data'][0]);
    }

    /**
     * Test add_api_page_requirement creates API routes.
     */
    public function testAddApiPageRequirementCreatesApiRoutes(): void
    {
        $this->loader->add_api_page_requirement('list_items', 'api_list.php');

        $routes = $this->loader->get_routes();
        $this->assertArrayHasKey('/apiv2/list_items', $routes);
        $this->assertArrayHasKey('/admin/apiv2/list_items', $routes);
        $this->assertSame('client_api', $routes['/apiv2/list_items'][0]);
    }

    /**
     * add_admin_api_page_requirement registers under /admin/apiv2/, mirroring the /apiv2/
     * convention used by add_api_page_requirement().
     */
    public function testAddAdminApiPageRequirementCreatesAdminApiRoute(): void
    {
        $this->loader->add_admin_api_page_requirement('admin_action', 'admin_api.php');

        $routes = $this->loader->get_routes();
        $this->assertArrayHasKey('/admin/apiv2/admin_action', $routes);
        $this->assertSame('admin_api', $routes['/admin/apiv2/admin_action'][0]);
    }

    /**
     * Regression: the misspelled legacy alias must forward to the corrected route rather
     * than registering under /admin/ajax/, where it silently overwrote the client_ajax
     * registration made by add_ajax_page_requirement().
     */
    public function testMisspelledAliasForwardsToCorrectAdminApiRoute(): void
    {
        $this->loader->add_apmin_api_page_requirement('admin_action', 'admin_api.php');

        $routes = $this->loader->get_routes();
        $this->assertArrayHasKey('/admin/apiv2/admin_action', $routes);
        $this->assertArrayNotHasKey('/admin/ajax/admin_action', $routes);
    }

    /**
     * Regression: add_ajax_page_requirement and the admin-API registration must no longer
     * collide on /admin/ajax/<name>.
     */
    public function testAjaxAndAdminApiRoutesDoNotCollide(): void
    {
        $this->loader->add_ajax_page_requirement('thing', 'thing.php');
        $this->loader->add_admin_api_page_requirement('thing', 'thing.php');

        $routes = $this->loader->get_routes();
        $this->assertSame('client_ajax', $routes['/admin/ajax/thing'][0]);
        $this->assertSame('client_ajax', $routes['/ajax/thing'][0]);
        $this->assertSame('admin_api', $routes['/admin/apiv2/thing'][0]);
    }

    /**
     * Regression: add_public_file() used to pass six arguments to a five-parameter method.
     * The surplus '' shifted $path to '' and $methods to the string '/'.$function, so every
     * call registered under the empty-string route key and stored a string where the router
     * expects an array of HTTP methods.
     */
    public function testAddPublicFileRegistersUnderItsOwnPath(): void
    {
        $this->loader->add_public_file('robots.txt', 'robots.php');

        $routes = $this->loader->get_routes();
        $this->assertArrayNotHasKey('', $routes, 'must not register under the empty-string key');
        $this->assertArrayHasKey('/robots.txt', $routes);
        $this->assertSame('public_file', $routes['/robots.txt'][0]);
        $this->assertIsArray($routes['/robots.txt'][2], 'methods slot must be an array, not a path string');
        $this->assertSame(['GET', 'POST'], $routes['/robots.txt'][2]);
    }

    /**
     * Two add_public_file() calls must produce two distinct routes, not overwrite each other
     * on a shared empty key.
     */
    public function testMultipleAddPublicFileCallsDoNotCollide(): void
    {
        $this->loader->add_public_file('robots.txt', 'robots.php');
        $this->loader->add_public_file('sitemap.xml', 'sitemap.php');

        $routes = $this->loader->get_routes();
        $this->assertArrayHasKey('/robots.txt', $routes);
        $this->assertArrayHasKey('/sitemap.xml', $routes);
    }

    /**
     * Test add_admin_page_requirement creates an admin route.
     */
    public function testAddAdminPageRequirementCreatesAdminRoute(): void
    {
        $this->loader->add_admin_page_requirement('manage', 'manage.php');

        $routes = $this->loader->get_routes();
        $this->assertArrayHasKey('/admin/manage', $routes);
        $this->assertSame('admin', $routes['/admin/manage'][0]);
    }

    /**
     * Test that get_routes sorts by path length descending (longest first).
     */
    public function testGetRoutesSortsByLengthDescending(): void
    {
        $this->loader->add_route_requirement('client', 'a', '', '/a');
        $this->loader->add_route_requirement('client', 'ab', '', '/ab');
        $this->loader->add_route_requirement('client', 'abc', '', '/abc');

        $routes = $this->loader->get_routes();
        $keys = array_keys($routes);

        $this->assertSame('/abc', $keys[0]);
        $this->assertSame('/ab', $keys[1]);
        $this->assertSame('/a', $keys[2]);
    }

    /**
     * Test that get_routes sorts same-length paths in reverse alphabetical order.
     */
    public function testGetRoutesSortsSameLengthReverseAlpha(): void
    {
        $this->loader->add_route_requirement('client', 'aa', '', '/aa');
        $this->loader->add_route_requirement('client', 'zz', '', '/zz');
        $this->loader->add_route_requirement('client', 'mm', '', '/mm');

        $routes = $this->loader->get_routes();
        $keys = array_keys($routes);

        $this->assertSame('/zz', $keys[0]);
        $this->assertSame('/mm', $keys[1]);
        $this->assertSame('/aa', $keys[2]);
    }

    /**
     * Test that overwriting a requirement replaces the previous entry.
     */
    public function testAddRequirementOverwritesPrevious(): void
    {
        $this->loader->add_requirement('fn', 'old.php');
        $this->loader->add_requirement('fn', 'new.php');

        $this->assertSame('new.php', $this->loader->get_requirements()['fn']);
    }

    /**
     * Test that overwriting a route replaces the previous entry.
     */
    public function testAddRouteRequirementOverwritesPrevious(): void
    {
        $this->loader->add_route_requirement('client', 'fn1', '', '/path');
        $this->loader->add_route_requirement('admin', 'fn2', '', '/path');

        $routes = $this->loader->get_routes();
        $this->assertSame('admin', $routes['/path'][0]);
        $this->assertSame('fn2', $routes['/path'][1]);
    }
}

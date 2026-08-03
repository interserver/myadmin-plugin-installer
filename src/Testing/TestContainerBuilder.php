<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing;

/**
 * Harness-side replacement for core's
 * `\MyAdmin\App\Testing\TestContainerBuilder`.
 *
 * ## Why this has to exist here
 *
 * Plan §0.5 recorded that `myadmin-vps-module`'s App-container block is **dead
 * code standalone**, because `class_exists(\MyAdmin\App\Testing\TestContainerBuilder::class)`
 * is false outside the core tree. That is not limited to vps-module:
 * `myadmin-virtuozzo-vps` calls it unguarded in `setUpGlobalTfStub()`, so three
 * of its tests fatal with `Class "MyAdmin\App\Testing\TestContainerBuilder"
 * not found` the moment the earlier `\MyAdmin\App` failure is fixed.
 *
 * Those three are the *most valuable* tests in that repo — they install a `tf`
 * whose `get_service_define()` returns a sentinel so that **no** service type
 * matches, then assert the handler stays inert. That is exactly the
 * propagation bug class Phase 3 targets, already written and never once
 * executed.
 *
 * `Bootstrap::init()` aliases this class into
 * `\MyAdmin\App\Testing\TestContainerBuilder`, so those tests run unchanged.
 *
 * ## Deliberate divergence from core
 *
 * Core's `with*()` methods are type-hinted against
 * `MyAdmin\App\Contracts\*` and `Psr\...` interfaces. This one is **untyped**:
 * the installer does not require `detain/myadmin-contracts`, and a type hint
 * against an interface that is not loadable fatals at call time. The builder
 * is a test fixture, so the safety a hint would buy is small and the breakage
 * it causes is total.
 */
class TestContainerBuilder
{
    /**
     * id => service.
     *
     * @var array<string,mixed>
     */
    private $services = [];

    /**
     * @return self
     */
    public static function make()
    {
        return new self();
    }

    /**
     * @param string $id
     * @param mixed  $service
     * @return self
     */
    public function with($id, $service)
    {
        $this->services[$id] = $service;
        return $this;
    }

    /**
     * The legacy `tf` object. Core keys it by `MyAdmin\tf::class`; the string
     * is used directly so the class does not have to be loadable.
     *
     * @param object $tf
     * @return self
     */
    public function withTf($tf)
    {
        return $this->with('MyAdmin\tf', $tf);
    }

    /**
     * @param object $db
     * @return self
     */
    public function withDb($db)
    {
        return $this->with('MyAdmin\App\Contracts\DatabaseInterface', $db);
    }

    /**
     * @param object $session
     * @return self
     */
    public function withSession($session)
    {
        return $this->with('MyAdmin\App\Contracts\SessionInterface', $session);
    }

    /**
     * @param object $accounts
     * @return self
     */
    public function withAccounts($accounts)
    {
        return $this->with('MyAdmin\App\Contracts\AccountsInterface', $accounts);
    }

    /**
     * @param object $request
     * @return self
     */
    public function withRequest($request)
    {
        return $this->with('MyAdmin\App\Contracts\RequestContextInterface', $request);
    }

    /**
     * @param object $events
     * @return self
     */
    public function withEvents($events)
    {
        return $this->with('Psr\EventDispatcher\EventDispatcherInterface', $events);
    }

    /**
     * @param object $variables
     * @return self
     */
    public function withVariables($variables)
    {
        return $this->with('MyAdmin\Variables', $variables);
    }

    /**
     * @param object $output
     * @return self
     */
    public function withOutput($output)
    {
        return $this->with('MyAdmin\App\Output', $output);
    }

    /**
     * @param object $adapter
     * @return self
     */
    public function withRateLimit($adapter)
    {
        return $this->with('MyAdmin\RateLimitAdapter', $adapter);
    }

    /**
     * @return \MyAdmin\Plugins\Testing\TestContainer
     */
    public function build()
    {
        return new TestContainer($this->services);
    }
}

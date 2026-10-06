<?php

declare(strict_types=1);

namespace Nvl\Support\Globals;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use Nvl\Support\Config\PackageConfiguration;
use Nvl\Support\Doctor\DoctorCheck;

/** Registers explicitly selected legacy global names without replacing host registrations. */
final class GlobalNames
{
    /** @var array<string, true> Registrations owned by this application boot. */
    private array $owned = [];

    /** @var array<int, string> Canonical route objects registered during this boot. */
    private array $ownedRoutes = [];

    /** Retain configuration and persist diagnostic declarations through config caching. */
    public function __construct(private readonly Repository $config) {}

    /** Determine whether the host selected one package's compatibility group. */
    public function enabled(string $package, string $group = 'global_aliases'): bool
    {
        if (! in_array($group, ['global_aliases', 'legacy_routes'], true)) {
            throw new InvalidArgumentException('Unknown global compatibility group.');
        }
        $selected = $this->config->get('nvl-core.compatibility.'.$group, []);
        if (! is_array($selected) || ! array_is_list($selected)) {
            throw new InvalidArgumentException("nvl-core.compatibility.{$group} must be a list of package names.");
        }
        foreach ($selected as $name) {
            if (! is_string($name) || $name === '' || PackageConfiguration::logical($name) !== $name) {
                throw new InvalidArgumentException("nvl-core.compatibility.{$group} must contain logical package names.");
            }
        }

        return in_array(PackageConfiguration::logical($package), $selected, true);
    }

    /**
     * Register a canonical name when the host has not reserved it.
     *
     * @param  callable(string): bool  $exists
     * @param  callable(string): void  $install
     */
    public function reserve(string $package, string $surface, string $name, callable $exists, callable $install, bool $append = false): bool
    {
        $key = $package.'.'.$surface.'.'.$name;
        if (isset($this->owned[$key])) {
            if ($append) {
                $install($name);
            }

            return true;
        }
        if ($exists($name)) {
            $this->record($key, "Global name collision for [{$name}] owned by [nvl/{$package}]; the host registration was preserved.");

            return false;
        }
        $install($name);
        $this->owned[$key] = true;

        return true;
    }

    /**
     * Register an old alias only for explicitly selected packages.
     *
     * @param  callable(string): bool  $exists
     * @param  callable(string): void  $install
     */
    public function register(string $package, string $surface, string $legacy, string $canonical, callable $exists, callable $install): bool
    {
        if (($surface === 'publish' && $legacy === 'config') || ! $this->enabled($package)) {
            return false;
        }
        $registered = $this->reserve($package, $surface, $legacy, $exists, $install);
        if ($registered) {
            $this->record($package.'.'.$surface.'.'.$legacy,
                "Legacy global name [{$legacy}] is active for [nvl/{$package}]; use [{$canonical}]. This compatibility alias is removed in the next major.");
        }

        return $registered;
    }

    /** Register canonical translation hints and explicitly selected legacy hints. */
    public function translations(string $package, string $path, Loader $loader): void
    {
        $canonical = 'nvl-'.PackageConfiguration::logical($package);
        $exists = static fn (string $namespace): bool => array_key_exists($namespace, $loader->namespaces());
        $install = static fn (string $namespace) => $loader->addNamespace($namespace, $path);
        $this->reserve($package, 'translations', $canonical, $exists, $install);
        $this->register($package, 'translations', $package, $canonical, $exists, $install);
    }

    /** Preserve host canonical route names and method paths while loading a package file. */
    public function loadRoutes(string $package, Router $router, callable $load): void
    {
        $before = $router->getRoutes()->getRoutes();
        $load();
        $after = $router->getRoutes()->getRoutes();
        $accepted = [];
        foreach ($after as $candidate) {
            if (in_array($candidate, $before, true)) {
                continue;
            }
            $collision = false;
            foreach ($before as $existing) {
                if (($this->ownedRoutes[spl_object_id($existing)] ?? null) === $package) {
                    continue;
                }
                if (($candidate->getName() !== null && $candidate->getName() === $existing->getName())
                    || ($candidate->uri() === $existing->uri() && $candidate->getDomain() === $existing->getDomain()
                        && array_intersect($this->methods($candidate), $this->methods($existing)) !== [])) {
                    $collision = true;
                    break;
                }
            }
            if ($collision) {
                $this->record($package.'.route.canonical.'.($candidate->getName() ?? hash('sha256', $candidate->uri())),
                    "Canonical route collision for [{$candidate->getName()}] at [{$candidate->uri()}]; the host route was preserved.");

                continue;
            }
            $accepted[] = $candidate;
            $this->ownedRoutes[spl_object_id($candidate)] = $package;
        }
        $routes = new RouteCollection;
        foreach ([...$before, ...$accepted] as $route) {
            $routes->add($route);
        }
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
        $router->setRoutes($routes);
    }

    /** @return list<string> Validate native route method metadata. */
    private function methods(Route $route): array
    {
        $methods = [];
        foreach ($route->methods() as $method) {
            if (! is_string($method)) {
                throw new InvalidArgumentException('Route methods must be strings.');
            }
            $methods[] = $method;
        }

        return $methods;
    }

    /** @return array<string, mixed> Validate native route action metadata. */
    private function action(Route $route): array
    {
        $action = $route->getAction();
        if (! is_array($action)) {
            throw new InvalidArgumentException('Route action metadata must be a map.');
        }
        $named = [];
        foreach ($action as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Route action metadata must use named keys.');
            }
            $named[$key] = $value;
        }

        return $named;
    }

    /**
     * Clone canonical handlers and middleware at bounded legacy paths, including signed URLs.
     *
     * @param  array<string, array{uri: string, name: string}>  $mappings  Canonical route name => old URI/name
     */
    public function routes(string $package, Router $router, array $mappings): int
    {
        if (! $this->enabled($package, 'legacy_routes')) {
            return 0;
        }
        $collection = $router->getRoutes();
        $collection->refreshNameLookups();
        $count = 0;
        foreach ($mappings as $canonical => $mapping) {
            $route = $collection->getByName($canonical);
            if ($route === null) {
                continue;
            }
            $key = $package.'.route.'.$mapping['name'];
            if (isset($this->owned[$key])) {
                continue;
            }
            $collision = $collection->getByName($mapping['name']) !== null;
            foreach ($collection->getRoutes() as $existing) {
                if ($existing->uri() === trim($mapping['uri'], '/')
                    && $existing->getDomain() === $route->getDomain()
                    && array_intersect($this->methods($existing), $this->methods($route)) !== []) {
                    $collision = true;
                }
            }
            if ($collision) {
                $this->record($key, "Legacy route collision for [{$mapping['name']}] at [{$mapping['uri']}]; the host route was preserved.");

                continue;
            }
            $action = $this->action($route);
            unset($action['prefix']);
            $action['as'] = $mapping['name'];
            $legacy = $router->newRoute($this->methods($route), trim($mapping['uri'], '/'), $action);
            $legacy->setBindingFields($route->bindingFields());
            $legacy->where($route->wheres);
            $legacy->defaults = $route->defaults;
            $legacy->isFallback = $route->isFallback;
            $collection->add($legacy);
            $this->owned[$key] = true;
            $this->record($key, "Legacy route [{$mapping['name']}] is active at [{$mapping['uri']}]; rebuild links using [{$canonical}] before the next major and regenerate the route cache.");
            $count++;
        }
        $collection->refreshNameLookups();
        $collection->refreshActionLookups();

        return $count;
    }

    /** Install selected legacy route families after all providers load canonical routes. */
    public function bootRoutes(Application $app): void
    {
        if ($app->routesAreCached()) {
            return;
        }
        $router = $app->make(Router::class);
        $router->getRoutes()->refreshNameLookups();
        foreach ($this->routeFamilies() as $family) {
            if (! $this->enabled($family['package'], 'legacy_routes')) {
                continue;
            }
            $mappings = [];
            foreach ($router->getRoutes()->getRoutes() as $route) {
                $name = $route->getName();
                $uri = $route->uri();
                $prefix = $family['canonical'];
                if (! is_string($name) || ! str_starts_with($name, $family['name_prefix'])
                    || ($uri !== $prefix && ! str_starts_with($uri, $prefix.'/'))) {
                    continue;
                }
                $suffix = substr($name, strlen($family['name_prefix']));
                $oldName = $family['legacy_name_prefix'] === $family['name_prefix']
                    ? $family['name_prefix'].'legacy.'.$suffix : $family['legacy_name_prefix'].$suffix;
                $mappings[$name] = [
                    'uri' => trim($family['current'].substr($uri, strlen($prefix)), '/'),
                    'name' => $oldName,
                ];
            }
            $this->routes($family['package'], $router, $mappings);
        }
    }

    /** @return list<array{package: string, current: string, canonical: string, name_prefix: string, legacy_name_prefix: string}> */
    private function routeFamilies(): array
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/global-names.json');
        if (! is_string($source)) {
            throw new InvalidArgumentException('Global name inventory is unavailable.');
        }
        $inventory = json_decode($source, true, flags: JSON_THROW_ON_ERROR);
        $families = is_array($inventory) ? ($inventory['route_families'] ?? null) : null;
        if (! is_array($families) || ! array_is_list($families)) {
            throw new InvalidArgumentException('Global route inventory must contain a list of route families.');
        }
        $validated = [];
        foreach ($families as $family) {
            if (! is_array($family) || ! is_string($family['package'] ?? null)
                || ! is_string($family['current'] ?? null) || ! is_string($family['canonical'] ?? null)
                || ! is_string($family['name_prefix'] ?? null) || ! is_string($family['legacy_name_prefix'] ?? null)) {
                throw new InvalidArgumentException('Global route family declarations must contain named string fields.');
            }
            $validated[] = ['package' => $family['package'], 'current' => $family['current'],
                'canonical' => $family['canonical'], 'name_prefix' => $family['name_prefix'],
                'legacy_name_prefix' => $family['legacy_name_prefix']];
        }

        return $validated;
    }

    /** @return list<DoctorCheck> Read persisted compatibility and collision diagnostics. */
    public function diagnostics(): array
    {
        $this->enabled('core');
        $this->enabled('core', 'legacy_routes');
        $messages = $this->config->get('nvl-core.configuration.global_names', []);
        if (! is_array($messages)) {
            throw new InvalidArgumentException('Global names diagnostic declarations must be a map.');
        }
        ksort($messages, SORT_STRING);
        $checks = [];
        foreach ($messages as $key => $message) {
            if (is_string($key) && is_string($message)) {
                $checks[] = new DoctorCheck('globals.'.$key, 'warning', false, $message);
            }
        }
        foreach (['global_aliases', 'legacy_routes'] as $group) {
            $selected = $this->config->get('nvl-core.compatibility.'.$group, []);
            if (! is_array($selected)) {
                throw new InvalidArgumentException('Global compatibility selections must be package lists.');
            }
            foreach ($selected as $package) {
                if (! is_string($package)) {
                    throw new InvalidArgumentException('Global compatibility selections must contain package names.');
                }
                $checks[] = new DoctorCheck('globals.compatibility.'.$group.'.'.$package, 'warning', false,
                    "Legacy compatibility [{$group}] is selected for [nvl/{$package}]; remove this selection before major 6.");
            }
        }

        return $checks;
    }

    /** Store only names and ownership, never host values. */
    private function record(string $key, string $message): void
    {
        $messages = $this->config->get('nvl-core.configuration.global_names', []);
        $messages = is_array($messages) ? $messages : [];
        $messages[$key] = $message;
        $this->config->set('nvl-core.configuration.global_names', $messages);
    }
}

<?php

declare(strict_types=1);

namespace Nvl\Support\Bindings;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionProperty;

/** Read-only capability inspection against native container registration metadata.
 * @api
 */
final class RequiredBindings
{
    /** @var array<string, RequiredBindingDefinition> */
    private array $definitions = [];

    public function __construct(private readonly Container $container, private readonly Repository $config) {}

    /** Register an idempotent declaration, rejecting conflicting ownership. */
    public function register(RequiredBindingDefinition $definition): void
    {
        $key = $definition->package.':'.$definition->contract;
        $existing = $this->definitions[$key] ?? null;
        if ($existing !== null && $existing != $definition) {
            throw new InvalidArgumentException("Conflicting required binding definition [{$key}].");
        }
        $this->definitions[$key] = $definition;
    }

    /** @return list<RequiredBindingDefinition> */
    public function all(?string $package = null): array
    {
        $definitions = $this->definitions;
        ksort($definitions, SORT_STRING);

        return array_values(array_filter($definitions, static fn (RequiredBindingDefinition $definition): bool => $package === null || $definition->package === $package));
    }

    /** @return list<RequiredBindingStatus> */
    public function inspect(): array
    {
        $statuses = [];
        foreach ($this->all() as $definition) {
            $enabled = $definition->enabledWhen === null || $this->config->get($definition->enabledWhen) === true;
            $status = ! $enabled ? 'inactive' : ($this->isPlaceholder($definition) ? 'missing' : 'configured');
            $message = match ($status) {
                'inactive' => "Capability [{$definition->capability}] is inactive.",
                'missing' => "Capability [{$definition->capability}] requires host binding [{$definition->contract}].",
                default => "Host binding [{$definition->contract}] is declared; adapter behavior has not been executed.",
            };
            $statuses[] = new RequiredBindingStatus($definition->package, $definition->contract, $definition->capability, $status, $message, $definition->documentation);
        }

        return $statuses;
    }

    /** Inspect native instances and generated concrete-binding closures without invoking them. */
    private function isPlaceholder(RequiredBindingDefinition $definition): bool
    {
        $abstract = $this->container->getAlias($definition->contract);
        if (! $this->container->bound($abstract)) {
            return true;
        }

        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($this->container);
        if (is_array($instances) && array_key_exists($abstract, $instances)) {
            $placeholder = $definition->placeholder;

            return $instances[$abstract] instanceof $placeholder;
        }

        $binding = $this->container->getBindings()[$abstract] ?? null;
        $concrete = is_array($binding) ? ($binding['concrete'] ?? null) : null;
        if (is_string($concrete)) {
            return $concrete === $definition->placeholder;
        }
        if ($concrete instanceof Closure) {
            $reflection = new ReflectionFunction($concrete);
            if ($reflection->getClosureScopeClass()?->getName() === Container::class) {
                return ($reflection->getStaticVariables()['concrete'] ?? null) === $definition->placeholder;
            }
        }

        return false;
    }
}

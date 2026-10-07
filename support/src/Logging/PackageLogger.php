<?php

declare(strict_types=1);

namespace Nvl\Support\Logging;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Log\LogManager;
use InvalidArgumentException;
use Throwable;

/** Routes bounded package diagnostics without retaining request, tenant, or job context.
 *
 * @api
 */
final readonly class PackageLogger
{
    private const array Verbosity = ['quiet' => 0, 'normal' => 1, 'verbose' => 2];

    private const array Levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * Retain explicit infrastructure or resolve the current host logger for each call.
     *
     * @param  LogManager|Closure(): LogManager  $logs
     */
    public function __construct(private Repository $config, private LogManager|Closure $logs) {}

    /**
     * Write one stable diagnostic with bounded scalar context.
     *
     * @param  array<array-key, mixed>  $context
     */
    public function log(string $package, string $level, string $key, array $context = [], string $verbosity = 'normal'): void
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/D', $package) !== 1
            || preg_match('/^nvl\.'.preg_quote($package, '/').'\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/D', $key) !== 1
            || ! in_array($level, self::Levels, true)
            || ! isset(self::Verbosity[$verbosity])) {
            throw new InvalidArgumentException('Package logs require a package, supported level/verbosity and stable nvl.package.operation.result key.');
        }

        [$channel, $policy] = $this->policy($package);
        if (in_array($level, ['debug', 'info', 'notice'], true)
            && self::Verbosity[$verbosity] > self::Verbosity[$policy]) {
            return;
        }
        $channels = $this->channels();
        $this->assertChannel($channel, $channels);
        if (! $this->config->has('logging.channels.nvl')) {
            $this->config->set('logging.channels.nvl', $channels['nvl']);
        }
        ($this->logs instanceof Closure ? ($this->logs)() : $this->logs)->channel($channel)->log($level, $key, [
            ...$this->safeContext($context),
            'package' => $package,
            'message_key' => $key,
        ]);
    }

    /** Inspect policy without resolving channels or writing logs.
     *
     * @return list<string>
     */
    public function diagnostics(): array
    {
        $errors = [];
        try {
            $channels = $this->channels();
            $packages = $this->config->get('nvl-core.logging.packages', []);
            if (! is_array($packages)) {
                throw new InvalidArgumentException('nvl-core.logging.packages must be a package map.');
            }
            [$defaultChannel] = $this->validatePolicy(
                $this->config->get('nvl-core.logging.channel', 'nvl'),
                $this->config->get('nvl-core.logging.verbosity', 'normal'),
            );
            $this->assertChannel($defaultChannel, $channels);
            foreach (array_keys($packages) as $package) {
                if (! is_string($package) || preg_match('/^[a-z][a-z0-9-]*$/D', $package) !== 1) {
                    $errors[] = 'Logging package overrides require logical package names.';

                    continue;
                }
                try {
                    [$channel] = $this->policy($package);
                    $this->assertChannel($channel, $channels);
                } catch (InvalidArgumentException $exception) {
                    $errors[] = $exception->getMessage();
                }
            }
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }

        return array_values(array_unique($errors));
    }

    /** @return array{string, string} */
    private function policy(string $package): array
    {
        $overrides = $this->config->get('nvl-core.logging.packages', []);
        if (! is_array($overrides) || (isset($overrides[$package]) && ! is_array($overrides[$package]))) {
            throw new InvalidArgumentException('Package logging overrides must be maps.');
        }
        $override = $overrides[$package] ?? [];
        $channel = $override['channel'] ?? $this->config->get('nvl-core.logging.channel', 'nvl');
        $verbosity = $override['verbosity'] ?? $this->config->get('nvl-core.logging.verbosity', 'normal');

        return $this->validatePolicy($channel, $verbosity);
    }

    /** @return array{string, string} */
    private function validatePolicy(mixed $channel, mixed $verbosity): array
    {
        if (! is_string($channel) || $channel === '') {
            throw new InvalidArgumentException('Package logging channel must be a nonempty configured channel name.');
        }
        if (! is_string($verbosity) || ! isset(self::Verbosity[$verbosity])) {
            throw new InvalidArgumentException('Package logging verbosity must be quiet, normal, or verbose.');
        }

        return [$channel, $verbosity];
    }

    /** Build a virtual fallback for diagnostics; the host configuration is unchanged here.
     *
     * @return array<array-key, mixed>
     */
    private function channels(): array
    {
        $channels = $this->config->get('logging.channels', []);
        if (! is_array($channels)) {
            throw new InvalidArgumentException('logging.channels must contain named channel configurations.');
        }
        if (! array_key_exists('nvl', $channels)) {
            $default = $this->config->get('logging.default');
            if (! is_string($default) || $default === '' || $default === 'nvl') {
                throw new InvalidArgumentException('Define logging.channels.nvl or choose a non-nvl host default before using the package fallback.');
            }
            $channels['nvl'] = ['driver' => 'stack', 'channels' => [$default], 'ignore_exceptions' => false];
        }

        return $channels;
    }

    /** Reject missing channels and direct/indirect stack cycles without invoking custom drivers.
     *
     * @param  array<array-key, mixed>  $channels
     * @param  list<string>  $path
     */
    private function assertChannel(string $channel, array $channels, array $path = []): void
    {
        if (in_array($channel, $path, true)) {
            throw new InvalidArgumentException('Package logging channel stack contains a cycle.');
        }
        $definition = $channels[$channel] ?? null;
        if (! is_array($definition) || ! is_string($definition['driver'] ?? null) || $definition['driver'] === '') {
            throw new InvalidArgumentException('Package logging references a missing or invalid channel.');
        }
        if ($definition['driver'] !== 'stack') {
            return;
        }
        $members = $definition['channels'] ?? null;
        if (! is_array($members) || $members === []) {
            throw new InvalidArgumentException('Package logging stacks require configured member channels.');
        }
        foreach ($members as $member) {
            if (! is_string($member) || $member === '') {
                throw new InvalidArgumentException('Package logging stack members must be channel names.');
            }
            $this->assertChannel($member, $channels, [...$path, $channel]);
        }
    }

    /** Retain diagnostics only; omit content, paths, credentials and exception text.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<string, bool|float|int|string|null>
     */
    private function safeContext(array $context): array
    {
        $safe = [];
        foreach (array_slice($context, 0, 32, true) as $key => $value) {
            if (! is_string($key) || preg_match('/(?:password|secret|token|credential|authorization|cookie|email|name|url|path|payload|data|body|trace|message|error|checksum|recipient|reason|source|output|host)/i', $key) === 1) {
                continue;
            }
            if ($value instanceof Throwable) {
                $value = $value::class;
            }
            if ($value === null || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
                $safe[$key] = $value;
            } elseif (is_string($value)) {
                $safe[$key] = substr($value, 0, 191);
            }
        }

        return $safe;
    }
}

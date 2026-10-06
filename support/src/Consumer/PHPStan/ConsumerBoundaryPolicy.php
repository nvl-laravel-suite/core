<?php

declare(strict_types=1);

namespace Nvl\Support\Consumer\PHPStan;

use Composer\InstalledVersions;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nvl\Support\Consumer\ConsumerApiCatalog;
use Nvl\Support\Consumer\ConsumerModelPolicy;
use Nvl\Support\Consumer\ConsumerSymbol;
use PhpParser\Node;
use PHPStan\Analyser\ResultCache\ResultCacheMetaExtension;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\LineRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use RuntimeException;

/** Shares installed ownership, receiver classification and exact exceptions between opt-in analysis rules. */
final class ConsumerBoundaryPolicy implements ResultCacheMetaExtension
{
    private ConsumerApiCatalog $catalog;

    /** @var list<string> */
    private array $testRoots = [];

    /** @var list<array{file:string,identifier:string,symbol:string,reason:string}> */
    private array $normalizedExceptions = [];

    /**
     * Validate host options against actual installed metadata without application boot.
     *
     * @param  list<string>  $testPaths
     * @param  array<string,string>  $tableNames
     * @param  list<array{file:string,identifier:string,symbol:string,reason:string}>  $exceptions
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private string $basePath,
        array $testPaths = [],
        private array $tableNames = [],
        array $exceptions = [],
    ) {
        $this->catalog = ConsumerApiCatalog::installed();
        foreach ($testPaths as $path) {
            $this->testRoots[] = $this->canonicalPath($path, true);
        }
        sort($this->testRoots);
        foreach ($tableNames as $table => $package) {
            if ($table === '' || trim($table) !== $table || ! isset($this->catalog->installedRoots()[$package])) {
                throw new InvalidArgumentException('nvlConsumer.tableNames requires exact tables and installed package identities.');
            }
            $owner = $this->catalog->tableOwner($table);
            if ($owner !== null && $owner !== $package) {
                throw new InvalidArgumentException('nvlConsumer.tableNames cannot change installed table ownership.');
            }
        }
        ksort($this->tableNames);
        foreach ($exceptions as $exception) {
            foreach ($exception as $value) {
                if (trim($value) === '') {
                    throw new InvalidArgumentException('Consumer exceptions require nonempty strings and a reason.');
                }
            }
            if (! in_array($exception['identifier'], self::identifiers(), true)
                || strpbrk($exception['symbol'], '*?[]{}#~%') !== false) {
                throw new InvalidArgumentException('Consumer exceptions require an exact symbol and known identifier.');
            }
            $exception['file'] = $this->canonicalPath($exception['file'], false);
            $exception['reason'] = trim($exception['reason']);
            if ($exception['identifier'] === 'nvl.consumer.ownedTable') {
                if (! $this->ownsTable($exception['symbol'])) {
                    throw new InvalidArgumentException('Consumer table exception must name an installed owned table.');
                }
            } else {
                $class = explode('::', $exception['symbol'], 2)[0];
                if ($this->ownedClass($class) === null) {
                    throw new InvalidArgumentException('Consumer exception must name an installed owned class or member.');
                }
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*(?:::\\$?[A-Za-z_][A-Za-z0-9_]*)?$/D', $exception['symbol']) !== 1) {
                    throw new InvalidArgumentException('Consumer exception symbol must be an exact class, method, property or constant.');
                }
            }
            $this->validateExceptionSymbol($exception['identifier'], $exception['symbol']);
            $this->normalizedExceptions[] = $exception;
        }
        usort($this->normalizedExceptions, static fn (array $a, array $b): int => $a <=> $b);
    }

    /** Return the stable result-cache metadata namespace. */
    public function getKey(): string
    {
        return 'nvl.consumer.boundary';
    }

    /** Hash fresh validated catalog bytes, ordered ownership roots, identities and normalized host options. */
    public function getHash(): string
    {
        $fresh = ConsumerApiCatalog::installed();
        $packages = $fresh->installedRoots();
        ksort($packages);
        $inputs = [];
        foreach ($packages as $package => $roots) {
            $path = $roots['path'].($package === 'nvl/core' ? '/support' : '').'/resources/consumer-api.json';
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('Cannot read installed consumer catalog '.$path);
            }
            $inputs[$package] = [$roots, InstalledVersions::getVersion($package), InstalledVersions::getReference($package), hash('sha256', $bytes)];
        }

        return hash('sha256', serialize([$inputs, $this->basePath, $this->testRoots, $this->tableNames, $this->normalizedExceptions]));
    }

    /** Identify actual installed production sources, independently of namespace spelling. */
    public function excludes(Scope $scope): bool
    {
        return $this->catalog->packageForFile($scope->getFile()) !== null;
    }

    /** Return the installed owner of the class's defining source. */
    public function ownedClass(string $class): ?string
    {
        $selected = $this->catalog->symbol($class);
        if ($selected !== null) {
            return $selected->package;
        }
        if (! $this->reflectionProvider->hasClass($class)) {
            return null;
        }
        $file = $this->reflectionProvider->getClass($class)->getFileName();

        return $file !== null ? $this->catalog->packageForFile($file) : null;
    }

    /** Return the exact selected public declaration. */
    public function symbol(string $class): ?ConsumerSymbol
    {
        $symbol = $this->catalog->symbol($class);
        if ($symbol !== null || ! $this->reflectionProvider->hasClass($class)) {
            return $symbol;
        }

        return $this->catalog->symbol($this->reflectionProvider->getClass($class)->getName());
    }

    /** Return a reflected class when PHPStan has source evidence. */
    public function reflection(string $class): ?ClassReflection
    {
        return $this->reflectionProvider->hasClass($class) ? $this->reflectionProvider->getClass($class) : null;
    }

    /**
     * Inspect each known constituent, preserving prohibited members of unions.
     *
     * @return list<Type>
     */
    public function constituents(Type $type): array
    {
        if ($type instanceof UnionType) {
            $types = [];
            foreach ($type->getTypes() as $part) {
                array_push($types, ...$this->constituents($part));
            }

            return $types;
        }
        if ($type->isClassString()->yes()) {
            return $this->constituents($type->getClassStringObjectType());
        }

        return [$type];
    }

    /**
     * Resolve model handles and named generic item/receiver types.
     *
     * @return array<string,ConsumerModelPolicy>
     */
    public function models(Type $type, bool $containers = true): array
    {
        $models = [];
        foreach ($this->constituents($type) as $part) {
            foreach ($part->getObjectClassReflections() as $reflection) {
                foreach ($reflection->getAncestors() as $ancestor) {
                    $policy = $this->catalog->model($ancestor->getName());
                    if ($policy !== null) {
                        $models[$policy->class] = $policy;
                        break;
                    }
                }
            }
            if (! $containers) {
                continue;
            }
            if ($part->isArray()->yes()) {
                $models += $this->models($part->getIterableValueType());
            }
            $receivers = [
                Builder::class => $part->getTemplateType(Builder::class, 'TModel'),
                Relation::class => $part->getTemplateType(Relation::class, 'TRelatedModel'),
                Factory::class => $part->getTemplateType(Factory::class, 'TModel'),
                Collection::class => $part->getTemplateType(Collection::class, 'TValue'),
                Paginator::class => $part->getTemplateType(Paginator::class, 'TValue'),
                CursorPaginator::class => $part->getTemplateType(CursorPaginator::class, 'TValue'),
            ];
            foreach ($receivers as $ancestor => $receiver) {
                if ((new ObjectType($ancestor))->isSuperTypeOf($part)->yes()) {
                    $models += $this->models($receiver, false);
                }
            }
        }

        return $models;
    }

    /** Determine whether a receiver represents in-memory model containers. */
    public function isContainer(Type $type): bool
    {
        return (new ObjectType(Collection::class))->isSuperTypeOf($type)->yes()
            || (new ObjectType(Paginator::class))->isSuperTypeOf($type)->yes()
            || (new ObjectType(CursorPaginator::class))->isSuperTypeOf($type)->yes();
    }

    /** Determine whether a receiver carries a query or factory. */
    public function isQuery(Type $type): bool
    {
        return (new ObjectType(Builder::class))->isSuperTypeOf($type)->yes()
            || (new ObjectType(Relation::class))->isSuperTypeOf($type)->yes()
            || (new ObjectType(Factory::class))->isSuperTypeOf($type)->yes();
    }

    /** Preserve declared callable spelling while comparing PHP method names case-insensitively. */
    public function methodName(string $class, string $method): string
    {
        foreach ([$class, Builder::class, Relation::class, Factory::class, Collection::class] as $receiver) {
            $reflection = $this->reflection($receiver);
            if ($reflection !== null && $reflection->hasNativeMethod($method)) {
                return $reflection->getNativeMethod($method)->getName();
            }
        }

        return strtolower($method);
    }

    /** Classify persistence before query exceptions are considered. */
    public function writes(string $method): bool
    {
        return preg_match('/^(?:save|update|delete|destroy|restore|forceDelete|forceDestroy|rawUpdate|push|touch|insert|upsert|increment|decrement|create|firstOrCreate|updateOrCreate|forceCreate|fill|forceFill|setAttribute|offsetSet|offsetUnset|attach|detach|sync|toggle|truncate)/i', $method) === 1;
    }

    /** Return the exact owned table after the optional SQL alias. */
    public function table(string $table): ?string
    {
        $table = preg_split('/\s+as\s+/i', $table, 2)[0] ?? $table;

        return $this->ownsTable($table) ? $table : null;
    }

    /** Determine ownership from installed defaults plus explicit physical table overrides. */
    public function ownsTable(string $table): bool
    {
        return $this->catalog->tableOwner($table) !== null || isset($this->tableNames[$table]);
    }

    /** Check exact catalog members while permitting external inherited framework contracts. */
    public function allowsMember(string $class, string $member, string $kind): bool
    {
        $symbol = $this->symbol($class);
        if ($symbol === null) {
            return $this->ownedClass($class) === null;
        }
        $members = match ($kind) {
            'method' => $symbol->methods,
            'property' => $symbol->properties,
            default => $symbol->constants,
        };
        if ($kind === 'method') {
            if (in_array(strtolower($member), array_map('strtolower', $members), true)) {
                return true;
            }
            $reflection = $this->reflection($class);
            if ($reflection !== null && $reflection->hasNativeMethod($member)) {
                return $this->ownedClass($reflection->getNativeMethod($member)->getDeclaringClass()->getName()) === null;
            }

            return false;
        }

        return in_array($member, $members, true);
    }

    /**
     * Build a single classified finding after narrow test-path and exact exception matching.
     *
     * @return list<IdentifierRuleError&LineRuleError>
     */
    public function error(string $identifier, string $symbol, Node $node, Scope $scope): array
    {
        if ($this->excludes($scope)) {
            return [];
        }
        $identifier = 'nvl.consumer.'.$identifier;
        $file = realpath($scope->getFile());
        if (in_array($identifier, ['nvl.consumer.packageQuery', 'nvl.consumer.packageWrite'], true) && $file !== false) {
            foreach ($this->testRoots as $root) {
                if ($file === $root || str_starts_with($file, $root.'/')) {
                    return [];
                }
            }
        }
        foreach ($this->normalizedExceptions as $exception) {
            if ($exception['file'] === $file && $exception['identifier'] === $identifier && $exception['symbol'] === $symbol) {
                return [];
            }
        }

        return [RuleErrorBuilder::message($symbol.' is outside the supported NVL consumer boundary.')->identifier($identifier)->line($node->getStartLine())->build()];
    }

    /**
     * Return declared capability names for a composed trait.
     *
     * @return list<string>
     */
    public function capabilityRelations(string $trait): array
    {
        return $this->catalog->capabilityRelations($trait);
    }

    /** Validate concrete diagnostic members without invoking Laravel or host model constructors. */
    private function validateExceptionSymbol(string $identifier, string $symbol): void
    {
        if ($identifier === 'nvl.consumer.ownedTable') {
            return;
        }
        $parts = explode('::', $symbol, 2);
        $class = $parts[0];
        $member = $parts[1] ?? null;
        $reflection = $this->reflection($class);
        if ($reflection === null) {
            throw new InvalidArgumentException('Consumer exception requires a reflected installed symbol.');
        }
        if ($identifier === 'nvl.consumer.capabilityRelation') {
            if ($member !== null && $reflection->isTrait() && $this->catalog->capabilityRelations($class) !== []) {
                return;
            }
            throw new InvalidArgumentException('Consumer capability exception requires an installed capability trait member.');
        }
        if ($member === null) {
            if ($identifier === 'nvl.consumer.internalApi') {
                return;
            }
            throw new InvalidArgumentException('Consumer model exception requires an exact member.');
        }
        $property = str_starts_with($member, '$');
        if ($identifier === 'nvl.consumer.internalApi') {
            $exists = $property ? $this->sourcePropertyExists($reflection, substr($member, 1)) : ($reflection->hasNativeMethod($member) || $reflection->hasConstant($member));
        } else {
            if ($this->catalog->model($reflection->getName()) === null) {
                throw new InvalidArgumentException('Consumer query/write exception requires a catalogued model.');
            }
            $exists = $property && $this->sourcePropertyExists($reflection, substr($member, 1));
            if (! $property) {
                foreach ([$class, Builder::class, Relation::class, Factory::class, Collection::class] as $receiver) {
                    $exists = $exists || ($this->reflection($receiver)?->hasNativeMethod($member) ?? false);
                }
            }
            if (! $property && ($identifier === 'nvl.consumer.packageWrite') !== $this->writes($member)) {
                throw new InvalidArgumentException('Consumer exception identifier does not match its member kind.');
            }
        }
        if (! $exists) {
            throw new InvalidArgumentException('Consumer exception must name an existing source member.');
        }
    }

    /** Inspect declarations and resolved property tags without Larastan database-property extensions. */
    private function sourcePropertyExists(ClassReflection $reflection, string $property): bool
    {
        foreach ($reflection->getAncestors() as $ancestor) {
            if ($ancestor->hasNativeProperty($property) || isset($ancestor->getPropertyTags()[$property])) {
                return true;
            }
        }

        return false;
    }

    /** Normalize exact paths without granting symlink-based test escapes. */
    private function canonicalPath(string $path, bool $directory): string
    {
        if ($path === '' || trim($path) !== $path || strpbrk($path, '*?[]{}#~%') !== false || preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $path) === 1) {
            throw new InvalidArgumentException('Consumer paths must be exact existing paths without traversal or patterns.');
        }
        $absolute = str_starts_with($path, '/') ? $path : $this->basePath.'/'.$path;
        $canonical = realpath($absolute);
        if ($canonical === false || ($directory ? ! is_dir($canonical) : ! is_file($canonical))) {
            throw new InvalidArgumentException('Consumer path does not exist: '.$path);
        }

        return $canonical;
    }

    /**
     * Return the five stable diagnostic identifiers.
     *
     * @return list<string>
     */
    private static function identifiers(): array
    {
        return ['nvl.consumer.internalApi', 'nvl.consumer.packageQuery', 'nvl.consumer.packageWrite', 'nvl.consumer.capabilityRelation', 'nvl.consumer.ownedTable'];
    }
}

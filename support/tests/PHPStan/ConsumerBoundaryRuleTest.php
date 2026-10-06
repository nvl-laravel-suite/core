<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\PHPStan;

use Composer\Autoload\ClassLoader;
use Nvl\Support\Consumer\PHPStan\ConsumerBoundaryPolicy;
use Nvl\Support\Consumer\PHPStan\HostCapabilityRelationRule;
use Nvl\Support\Consumer\PHPStan\OwnedTableRule;
use Nvl\Support\Consumer\PHPStan\PackageMethodCallRule;
use Nvl\Support\Consumer\PHPStan\PackagePropertyRule;
use Nvl\Support\Consumer\PHPStan\PackageStaticCallRule;
use Nvl\Support\Consumer\PHPStan\SymbolUsageRule;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPStan\Type\FileTypeMapper;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionClass;
use RuntimeException;

/** Pest does not define the Composer bootstrap path needed by native PHPUnit workers. */
if (! defined('PHPUNIT_COMPOSER_INSTALL')) {
    $composerClassLoaderFile = (new ReflectionClass(ClassLoader::class))->getFileName();
    if (! is_string($composerClassLoaderFile) || ! is_readable(dirname($composerClassLoaderFile, 2).'/autoload.php')) {
        throw new RuntimeException('Cannot resolve the active Composer autoloader for isolated PHPStan tests.');
    }
    define('PHPUNIT_COMPOSER_INSTALL', dirname($composerClassLoaderFile, 2).'/autoload.php');
}

/**
 * Exercises the complete boundary against PHPStan's actual resolved nodes and types.
 *
 * @extends RuleTestCase<Rule<Node>>
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ConsumerBoundaryRuleTest extends RuleTestCase
{
    /** @var list<string> */
    private array $testPaths = [];

    /** @var list<array{file:string,identifier:string,symbol:string,reason:string}> */
    private array $exceptions = [];

    /** @var array<string,string> */
    private array $tableNames = [];

    /** Bootstrap PHPStan inside its isolated test process and remove only newly installed Laravel handlers. */
    protected function setUp(): void
    {
        ini_set('memory_limit', '1G');
        if (class_exists(ConsumerBoundaryPolicy::class)) {
            $errorHandler = set_error_handler(static fn (): bool => false);
            restore_error_handler();
            $exceptionHandler = set_exception_handler(static function (): void {});
            restore_exception_handler();
            try {
                parent::setUp();
                self::getContainer();
            } finally {
                $currentErrorHandler = set_error_handler(static fn (): bool => false);
                restore_error_handler();
                if ($currentErrorHandler !== $errorHandler) {
                    restore_error_handler();
                }
                $currentExceptionHandler = set_exception_handler(static function (): void {});
                restore_exception_handler();
                if ($currentExceptionHandler !== $exceptionHandler) {
                    restore_exception_handler();
                }
            }
        }
    }

    /** Return the explicit host analysis configuration. @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__.'/test.neon'];
    }

    /** Assemble the shipped rules without relying on PHPStan's built-in analysis level. @return Rule<Node> */
    protected function getRule(): Rule
    {
        $rules = [];
        $policy = new ConsumerBoundaryPolicy(self::createReflectionProvider(), dirname(__DIR__, 6), $this->testPaths, $this->tableNames, $this->exceptions);
        foreach ([SymbolUsageRule::class, PackageStaticCallRule::class, PackageMethodCallRule::class, PackagePropertyRule::class, HostCapabilityRelationRule::class, OwnedTableRule::class] as $class) {
            $rules[] = $class === SymbolUsageRule::class
                ? new SymbolUsageRule($policy, self::getContainer()->getByType(FileTypeMapper::class), self::getContainer()->getByType(TypeStringResolver::class))
                : new $class($policy);
        }

        return new class($rules) implements Rule
        {
            /** @param list<Rule<Node>> $rules */
            public function __construct(private array $rules) {}

            public function getNodeType(): string
            {
                return Node::class;
            }

            public function processNode(Node $node, Scope $scope): array
            {
                $errors = [];
                foreach ($this->rules as $rule) {
                    if (is_a($node, $rule->getNodeType())) {
                        array_push($errors, ...$rule->processNode($node, $scope));
                    }
                }

                return $errors;
            }
        };
    }

    /** Exercise independently annotated prohibited operations and require no additional findings. */
    public function test_inferred_receivers_and_host_capability_relations(): void
    {
        $this->assertFixture('receivers.php.stub');
        $this->assertFixture('declarations.php.stub');
        $this->assertFixture('writes.php.stub');
        $this->assertFixture('aliases.php.stub');
        $this->assertFixture('adapter.php.stub');
        $this->assertFixture('conversions.php.stub');
        $this->assertFixture('transfer.php.stub');
        $this->assertFixture('host-forged-namespace.php.stub');
    }

    /** A reviewed C1 read predicate does not grant writes or query-builder escape operations. */
    public function test_exact_adapter_exception_retains_write_denials(): void
    {
        $this->exceptions = [[
            'file' => __DIR__.'/Fixtures/adapter.php.stub',
            'identifier' => 'nvl.consumer.packageQuery',
            'symbol' => 'Nvl\\Comments\\Models\\Comment::where',
            'reason' => 'Reviewed C1 SQL predicate in the supplied package builder.',
        ]];
        $this->assertFixture('adapter.php.stub');
        $this->assertFixture('host-forged-namespace.php.stub');
    }

    /** Explicit test roots grant model setup while internal API, relation and raw-table findings remain. */
    public function test_explicit_test_paths_keep_other_boundaries(): void
    {
        $this->testPaths = [__DIR__.'/Fixtures'];
        $this->assertFixture('receivers.php.stub');
        $this->assertFixture('declarations.php.stub');
        $this->assertFixture('transfer.php.stub');
    }

    /** Additional physical names never replace default ownership or depend on module enablement. */
    public function test_explicit_physical_table_overrides(): void
    {
        $this->tableNames = ['tenant_comments' => 'nvl/comments'];
        $this->assertFixture('tables.php.stub');
    }

    /** Exact valid but nonmatching file, symbol, and identifier cannot suppress a query. */
    public function test_nonmatching_exceptions(): void
    {
        foreach ([
            ['file' => __DIR__.'/Fixtures/allowed.php.stub'],
            ['symbol' => 'Nvl\\Comments\\Models\\Comment::refresh'],
            ['identifier' => 'nvl.consumer.packageWrite', 'symbol' => 'Nvl\\Comments\\Models\\Comment::save'],
        ] as $changes) {
            $this->exceptions = [array_replace([
                'file' => __DIR__.'/Fixtures/adapter.php.stub',
                'identifier' => 'nvl.consumer.packageQuery',
                'symbol' => 'Nvl\\Comments\\Models\\Comment::where',
                'reason' => 'Exact nonmatching review control.',
            ], $changes)];
            $this->assertFixture('adapter.php.stub');
        }
    }

    /** Reject impossible members and mismatched diagnostic kinds using source reflection only. */
    public function test_invalid_member_exceptions(): void
    {
        foreach ([
            ['packageQuery', 'Nvl\\Comments\\Models\\Comment::nonexistentMember'],
            ['packageQuery', 'Nvl\\Comments\\Models\\Comment::$nonexistentMember'],
            ['internalApi', 'Nvl\\Support\\Consumer\\ConsumerApiCatalog::NONEXISTENT'],
            ['packageQuery', 'Nvl\\Comments\\Models\\Comment::save'],
            ['packageQuery', 'Nvl\\Comments\\Models\\Comment::forceDestroy'],
            ['packageQuery', 'Nvl\\Comments\\Models\\Comment::rawUpdate'],
            ['packageWrite', 'Nvl\\Comments\\Models\\Comment::where'],
        ] as [$identifier, $symbol]) {
            try {
                new ConsumerBoundaryPolicy(self::createReflectionProvider(), dirname(__DIR__, 6), [], [], [[
                    'file' => __DIR__.'/Fixtures/adapter.php.stub', 'identifier' => 'nvl.consumer.'.$identifier,
                    'symbol' => $symbol, 'reason' => 'Invalid member control.',
                ]]);
                self::fail('Accepted invalid exception '.$identifier.' '.$symbol);
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('exception', $exception->getMessage());
            }
        }
    }

    /** A synthesized taxonomy exception only matches a relation derived from real host declarations. */
    public function test_nonmatching_synthesized_relation_exception(): void
    {
        $this->exceptions = [[
            'file' => __DIR__.'/Fixtures/receivers.php.stub',
            'identifier' => 'nvl.consumer.capabilityRelation',
            'symbol' => 'Nvl\\Taxonomy\\Concerns\\HasTaxonomies::$tagz',
            'reason' => 'Typo control must not exempt the statically declared tags relation.',
        ]];
        $this->assertFixture('receivers.php.stub');
    }

    /** A symlink inside an allowed test root cannot grant permission to production source outside it. */
    public function test_test_roots_follow_actual_filesystem_ownership(): void
    {
        $directory = sys_get_temp_dir().'/nvl-boundary-symlink-'.bin2hex(random_bytes(8));
        mkdir($directory);
        mkdir($directory.'/tests');
        $source = $directory.'/production.php';
        $link = $directory.'/tests/linked.php';
        file_put_contents($source, '<?php function symlinked(\\Nvl\\Comments\\Models\\Comment $comment): void { $comment->save(); }');
        symlink($source, $link);
        try {
            $this->testPaths = [$directory.'/tests'];
            $this->analyse([$link], [['Nvl\\Comments\\Models\\Comment::save is outside the supported NVL consumer boundary.', 1]]);
        } finally {
            unlink($link);
            unlink($source);
            rmdir($directory.'/tests');
            rmdir($directory);
        }
    }

    /** Collection membership changes do not inherit model persistence semantics. */
    public function test_collection_membership_and_model_mutators(): void
    {
        $this->assertFixture('transfer.php.stub');
    }

    /** Assert source positions and identifiers from the hand-reviewed fixture expectations. */
    private function assertFixture(string $name): void
    {
        $file = __DIR__.'/Fixtures/'.$name;
        $expected = [];
        $identifiers = [];
        foreach (file($file) as $index => $line) {
            preg_match_all('/boundary: (\w+) ([^;\s]+)/', $line, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                if ($this->testPaths !== [] && in_array($match[1], ['packageQuery', 'packageWrite'], true)) {
                    continue;
                }
                if (($this->exceptions[0]['symbol'] ?? null) === 'Nvl\\Comments\\Models\\Comment::where' && ($this->exceptions[0]['file'] ?? null) === __DIR__.'/Fixtures/adapter.php.stub' && $match[1] === 'packageQuery' && $match[2] === 'Nvl\\Comments\\Models\\Comment::where' && $name === 'adapter.php.stub') {
                    continue;
                }
                $expected[] = [$match[2].' is outside the supported NVL consumer boundary.', $index + 1];
                $identifiers[] = [$index + 1, 'nvl.consumer.'.$match[1]];
            }
        }
        $this->analyse([$file], $expected);
        $actual = array_map(static fn ($error): array => [$error->getLine(), $error->getIdentifier()], $this->gatherAnalyserErrors([$file]));
        sort($actual);
        sort($identifiers);
        self::assertSame($identifiers, $actual);
    }

    public function test_allowed_handles_and_host_tables(): void
    {
        self::assertTrue(class_exists(ConsumerBoundaryPolicy::class), 'The consumer boundary policy must be shipped.');
        $this->analyse([__DIR__.'/Fixtures/allowed.php.stub'], []);
        self::assertFalse(class_exists('ConsumerBoundaryFixtures\\HostArticle', false));
        self::assertFalse(class_exists('ConsumerReceiverFixtures\\Article', false));
    }

    public function test_forbidden_operations_have_exact_locations_and_identifiers(): void
    {
        self::assertTrue(class_exists(PackageStaticCallRule::class), 'The package static-call boundary must be shipped.');
        $expected = [
            [11, 'internalApi', 'Nvl\\Comments\\Services\\CommentAccessService'],
            [19, 'packageQuery', 'Nvl\\Comments\\Models\\Comment::query'],
            [20, 'packageWrite', 'Nvl\\Comments\\Models\\Comment::save'],
            [21, 'packageQuery', 'Nvl\\Comments\\Models\\Comment::refresh'],
            [22, 'packageQuery', 'Nvl\\Comments\\Models\\Comment::$body'],
            [23, 'packageWrite', 'Nvl\\Comments\\Models\\Comment::$id'],
            [24, 'packageQuery', 'Nvl\\Comments\\Models\\Comment::$body'],
            [25, 'packageWrite', 'Nvl\\Comments\\Models\\Comment::$id'],
            [26, 'packageQuery', 'Nvl\\Comments\\Models\\Comment::toArray'],
            [27, 'packageQuery', 'Nvl\\Comments\\Models\\Comment::jsonSerialize'],
            [28, 'packageQuery', 'Nvl\\Comments\\Models\\Comment::where'],
            [29, 'packageWrite', 'Nvl\\Comments\\Models\\Comment::update'],
            [30, 'packageQuery', 'Nvl\\Comments\\Models\\Comment::toArray'],
            [31, 'packageQuery', 'Nvl\\Comments\\Models\\Comment::$body'],
            [32, 'ownedTable', 'nvl_comments_comments'],
        ];
        $file = __DIR__.'/Fixtures/forbidden.php.stub';
        $this->analyse([$file], array_map(static fn (array $error): array => [$error[2].' is outside the supported NVL consumer boundary.', $error[0]], $expected));
        $actual = array_map(static fn ($error): array => [$error->getLine(), $error->getIdentifier()], $this->gatherAnalyserErrors([$file]));
        sort($actual);
        $identifiers = array_map(static fn (array $error): array => [$error[0], 'nvl.consumer.'.$error[1]], $expected);
        sort($identifiers);
        self::assertSame($identifiers, $actual);
    }
}

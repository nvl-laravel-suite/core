<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\PHPStan;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use ZipArchive;

/** Proves the shipped boundary using real offline Composer archives and normal PHPStan caches. */
final class ConsumerBoundaryInstallationTest extends TestCase
{
    /** Install runtime artifacts first, then add explicitly requested host development tools. */
    public function test_archived_consumer_and_normal_cache_invalidation(): void
    {
        $root = dirname(__DIR__, 6);
        $workspace = sys_get_temp_dir().'/nvl-boundary-install-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem;
        $filesystem->mkdir([$workspace.'/archives', $workspace.'/bootstrap/cache', $workspace.'/storage/framework/cache', $workspace.'/config', $workspace.'/app']);
        try {
            $repositories = [];
            foreach (['core', 'comments', 'filterable'] as $package) {
                $this->command(['composer', 'archive', '--format=zip', '--file='.$package, '--dir='.$workspace.'/archives', '--no-interaction'], $root.'/packages/nvl/'.$package);
                $directory = $workspace.'/packages/'.$package;
                $filesystem->mkdir($directory);
                $zip = new ZipArchive;
                self::assertTrue($zip->open($workspace.'/archives/'.$package.'.zip'));
                if ($package === 'core') {
                    self::assertNotFalse($zip->locateName('support/consumer-audit.neon'));
                    self::assertFalse($zip->locateName('support/tests/PHPStan/ConsumerBoundaryRuleTest.php'));
                }
                self::assertTrue($zip->extractTo($directory));
                $zip->close();
                $repositories[] = ['type' => 'path', 'url' => $directory, 'options' => ['versions' => ['nvl/'.$package => '5.0.0'], 'symlink' => false]];
            }
            $installed = json_decode((string) file_get_contents($root.'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
            foreach ($installed['packages'] as $package) {
                if (str_starts_with($package['name'], 'nvl/')) {
                    continue;
                }
                $package['dist'] = ['type' => 'path', 'url' => realpath($root.'/vendor/composer/'.$package['install-path']), 'reference' => $package['dist']['reference'] ?? null];
                $package['transport-options'] = ['symlink' => false];
                unset($package['source'], $package['install-path'], $package['installation-source']);
                $repositories[] = ['type' => 'package', 'package' => $package];
            }
            $repositories[] = ['packagist.org' => false];
            $manifest = [
                'name' => 'consumer/boundary-proof',
                'require' => ['php' => '^8.4', 'nvl/comments' => '5.0.0'],
                'repositories' => $repositories,
                'config' => ['allow-plugins' => false],
            ];
            $filesystem->dumpFile($workspace.'/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            $this->command(['composer', 'update', '--no-dev', '--no-plugins', '--no-scripts', '--no-audit', '--no-interaction'], $workspace);
            $this->command(['composer', 'dump-autoload', '--optimize', '--strict-psr', '--strict-ambiguous', '--no-dev', '--no-plugins', '--no-scripts', '--no-interaction'], $workspace);
            $probe = <<<'PHP_SOURCE'
<?php
require __DIR__.'/vendor/autoload.php';
$catalog = Nvl\Support\Consumer\ConsumerApiCatalog::installed();
$loaded = array_values(array_filter(get_declared_classes(), static fn ($name) => str_starts_with($name, 'PHPStan\\') || str_starts_with($name, 'Larastan\\') || str_starts_with($name, 'PhpParser\\') || str_starts_with($name, 'Illuminate\\') || str_starts_with($name, 'Nvl\\Suite\\')));
$dev = array_values(array_filter(['phpstan/phpstan', 'larastan/larastan', 'orchestra/testbench', 'pestphp/pest', 'phpunit/phpunit'], Composer\InstalledVersions::isInstalled(...)));
$manifest = new Illuminate\Foundation\PackageManifest(new Illuminate\Filesystem\Filesystem, __DIR__, __DIR__.'/bootstrap/cache/packages.php');
$manifest->build();
$providers = $manifest->providers();
foreach ($providers as $provider) { class_exists($provider); }
$rules = array_values(array_filter(get_declared_classes(), static fn ($name) => str_starts_with($name, 'Nvl\\Support\\Consumer\\PHPStan\\') || str_starts_with($name, 'Nvl\\Suite\\')));
echo json_encode(['packages' => array_keys($catalog->installedRoots()), 'loaded' => $loaded, 'dev' => $dev, 'rules' => $rules, 'providers' => $providers, 'parser_installed' => Composer\InstalledVersions::isInstalled('nikic/php-parser')], JSON_THROW_ON_ERROR);
PHP_SOURCE;
            $filesystem->dumpFile($workspace.'/probe.php', $probe);
            $runtime = json_decode($this->command([PHP_BINARY, 'probe.php'], $workspace), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame([], $runtime['loaded']);
            self::assertSame([], $runtime['dev']);
            self::assertSame([], $runtime['rules']);
            self::assertEqualsCanonicalizing(['nvl/core', 'nvl/comments', 'nvl/filterable'], $runtime['packages']);
            self::assertContains('Nvl\\Support\\Providers\\SupportServiceProvider', $runtime['providers']);
            self::assertTrue($runtime['parser_installed']);

            $versions = array_column($installed['packages'], 'version', 'name');
            $manifest['require-dev'] = ['phpstan/phpstan' => $versions['phpstan/phpstan'], 'larastan/larastan' => $versions['larastan/larastan']];
            $filesystem->dumpFile($workspace.'/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            $this->command(['composer', 'update', '--no-plugins', '--no-scripts', '--no-audit', '--no-interaction'], $workspace);
            $filesystem->dumpFile($workspace.'/bootstrap/app.php', '<?php return Illuminate\\Foundation\\Application::configure(basePath: dirname(__DIR__))->create();');
            $filesystem->dumpFile($workspace.'/config/app.php', '<?php return ["key" => "base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=", "env" => "testing"];');
            $filesystem->dumpFile($workspace.'/phpstan.neon', "includes:\n    - vendor/larastan/larastan/extension.neon\n    - vendor/nvl/core/support/consumer-audit.neon\nparameters:\n    level: 0\n    tmpDir: cache\n    paths:\n        - app\n");
            $host = <<<'HOST'
<?php
use Nvl\Comments\Models\Comment;
use Nvl\Support\Consumer\ConsumerApiCatalog;
function accepted(Comment $comment, ConsumerApiCatalog $catalog): void {
    $comment->id;
    $comment->getKey();
    $catalog->symbol(Comment::class);
}
HOST;
            $filesystem->dumpFile($workspace.'/app/host.php', $host);
            self::assertSame([], $this->analyse($workspace)['identifiers']);
            self::assertSame(0, $this->analyse($workspace)['exit']);
            self::assertFileExists($workspace.'/cache/resultCache.php');

            $commentsPath = $workspace.'/vendor/nvl/comments/resources/consumer-api.json';
            $corePath = $workspace.'/vendor/nvl/core/support/resources/consumer-api.json';
            $commentsBytes = (string) file_get_contents($commentsPath);
            $coreBytes = (string) file_get_contents($corePath);
            $comments = json_decode($commentsBytes, true, flags: JSON_THROW_ON_ERROR);
            $core = json_decode($coreBytes, true, flags: JSON_THROW_ON_ERROR);
            foreach (['models', 'capability_relations', 'tables'] as $map) {
                if ($core[$map] === []) {
                    $core[$map] = new \stdClass;
                }
            }
            $comments['models']['Nvl\\Comments\\Models\\Comment']['read'] = [];
            $filesystem->dumpFile($commentsPath, json_encode($comments, JSON_THROW_ON_ERROR));
            self::assertSame(['nvl.consumer.packageQuery'], $this->analyse($workspace)['identifiers']);
            $filesystem->dumpFile($commentsPath, $commentsBytes);
            self::assertSame(0, $this->analyse($workspace)['exit']);
            $core['symbols']['Nvl\\Support\\Consumer\\ConsumerApiCatalog']['methods'] = array_values(array_diff($core['symbols']['Nvl\\Support\\Consumer\\ConsumerApiCatalog']['methods'], ['symbol']));
            $filesystem->dumpFile($corePath, json_encode($core, JSON_THROW_ON_ERROR));
            self::assertSame(['nvl.consumer.internalApi'], $this->analyse($workspace)['identifiers']);
            $filesystem->dumpFile($corePath, $coreBytes);
            self::assertSame(0, $this->analyse($workspace)['exit']);
            $filesystem->dumpFile($commentsPath, '{broken');
            self::assertNotSame(0, $this->analyse($workspace)['exit']);
            $filesystem->dumpFile($commentsPath, $commentsBytes);
            self::assertSame(0, $this->analyse($workspace)['exit']);
            unlink($commentsPath);
            self::assertNotSame(0, $this->analyse($workspace)['exit']);
            $filesystem->dumpFile($commentsPath, $commentsBytes);

            $owned = $workspace.'/vendor/nvl/comments/host-source';
            $filesystem->mkdir($owned);
            $filesystem->dumpFile($owned.'/host.php', '<?php function owned(\\Nvl\\Comments\\Models\\Comment $comment): void { $comment->save(); }');
            $comments = json_decode($commentsBytes, true, flags: JSON_THROW_ON_ERROR);
            $comments['psr4']['BoundaryOwned\\'] = 'host-source/';
            $filesystem->dumpFile($commentsPath, json_encode($comments, JSON_THROW_ON_ERROR));
            self::assertSame(0, $this->analyse($workspace, 'vendor/nvl/comments/host-source')['exit']);
            self::assertSame(0, $this->analyse($workspace, 'vendor/nvl/comments/host-source')['exit']);
            $filesystem->dumpFile($commentsPath, $commentsBytes);
            self::assertSame(['nvl.consumer.packageWrite'], $this->analyse($workspace, 'vendor/nvl/comments/host-source')['identifiers']);

            $forbidden = <<<'HOST'
<?php
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Traits\InteractsWithComments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
class Article extends Model { use InteractsWithComments; }
function rejected(Comment $comment, Article $article): void {
    $comment->save();
    $comment->refresh();
    $article->comments;
    DB::table('nvl_comments_comments');
}
HOST;
            $filesystem->dumpFile($workspace.'/app/host.php', $forbidden);
            $result = $this->analyse($workspace);
            self::assertNotSame(0, $result['exit']);
            self::assertEqualsCanonicalizing(['nvl.consumer.internalApi', 'nvl.consumer.packageQuery', 'nvl.consumer.packageWrite', 'nvl.consumer.capabilityRelation', 'nvl.consumer.ownedTable'], array_values(array_unique($result['identifiers'])));
            $configuration = (string) file_get_contents($workspace.'/phpstan.neon');
            $filesystem->dumpFile($workspace.'/phpstan.neon', $configuration."    nvlConsumer:\n        testPaths:\n            - app\n");
            self::assertEqualsCanonicalizing(['nvl.consumer.internalApi', 'nvl.consumer.capabilityRelation', 'nvl.consumer.ownedTable'], $this->analyse($workspace)['identifiers']);
            $filesystem->dumpFile($workspace.'/phpstan.neon', $configuration);
            self::assertCount(5, $this->analyse($workspace)['identifiers']);
            $ignored = $configuration."    ignoreErrors:\n";
            foreach (array_unique($result['identifiers']) as $identifier) {
                $ignored .= "        -\n            identifier: ".$identifier."\n            path: app/host.php\n";
            }
            $filesystem->dumpFile($workspace.'/phpstan.neon', $ignored);
            self::assertSame(0, $this->analyse($workspace)['exit']);
            $filesystem->dumpFile($workspace.'/phpstan.neon', $configuration);
            $this->command([PHP_BINARY, 'vendor/bin/phpstan', 'analyse', '-c', 'phpstan.neon', '--no-progress', '--memory-limit=1G', '--generate-baseline=baseline.neon'], $workspace);
            self::assertStringContainsString('nvl.consumer.packageWrite', (string) file_get_contents($workspace.'/baseline.neon'));
            $filesystem->dumpFile($workspace.'/phpstan.neon', str_replace("includes:\n", "includes:\n    - baseline.neon\n", $configuration));
            self::assertSame(0, $this->analyse($workspace)['exit']);
            $this->command([PHP_BINARY, 'vendor/bin/phpstan', '--version'], $workspace);
            file_put_contents('/tmp/nvl-task2-installation-evidence.json', json_encode(['runtime' => $runtime, 'versions' => $manifest['require-dev'], 'forbidden' => $result, 'normal_cache_mutations' => ['safe-field', 'member', 'ownership', 'corrupt', 'missing', 'testPaths-options'], 'phpstan_ignores' => ['identifier', 'generated-baseline']], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /** Analyse with the ordinary result cache, preserving it across every metadata mutation.
     * @return array{exit:int,identifiers:list<string>,output:string}
     */
    private function analyse(string $workspace, ?string $path = null): array
    {
        $command = [PHP_BINARY, 'vendor/bin/phpstan', 'analyse', '-c', 'phpstan.neon', '--no-progress', '--memory-limit=1G', '--error-format=json', '-vv'];
        if ($path !== null) {
            $command[] = $path;
        }
        $process = new Process($command, $workspace, ['COMPOSER_DISABLE_NETWORK' => '1'], timeout: 120);
        $exit = $process->run();
        $output = $process->getOutput().$process->getErrorOutput();
        $json = json_decode($process->getOutput(), true);
        $identifiers = [];
        foreach ($json['files'] ?? [] as $file) {
            foreach ($file['messages'] as $message) {
                $identifiers[] = $message['identifier'];
            }
        }
        file_put_contents('/tmp/nvl-task2-installation-commands.log', json_encode(['command' => $command, 'exit' => $exit, 'output' => $output], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);

        return ['exit' => $exit, 'identifiers' => $identifiers, 'output' => $output];
    }

    /** Run local archive and Composer operations without network resolution.
     * @param  list<string>  $command
     */
    private function command(array $command, string $directory): string
    {
        $process = new Process($command, $directory, ['COMPOSER_DISABLE_NETWORK' => '1', 'COMPOSER_ROOT_VERSION' => '5.0.0'], timeout: 120);
        $process->mustRun();

        return $process->getOutput();
    }
}

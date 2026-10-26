<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\PHPStan;

use Composer\InstalledVersions;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use ZipArchive;

/** Verifies native Core's production archive without publishing retired DTO or model-handle policy APIs. */
final class ConsumerBoundaryInstallationTest extends TestCase
{
    /** Archive the actual package and verify its native discovery, autoload, and required runtime files. */
    public function test_archived_native_core_contract(): void
    {
        $package = InstalledVersions::getInstallPath('nvl/core');
        self::assertNotNull($package);
        $workspace = sys_get_temp_dir().'/nvl-native-core-archive-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem;
        $filesystem->mkdir($workspace);

        try {
            (new Process(
                ['composer', 'archive', '--format=zip', '--file=core', '--dir='.$workspace, '--no-interaction'],
                $package,
                ['COMPOSER_DISABLE_NETWORK' => '1', 'COMPOSER_ROOT_VERSION' => '7.0.0'],
                timeout: 60,
            ))->mustRun();
            $zip = new ZipArchive;
            self::assertTrue($zip->open($workspace.'/core.zip'));

            try {
                $bytes = $zip->getFromName('composer.json');
                self::assertNotFalse($bytes);
                $manifest = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(['Nvl\\Support\\' => 'support/src/'], $manifest['autoload']['psr-4']);
                self::assertSame('data/src/', $manifest['autoload-dev']['psr-4']['Nvl\\Data\\']);
                self::assertSame([SupportServiceProvider::class, LocaleServiceProvider::class], $manifest['extra']['laravel']['providers']);
                self::assertSame('^13.0', $manifest['require']['laravel/framework']);
                self::assertArrayNotHasKey('aliases', $manifest['extra']['laravel']);

                foreach (['spatie/laravel-data', 'spatie/laravel-typescript-transformer', 'spatie/typescript-transformer', 'phpstan/phpstan', 'larastan/larastan', 'nikic/php-parser'] as $dependency) {
                    self::assertArrayNotHasKey($dependency, $manifest['require']);
                    self::assertArrayNotHasKey($dependency, $manifest['suggest'] ?? []);
                }
                foreach ([
                    'LICENSE',
                    'support/config/nvl-core.php',
                    'support/src/Providers/SupportServiceProvider.php',
                    'support/src/Providers/LocaleServiceProvider.php',
                    'support/src/Enums/SystemActor.php',
                    'support/src/Enums/PublicActor.php',
                    'support/src/Ownership/Contracts/OwnershipBoundary.php',
                    'support/src/Ownership/Traits/HasOwnership.php',
                    'support/src/Ownership/Services/UnownedOwnershipBoundary.php',
                    'support/src/Ownership/Services/PersistedOwnershipStorage.php',
                    'support/src/Doctor/DoctorCheck.php',
                    'support/src/Schema/SchemaMigrationPaths.php',
                ] as $required) {
                    self::assertNotFalse($zip->locateName($required), 'Required native file missing: '.$required);
                }
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $name = $zip->getNameIndex($index);
                    self::assertNotFalse($name);
                    foreach (['data/', 'support/tests/', 'support/src/Consumer/'] as $prefix) {
                        self::assertFalse(str_starts_with($name, $prefix), 'Retired or development source shipped: '.$name);
                    }
                }
                foreach (['support/consumer-audit.neon', 'support/resources/consumer-api.json', 'support/stubs-events.stub', 'Pest.php', 'phpstan.neon.dist', 'phpunit.xml.dist'] as $developmentFile) {
                    self::assertFalse($zip->locateName($developmentFile), 'Retired or development file shipped: '.$developmentFile);
                }
            } finally {
                $zip->close();
            }
        } finally {
            $filesystem->remove($workspace);
        }
    }
}

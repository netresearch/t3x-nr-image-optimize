<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Functional;

use Netresearch\NrImageOptimize\Processor;
use Netresearch\NrImageOptimize\Service\VariantUrlSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function copy;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function sprintf;
use function unlink;

/**
 * Which variant requests the processor turns into a new variant on disk:
 * only signed URLs, only for sources in a public storage, and only for
 * sources that are images of a supported type.
 */
#[CoversClass(Processor::class)]
#[CoversClass(VariantUrlSigner::class)]
final class ProcessorAccessTest extends FunctionalTestCase
{
    use SignedVariantRequestTrait;

    protected array $testExtensionsToLoad = [
        'netresearch/nr-image-optimize',
    ];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/nr_image_optimize/Tests/Functional/Fixtures/test-image.png' => 'fileadmin/test-image.png',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetStorageRootCaches();

        // The test instance is reused across tests (and runs); start without
        // variants an earlier test wrote.
        $this->removeTree(Environment::getPublicPath() . '/processed');
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname()); // nosemgrep: php.lang.security.unlink-use.unlink-use -- test fixture teardown inside the test instance
            }
        }

        rmdir($directory);
    }

    protected function tearDown(): void
    {
        $this->resetStorageRootCaches();

        parent::tearDown();
    }

    #[Test]
    public function signedVariantRequestCreatesTheVariant(): void
    {
        $response = $this->get(Processor::class)->generateAndSend(
            $this->signedVariantRequest('/processed/fileadmin/test-image.w40h30m0q80.png'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertFileExists(Environment::getPublicPath() . '/processed/fileadmin/test-image.w40h30m0q80.png');
    }

    #[Test]
    public function variantRequestWithoutSignatureIsRefused(): void
    {
        $response = $this->get(Processor::class)->generateAndSend(
            new ServerRequest(new Uri('https://example.com/processed/fileadmin/test-image.w41h31m0q80.png')),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertFileDoesNotExist(Environment::getPublicPath() . '/processed/fileadmin/test-image.w41h31m0q80.png');
    }

    #[Test]
    public function variantRequestWithSignatureOfAnotherVariantIsRefused(): void
    {
        $signature = (new VariantUrlSigner())->sign('/processed/fileadmin/test-image.w40h30m0q80.png');

        $response = $this->get(Processor::class)->generateAndSend(
            new ServerRequest(new Uri(sprintf(
                'https://example.com/processed/fileadmin/test-image.w8192h8192m0q100.png?%s=%s',
                VariantUrlSigner::QUERY_PARAMETER,
                $signature,
            ))),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertFileDoesNotExist(Environment::getPublicPath() . '/processed/fileadmin/test-image.w8192h8192m0q100.png');
    }

    #[Test]
    public function existingVariantIsServedWithoutSignature(): void
    {
        $processor = $this->get(Processor::class);

        self::assertSame(200, $processor->generateAndSend(
            $this->signedVariantRequest('/processed/fileadmin/test-image.w42h32m0q80.png'),
        )->getStatusCode());

        $response = $processor->generateAndSend(
            new ServerRequest(new Uri('https://example.com/processed/fileadmin/test-image.w42h32m0q80.png')),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function variantOfFileInNonPublicStorageIsNotCreated(): void
    {
        $this->createLocalStorage(1, 'fileadmin/', true);
        $this->createLocalStorage(2, 'protected/', false);
        $this->placeImage('protected/test-image.png');

        $response = $this->get(Processor::class)->generateAndSend(
            $this->signedVariantRequest('/processed/protected/test-image.w40h30m0q80.png'),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertFileDoesNotExist(Environment::getPublicPath() . '/processed/protected/test-image.w40h30m0q80.png');
    }

    #[Test]
    public function existingVariantOfFileInNonPublicStorageIsNotServed(): void
    {
        $this->createLocalStorage(1, 'fileadmin/', true);
        $this->createLocalStorage(2, 'protected/', false);
        $this->placeImage('protected/test-image.png');
        $this->placeImage('processed/protected/test-image.w40h30m0q80.png');

        $response = $this->get(Processor::class)->generateAndSend(
            new ServerRequest(new Uri('https://example.com/processed/protected/test-image.w40h30m0q80.png')),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function variantOfFileInPublicStorageIsCreated(): void
    {
        $this->createLocalStorage(1, 'fileadmin/', true);
        $this->createLocalStorage(2, 'shared/', true);
        $this->placeImage('shared/test-image.png');

        $response = $this->get(Processor::class)->generateAndSend(
            $this->signedVariantRequest('/processed/shared/test-image.w40h30m0q80.png'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertFileExists(Environment::getPublicPath() . '/processed/shared/test-image.w40h30m0q80.png');
    }

    #[Test]
    public function sourceThatIsNotAnImageIsNotProcessed(): void
    {
        file_put_contents(
            Environment::getPublicPath() . '/fileadmin/not-an-image.png',
            "%!PS-Adobe-3.0\n%%BoundingBox: 0 0 10 10\nshowpage\n",
        );

        $response = $this->get(Processor::class)->generateAndSend(
            $this->signedVariantRequest('/processed/fileadmin/not-an-image.w40h30m0q80.png'),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertFileDoesNotExist(Environment::getPublicPath() . '/processed/fileadmin/not-an-image.w40h30m0q80.png');
    }

    #[Test]
    public function sourceWithUnsupportedExtensionIsNotProcessed(): void
    {
        file_put_contents(
            Environment::getPublicPath() . '/fileadmin/drawing.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>',
        );

        $response = $this->get(Processor::class)->generateAndSend(
            $this->signedVariantRequest('/processed/fileadmin/drawing.w40h30m0q80.svg'),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertFileDoesNotExist(Environment::getPublicPath() . '/processed/fileadmin/drawing.w40h30m0q80.svg');
    }

    /**
     * Insert a Local-driver storage whose base path is relative to the public path.
     */
    private function createLocalStorage(int $uid, string $basePath, bool $isPublic): void
    {
        $configuration = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>'
            . '<T3FlexForms><data><sheet index="sDEF"><language index="lDEF">'
            . '<field index="basePath"><value index="vDEF">' . $basePath . '</value></field>'
            . '<field index="pathType"><value index="vDEF">relative</value></field>'
            . '<field index="caseSensitive"><value index="vDEF">1</value></field>'
            . '</language></sheet></data></T3FlexForms>';

        $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file_storage')
            ->insert('sys_file_storage', [
                'uid'           => $uid,
                'pid'           => 0,
                'name'          => 'Storage ' . $uid,
                'driver'        => 'Local',
                'configuration' => $configuration,
                'is_browsable'  => 1,
                'is_public'     => $isPublic ? 1 : 0,
                'is_writable'   => 1,
                'is_online'     => 1,
                'is_default'    => $uid === 1 ? 1 : 0,
            ]);
    }

    /**
     * Copy the fixture image to a path relative to the public path.
     */
    private function placeImage(string $relativePath): void
    {
        $target    = Environment::getPublicPath() . '/' . $relativePath;
        $directory = dirname($target);

        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0o777, true));
        }

        self::assertTrue(copy(__DIR__ . '/Fixtures/test-image.png', $target));
    }

    /**
     * The processor caches the storage-derived roots per public path for the
     * lifetime of the PHP process; storages inserted by a test must not be
     * hidden behind a list computed by an earlier test.
     */
    private function resetStorageRootCaches(): void
    {
        $reflection = new ReflectionClass(Processor::class);

        foreach (['resolvedAllowedRootsByPublicPath', 'resolvedNonPublicRootsByPublicPath'] as $name) {
            if ($reflection->hasProperty($name)) {
                $reflection->getProperty($name)->setValue(null, []);
            }
        }
    }
}

<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Functional;

use Netresearch\NrImageOptimize\Processor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional regression tests for issue #70 and its follow-ups, exercising
 * the real TYPO3 bootstrap + FAL LocalDriver + middleware request cycle
 * against a test instance where fileadmin / processed / uploads are
 * symlinks to an external directory outside the public root.
 *
 * This mirrors the Chemnitz AWS/ECS + EFS production layout where
 * scripts/post-deployment links public/fileadmin, public/processed and
 * public/uploads into /mnt/efs/cms/ before the webserver starts.
 *
 * The earlier unit tests in ProcessorTest exercise isPathWithinAllowedRoots
 * via reflection with a mocked StorageRepository; these tests drive the
 * exact same code through generateAndSend() with the real DI container and
 * a real fileadmin storage, to prove the fix works end-to-end and not just
 * against a mocked boundary.
 */
#[CoversClass(Processor::class)]
final class ProcessorSymlinkedFileadminTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-image-optimize',
    ];

    /**
     * Stage the source image into a neutral location inside the test
     * instance; setUp() below moves it into the external "EFS-like"
     * directory and restores the expected symlinked layout.
     */
    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/nr_image_optimize/Tests/Functional/Fixtures/test-image.png' => 'typo3temp/nr-pio-fixture/test-image.png',
    ];

    /**
     * Absolute path to the external directory simulating the EFS mount.
     * Allocated in setUp(), cleaned up in tearDown().
     */
    private string $externalMount = '';

    protected function setUp(): void
    {
        parent::setUp();

        $publicPath = Environment::getPublicPath();

        // Sanity: the staged fixture must be readable before we start
        // restructuring the public tree.
        $fixture = $publicPath . '/typo3temp/nr-pio-fixture/test-image.png';
        self::assertFileExists($fixture, 'Fixture staging failed');

        // External mount lives OUTSIDE publicPath so that realpath() on the
        // symlinks resolves to a target that does NOT start with publicPath
        // — this is exactly the condition that triggered HTTP 400 in #70.
        $this->externalMount = dirname($publicPath) . '/nr-pio-efs-' . uniqid('', true);
        mkdir($this->externalMount . '/fileadmin', 0o777, true);
        mkdir($this->externalMount . '/processed', 0o777, true);
        mkdir($this->externalMount . '/uploads', 0o777, true);

        // Copy (do not rename) the fixture into the external fileadmin:
        // the testing framework reuses the instance across tests in the
        // same case and only populates pathsToProvideInTestInstance on the
        // first test, so subsequent setUps rely on the staged copy still
        // being readable at its original location.
        $fixtureTarget = $this->externalMount . '/fileadmin/test-image.png';
        self::assertTrue(
            copy($fixture, $fixtureTarget),
            sprintf('Fixture copy failed: %s -> %s', $fixture, $fixtureTarget),
        );
        self::assertFileExists($fixtureTarget, 'Fixture copy silently produced no file');

        // Replace the real public/fileadmin directory (created by the
        // testing framework) with a symlink pointing into the external
        // mount — mirrors `ln -sf /mnt/efs/cms/fileadmin/ /var/www/public`
        // from chemnitz/cms/main/scripts/post-deployment.
        $this->replaceDirWithSymlink(
            $publicPath . '/fileadmin',
            $this->externalMount . '/fileadmin',
        );
        $this->replaceDirWithSymlink(
            $publicPath . '/processed',
            $this->externalMount . '/processed',
        );
        $this->replaceDirWithSymlink(
            $publicPath . '/uploads',
            $this->externalMount . '/uploads',
        );

        // getAllowedRoots() caches the resolved list statically across
        // invocations. Parent setUp() may already have resolved it against
        // the non-symlinked layout — reset so our symlink layout is picked
        // up on the first real request.
        $this->resetAllowedRootsCache();
    }

    protected function tearDown(): void
    {
        $this->resetAllowedRootsCache();

        if ($this->externalMount !== '' && is_dir($this->externalMount)) {
            $this->removeRecursive($this->externalMount);
        }

        parent::tearDown();
    }

    /**
     * Core regression for #70: with `public/fileadmin` symlinked to an
     * external directory outside the public root, a request for an
     * uncached image variant must succeed (not return HTTP 400 from path
     * validation).
     */
    #[Test]
    public function uncachedVariantUnderSymlinkedFileadminReturns200(): void
    {
        $processor = $this->get(Processor::class);

        $uri     = new Uri('https://example.com/processed/fileadmin/test-image.w50h38m0q80.png');
        $request = new ServerRequest($uri);

        $response = $processor->generateAndSend($request);

        self::assertNotSame(
            400,
            $response->getStatusCode(),
            'Path validation rejected a request under symlinked fileadmin. '
            . 'Check the regression conditions of issue #70.',
        );
        self::assertSame(200, $response->getStatusCode());

        // The variant must actually have been written to the external
        // mount (following the symlink), not to the empty public/processed.
        self::assertFileExists(
            $this->externalMount . '/processed/fileadmin/test-image.w50h38m0q80.png',
            'Variant should be stored through the public/processed symlink on the external mount',
        );
    }

    /**
     * Serving an already-cached variant under a symlinked public/processed
     * (the #70 follow-up path-variant branch of isPathWithinAllowedRoots)
     * must succeed on the second request too.
     */
    #[Test]
    public function cachedVariantUnderSymlinkedProcessedIsServedFromCache(): void
    {
        $processor = $this->get(Processor::class);

        $uri     = new Uri('https://example.com/processed/fileadmin/test-image.w50h38m0q80.png');
        $request = new ServerRequest($uri);

        // First request generates the variant; second must hit the cached-
        // file short-circuit at the top of generateAndSend().
        $first = $processor->generateAndSend($request);
        self::assertSame(200, $first->getStatusCode(), 'First request failed — base regression broken');

        $second = $processor->generateAndSend($request);
        self::assertSame(200, $second->getStatusCode(), 'Second request failed — cached-variant path validation broken under symlinks');
        self::assertNotEmpty($second->getHeaderLine('Cache-Control'));
    }

    /**
     * Security guarantee: the symlinked-fileadmin fix must not be
     * permissive enough to let a traversal sequence escape the allowed
     * roots and hit an arbitrary file on disk.
     */
    #[Test]
    public function pathTraversalStillRejectedWhenFileadminIsSymlinked(): void
    {
        $processor = $this->get(Processor::class);

        $uri     = new Uri('https://example.com/processed/../../etc/passwd.w100h75m0q80.png');
        $request = new ServerRequest($uri);

        $response = $processor->generateAndSend($request);

        self::assertSame(
            400,
            $response->getStatusCode(),
            'Path traversal bypassed validation when fileadmin is a symlink',
        );
    }

    /**
     * Atomically replace `$linkTarget` (likely an empty directory created by
     * the testing framework) with a symlink pointing at `$linkDestination`.
     */
    private function replaceDirWithSymlink(string $linkTarget, string $linkDestination): void
    {
        if (is_link($linkTarget)) {
            unlink($linkTarget); // nosemgrep: php.lang.security.unlink-use.unlink-use -- test fixture teardown of self-created tmp symlink
        } elseif (is_dir($linkTarget)) {
            $this->removeRecursive($linkTarget);
        } elseif (file_exists($linkTarget)) {
            unlink($linkTarget); // nosemgrep: php.lang.security.unlink-use.unlink-use -- test fixture teardown of self-created tmp file
        }

        symlink($linkDestination, $linkTarget);
    }

    /**
     * Recursively remove a directory tree — symlinks are unlinked without
     * descending into their target so we don't accidentally delete the
     * external mount contents.
     */
    private function removeRecursive(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use -- test fixture teardown of self-created tmp files

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            $this->removeRecursive($path . '/' . $entry);
        }

        rmdir($path);
    }

    /**
     * Clear the static allowed-roots cache on Processor so that
     * getAllowedRoots() rebuilds against the current on-disk layout.
     */
    private function resetAllowedRootsCache(): void
    {
        $reflection = new ReflectionClass(Processor::class);

        if (!$reflection->hasProperty('resolvedAllowedRootsByPublicPath')) {
            return;
        }

        $property = $reflection->getProperty('resolvedAllowedRootsByPublicPath');
        $property->setValue(null, []);
    }
}

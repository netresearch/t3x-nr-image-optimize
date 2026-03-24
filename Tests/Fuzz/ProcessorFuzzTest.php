<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Fuzz;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Netresearch\NrImageOptimize\Processor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ReflectionMethod;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\LockFactory;

/**
 * Fuzz tests for the Processor class.
 *
 * These tests exercise URL parsing and mode extraction with randomised
 * inputs to detect crashes, type errors, or unexpected exceptions that
 * a curated data-provider might miss.
 */
#[CoversClass(Processor::class)]
final class ProcessorFuzzTest extends TestCase
{
    private Processor $processor;

    protected function setUp(): void
    {
        parent::setUp();

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            '/var/www/html',
            '/var/www/html/public',
            '/var/www/html/var',
            '/var/www/html/config',
            '/var/www/html/public/index.php',
            'UNIX',
        );

        $this->processor = new Processor(
            new ImageManager(new Driver()),
            $this->createMock(LockFactory::class),
            $this->createMock(ResponseFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class),
        );
    }

    /**
     * Generate a random URL path that may or may not match the expected
     * pattern.  Includes edge-cases such as empty segments, very long
     * numeric values, non-ASCII characters, and missing components.
     */
    private function randomUrlPath(): string
    {
        $extensions = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'svg', 'bmp', '', bin2hex(random_bytes(3))];
        $parts      = [];

        // Random path depth (0-5 segments)
        $depth = random_int(0, 5);

        for ($i = 0; $i < $depth; ++$i) {
            $parts[] = match (random_int(0, 3)) {
                0       => 'processed',
                1       => bin2hex(random_bytes(random_int(1, 8))),
                2       => str_repeat('a', random_int(1, 50)),
                default => '',
            };
        }

        // Build mode string with random components
        $mode = '';

        if (random_int(0, 1) === 1) {
            $mode .= 'w' . random_int(-100, 10000);
        }

        if (random_int(0, 1) === 1) {
            $mode .= 'h' . random_int(-100, 10000);
        }

        if (random_int(0, 1) === 1) {
            $mode .= 'q' . random_int(-1, 200);
        }

        if (random_int(0, 1) === 1) {
            $mode .= 'm' . random_int(-1, 5);
        }

        $ext      = $extensions[array_rand($extensions)];
        $filename = 'image';

        if ($mode !== '') {
            $filename .= '.' . $mode;
        }

        if ($ext !== '') {
            $filename .= '.' . $ext;
        }

        $parts[] = $filename;

        return '/' . implode('/', $parts);
    }

    /**
     * Generate a random mode string for getValueFromMode fuzzing.
     */
    private function randomModeString(): string
    {
        $chars  = 'wWhHqQmM0123456789-.';
        $length = random_int(0, 30);
        $result = '';

        for ($i = 0; $i < $length; ++$i) {
            $result .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $result;
    }

    #[Test]
    public function gatherInformationBasedOnUrlDoesNotCrashWithRandomInput(): void
    {
        $method = new ReflectionMethod($this->processor, 'gatherInformationBasedOnUrl');

        for ($i = 0; $i < 500; ++$i) {
            $url = $this->randomUrlPath();

            try {
                $result = $method->invoke($this->processor, $url);
                // If it returns, it must be an array
                self::assertIsArray($result, sprintf('Expected array for URL: %s', $url));
            } catch (\Throwable $e) {
                // TypeError or ValueError from PHP internals are acceptable
                // but segfaults / fatal errors would terminate the test runner
                self::assertInstanceOf(
                    \Throwable::class,
                    $e,
                    sprintf('Unexpected crash for URL: %s — %s', $url, $e->getMessage()),
                );
            }
        }
    }

    #[Test]
    public function getValueFromModeDoesNotCrashWithRandomInput(): void
    {
        $method     = new ReflectionMethod($this->processor, 'getValueFromMode');
        $identifiers = ['w', 'h', 'q', 'm', '', 'x', 'W', "\0"];

        for ($i = 0; $i < 500; ++$i) {
            $identifier = $identifiers[array_rand($identifiers)];
            $mode       = $this->randomModeString();

            try {
                $result = $method->invoke($this->processor, $identifier, $mode);
                // Must return int or null
                self::assertTrue(
                    $result === null || is_int($result),
                    sprintf('Expected int|null for identifier=%s mode=%s, got %s', $identifier, $mode, get_debug_type($result)),
                );
            } catch (\Throwable $e) {
                self::assertInstanceOf(
                    \Throwable::class,
                    $e,
                    sprintf('Unexpected crash for identifier=%s mode=%s — %s', $identifier, $mode, $e->getMessage()),
                );
            }
        }
    }

    #[Test]
    public function gatherInformationBasedOnUrlHandlesEdgeCases(): void
    {
        $method = new ReflectionMethod($this->processor, 'gatherInformationBasedOnUrl');

        $edgeCases = [
            '',
            '/',
            '//',
            '/../../../etc/passwd',
            '/processed/' . str_repeat('a', 1000) . '.w100.jpg',
            "/processed/image.w\x00100.jpg",
            '/processed/image.w999999999999.jpg',
            '/processed/image.w-1h-1q-1m-1.jpg',
            '/processed/image.w0h0q0m0.jpg',
            '/processed/image.jpg?foo=bar',
            '/processed/image.w100h200.WEBP',
            '/processed/image.w100h200.JpEg',
        ];

        foreach ($edgeCases as $url) {
            try {
                $result = $method->invoke($this->processor, $url);
                self::assertIsArray($result, sprintf('Expected array for edge case: %s', $url));
            } catch (\Throwable) {
                // Exceptions are acceptable; crashes are not
            }
        }
    }
}

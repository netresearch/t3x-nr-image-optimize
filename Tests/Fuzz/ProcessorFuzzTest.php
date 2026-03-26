<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Fuzz;

use Netresearch\NrImageOptimize\Processor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

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

        // ImageManager is final and cannot be mocked. Use newInstanceWithoutConstructor
        // since fuzz tests only exercise URL parsing via reflection, not image operations.
        $reflection      = new ReflectionClass(Processor::class);
        $this->processor = $reflection->newInstanceWithoutConstructor();
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
     * Generate a random mode string for parseAllModeValues fuzzing.
     */
    private function randomModeString(): string
    {
        $chars  = 'wWhHqQmM0123456789-.';
        $max    = strlen($chars) - 1;
        $length = random_int(0, 30);
        $result = '';

        for ($i = 0; $i < $length; ++$i) {
            $result .= $chars[random_int(0, $max)];
        }

        return $result;
    }

    #[Test]
    public function gatherInformationBasedOnUrlDoesNotCrashWithRandomInput(): void
    {
        $method = new ReflectionMethod($this->processor, 'gatherInformationBasedOnUrl');

        for ($i = 0; $i < 500; ++$i) {
            $url = $this->randomUrlPath();

            $result = $method->invoke($this->processor, $url);
            // Must be array or null (non-matching pattern)
            self::assertTrue(
                $result === null || is_array($result),
                sprintf('Expected array|null for URL: %s, got %s', $url, get_debug_type($result)),
            );
        }
    }

    #[Test]
    public function parseAllModeValuesDoesNotCrashWithRandomInput(): void
    {
        $method = new ReflectionMethod($this->processor, 'parseAllModeValues');

        for ($i = 0; $i < 500; ++$i) {
            $mode = $this->randomModeString();

            $result = $method->invoke($this->processor, $mode);
            // Must return an array
            self::assertIsArray(
                $result,
                sprintf(
                    'Expected array for mode=%s, got %s',
                    $mode,
                    get_debug_type($result),
                ),
            );
        }
    }

    #[Test]
    public function gatherInformationBasedOnUrlHandlesEdgeCases(): void
    {
        $method = new ReflectionMethod($this->processor, 'gatherInformationBasedOnUrl');

        // Edge cases that should NOT match the regex (return defaults)
        $nonMatchingCases = [
            '',
            '/',
            '//',
            '/processed/../../../etc/passwd.w100.jpg',
            "/processed/image.w\x00100.jpg",
            '/processed/image.jpg?foo=bar',
        ];

        foreach ($nonMatchingCases as $url) {
            $result = $method->invoke($this->processor, $url);
            self::assertNull($result, sprintf('Expected null for non-matching edge case URL "%s"', $url));
        }

        // Edge cases that DO match the regex -- verify all parsed components
        $matchingCases = [
            '/processed/' . str_repeat('a', 1000) . '.w100.jpg' => [
                'targetWidth' => 100, 'targetHeight' => null, 'targetQuality' => 100, 'processingMode' => 0, 'extension' => 'jpg',
            ],
            '/processed/image.w0h0q0m0.jpg' => [
                'targetWidth' => 1, 'targetHeight' => 1, 'targetQuality' => 1, 'processingMode' => 0, 'extension' => 'jpg',
            ],
            '/processed/image.w100h200.WEBP' => [
                'targetWidth' => 100, 'targetHeight' => 200, 'targetQuality' => 100, 'processingMode' => 0, 'extension' => 'webp',
            ],
            '/processed/image.w100h200.JpEg' => [
                'targetWidth' => 100, 'targetHeight' => 200, 'targetQuality' => 100, 'processingMode' => 0, 'extension' => 'jpg',
            ],
            '/processed/image.w50h80q75m1.png' => [
                'targetWidth' => 50, 'targetHeight' => 80, 'targetQuality' => 75, 'processingMode' => 1, 'extension' => 'png',
            ],
        ];

        foreach ($matchingCases as $url => $expected) {
            $result = $method->invoke($this->processor, $url);
            self::assertIsArray($result, sprintf('Expected array for edge case URL "%s"', $url));
            self::assertSame($expected['targetWidth'], $result['targetWidth'], sprintf('Wrong targetWidth for "%s"', $url));
            self::assertSame($expected['targetHeight'], $result['targetHeight'], sprintf('Wrong targetHeight for "%s"', $url));
            self::assertSame($expected['targetQuality'], $result['targetQuality'], sprintf('Wrong targetQuality for "%s"', $url));
            self::assertSame($expected['processingMode'], $result['processingMode'], sprintf('Wrong processingMode for "%s"', $url));
            self::assertSame($expected['extension'], $result['extension'], sprintf('Wrong extension for "%s"', $url));

            // Verify resolved paths do not escape the public root
            $resolvedDir = realpath(dirname((string) $result['pathOriginal']));
            $resolved    = $resolvedDir !== false ? $resolvedDir : $result['pathOriginal'];
            $publicPath  = Environment::getPublicPath();

            if ($publicPath !== '') {
                self::assertStringStartsWith(
                    $publicPath,
                    $resolved,
                    sprintf(
                        'Path traversal detected for URL "%s": pathOriginal "%s" escapes public root "%s"',
                        $url,
                        $result['pathOriginal'],
                        $publicPath,
                    ),
                );
            }
        }
    }
}

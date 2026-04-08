<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit;

use Netresearch\NrImageOptimize\Processor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

/**
 * @covers \Netresearch\NrImageOptimize\Processor
 */
class ProcessorTest extends TestCase
{
    private Processor $processor;

    protected function setUp(): void
    {
        parent::setUp();

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            '/var/www',
            '/var/www',
            '/var/www/var',
            '/var/www/config',
            '/var/www/index.php',
            'UNIX',
        );

        // Bypass the constructor which checks for system binaries and calls exit()
        $reflectionClass = new ReflectionClass(Processor::class);
        $this->processor = $reflectionClass->newInstanceWithoutConstructor();
    }

    /**
     * Invoke a private or protected method on the given object via reflection.
     */
    private function callMethod(object $object, string $methodName, mixed ...$args): mixed
    {
        $reflectionMethod = new ReflectionMethod($object, $methodName);

        return $reflectionMethod->invoke($object, ...$args);
    }

    /**
     * Set a private or protected property on the given object via reflection.
     */
    private function setProperty(object $object, string $propertyName, mixed $value): void
    {
        $reflectionProperty = new ReflectionProperty($object, $propertyName);
        $reflectionProperty->setValue($object, $value);
    }

    /**
     * Read a private or protected property from the given object via reflection.
     */
    private function getProperty(object $object, string $propertyName): mixed
    {
        $reflectionProperty = new ReflectionProperty($object, $propertyName);

        return $reflectionProperty->getValue($object);
    }

    /**
     * Regression: passing null as $mode caused a TypeError because the parameter
     * was typed as `string`. This happens when the URL regex in gatherInformationBasedOnUrl
     * does not match and $information[3] is unset.
     *
     * @see OPSCHEM-347
     */
    public function testGetValueFromModeReturnsNullWhenModeIsNull(): void
    {
        $result = $this->callMethod($this->processor, 'getValueFromMode', 'w', null);
        self::assertNull($result);
    }

    /**
     * Regression: crawlers/bots send srcset descriptor values (e.g. " x2") as part of
     * the URL. The URL regex does not match these, which left $information[3] unset and
     * triggered the TypeError in getValueFromMode. After the fix, gatherInformationBasedOnUrl
     * returns false early for non-matching URLs.
     *
     * Example from LIVE log:
     *   /processed/fileadmin/test.w800h532m1q100.jpg%20x2
     */
    public function testGatherInformationReturnsFalseOnSrcsetUrl(): void
    {
        $this->setProperty($this->processor, 'variantUrl', '/processed/fileadmin/test.w800h532m1q100.jpg x2');

        $result = $this->callMethod($this->processor, 'gatherInformationBasedOnUrl');

        self::assertFalse($result);
    }

    /**
     * Regression: same root cause as above, but with comma-separated srcset values
     * where the browser/crawler sends the full srcset attribute content as a single URL.
     *
     * Example from LIVE log:
     *   /processed/fileadmin/test.w540h0m1q100.jpg%2C%20/processed/fileadmin/test.w1080h0m1q100.jpg%20x2
     */
    public function testGatherInformationReturnsFalseOnCommaSeparatedSrcsetUrl(): void
    {
        $this->setProperty(
            $this->processor,
            'variantUrl',
            '/processed/fileadmin/test.w540h0m1q100.jpg, /processed/fileadmin/test.w1080h0m1q100.jpg x2'
        );

        $result = $this->callMethod($this->processor, 'gatherInformationBasedOnUrl');

        self::assertFalse($result);
    }

    /**
     * Ensures the early-return fix does not break normal image processing.
     * A valid processed URL must still be parsed into the correct dimensions,
     * quality, processing mode and file extension.
     */
    public function testGatherInformationReturnsTrueForValidUrl(): void
    {
        $this->setProperty($this->processor, 'variantUrl', '/processed/fileadmin/image.w800h532m1q80.jpg');

        $result = $this->callMethod($this->processor, 'gatherInformationBasedOnUrl');

        self::assertTrue($result);
        self::assertSame(800, $this->getProperty($this->processor, 'targetWidth'));
        self::assertSame(532, $this->getProperty($this->processor, 'targetHeight'));
        self::assertSame(80, $this->getProperty($this->processor, 'targetQuality'));
        self::assertSame(1, $this->getProperty($this->processor, 'processingMode'));
        self::assertSame('jpg', $this->getProperty($this->processor, 'extension'));
    }
}

<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit\Command;

use Generator;
use Netresearch\NrImageOptimize\Command\AbstractImageCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tests for the helper methods on AbstractImageCommand.
 *
 * The abstract base is exercised through a thin fixture subclass that exposes
 * the protected helpers. Database-backed methods (countImages, iterateViaIndex)
 * are intentionally out of scope here and live in the functional test layer.
 *
 * No CoversClass attribute: final/abstract classes cannot always be instrumented
 * by PCOV on PHP 8.5, causing PHPUnit coverage warnings.
 */
class AbstractImageCommandTest extends TestCase
{
    private AbstractImageCommandTestFixture $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new AbstractImageCommandTestFixture();
    }

    /**
     * @return array<string, array{0: array<int|string, mixed>, 1: list<int>}>
     */
    public static function parseStorageUidsOptionProvider(): array
    {
        return [
            'empty array'                      => [[], []],
            'single numeric string'            => [['5'], [5]],
            'single numeric int'               => [[7], [7]],
            'comma separated'                  => [['1,2,3'], [1, 2, 3]],
            'multiple values'                  => [['1', '2', '3'], [1, 2, 3]],
            'mixed comma and individual'       => [['1,2', '3'], [1, 2, 3]],
            'non-numeric parts skipped'        => [['1,abc,2'], [1, 2]],
            'non-scalar value skipped'         => [[['nested'], '4'], [4]],
            'empty strings stripped'           => [[',,,1,,,'], [1]],
            'whitespace trimmed around values' => [[' 1 , 2 '], [1, 2]],
            'null value skipped'               => [[null, '3'], [3]],
            'bool scalar value preserved'      => [[true, '5'], [1, 5]],
            'negative numbers parsed'          => [['-1,-2'], [-1, -2]],
        ];
    }

    /**
     * @param array<int|string, mixed> $input
     * @param list<int>                $expected
     */
    #[Test]
    #[DataProvider('parseStorageUidsOptionProvider')]
    public function parseStorageUidsOptionReturnsExpectedList(array $input, array $expected): void
    {
        self::assertSame($expected, $this->command->callParseStorageUidsOption($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: int, 2: int}>
     */
    public static function getIntOptionProvider(): array
    {
        return [
            'numeric string'            => ['42', 10, 42],
            'plain int'                 => [7, 10, 7],
            'float'                     => [3.7, 10, 3],
            'null uses default'         => [null, 10, 10],
            'non-numeric string'        => ['foo', 10, 10],
            'bool true uses default'    => [true, 10, 10],
            'bool false uses default'   => [false, 10, 10],
            'empty string uses default' => ['', 10, 10],
            'numeric zero'              => ['0', 10, 0],
            'negative numeric'          => ['-5', 10, -5],
        ];
    }

    #[Test]
    #[DataProvider('getIntOptionProvider')]
    public function getIntOptionReturnsExpectedInt(mixed $value, int $default, int $expected): void
    {
        self::assertSame($expected, $this->command->callGetIntOption($value, $default));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function extractUidProvider(): array
    {
        return [
            'missing key'    => [[], 0],
            'numeric int'    => [['uid' => 42], 42],
            'numeric string' => [['uid' => '17'], 17],
            'null value'     => [['uid' => null], 0],
            'non-numeric'    => [['uid' => 'abc'], 0],
            'zero'           => [['uid' => 0], 0],
            'float value'    => [['uid' => 3.9], 3],
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    #[Test]
    #[DataProvider('extractUidProvider')]
    public function extractUidReturnsExpectedUid(array $record, int $expected): void
    {
        self::assertSame($expected, $this->command->callExtractUid($record));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function buildLabelProvider(): array
    {
        return [
            'with identifier'            => [['identifier' => 'path/to/file.jpg'], 'path/to/file.jpg'],
            'without identifier uid int' => [['uid' => 42], '#42'],
            'empty identifier uses uid'  => [['identifier' => '', 'uid' => 9], '#9'],
            'non-string identifier'      => [['identifier' => 123, 'uid' => 9], '#9'],
            'non-scalar uid'             => [['uid' => ['nested']], '#?'],
            'missing both'               => [[], '#?'],
            'numeric uid as string'      => [['uid' => '123'], '#123'],
            'bool uid'                   => [['uid' => true], '#1'],
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    #[Test]
    #[DataProvider('buildLabelProvider')]
    public function buildLabelReturnsExpectedLabel(array $record, string $expected): void
    {
        self::assertSame($expected, $this->command->callBuildLabel($record));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function shortenLabelProvider(): array
    {
        return [
            'short text unchanged'    => ['abc', 10, 'abc'],
            'exactly maxLen'          => ['abcdef', 6, 'abcdef'],
            'long text ellipsized'    => ['abcdefghij', 5, 'ab…ij'],
            'collapses whitespace'    => ["foo\n\tbar", 20, 'foo bar'],
            'maxLen three kept as-is' => ['abc', 3, 'abc'],
            'maxLen three truncated'  => ['abcdef', 3, 'abc'],
            'maxLen two truncated'    => ['abcd', 2, 'ab'],
            'maxLen zero truncated'   => ['abcd', 0, ''],
            'maxLen four ellipsized'  => ['abcdefgh', 4, 'a…h'],
            'very long text'          => [str_repeat('x', 100), 11, 'xxxxx…xxxxx'],
        ];
    }

    #[Test]
    #[DataProvider('shortenLabelProvider')]
    public function shortenLabelReturnsExpectedString(string $text, int $maxLen, string $expected): void
    {
        self::assertSame($expected, $this->command->callShortenLabel($text, $maxLen));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function formatMbGbProvider(): array
    {
        return [
            'zero bytes'  => [0, '0.00 MB / 0.00 GB'],
            'one mb'      => [1048576, '1.00 MB / 0.00 GB'],
            'one gb'      => [1073741824, '1,024.00 MB / 1.00 GB'],
            'half mb'     => [524288, '0.50 MB / 0.00 GB'],
            'large value' => [5368709120, '5,120.00 MB / 5.00 GB'],
        ];
    }

    #[Test]
    #[DataProvider('formatMbGbProvider')]
    public function formatMbGbReturnsExpectedFormattedString(int $bytes, string $expected): void
    {
        self::assertSame($expected, $this->command->callFormatMbGb($bytes));
    }

    #[Test]
    public function createProgressReturnsProgressBarAndMessageMax(): void
    {
        $input  = new ArrayInput([]);
        $output = new BufferedOutput();
        $io     = new SymfonyStyle($input, $output);

        $result = $this->command->callCreateProgress($io, 42);

        self::assertArrayHasKey('progress', $result);
        self::assertArrayHasKey('messageMax', $result);
        self::assertInstanceOf(ProgressBar::class, $result['progress']);
        self::assertGreaterThanOrEqual(10, $result['messageMax']);
        self::assertSame(42, $result['progress']->getMaxSteps());
    }

    #[Test]
    public function createProgressEnforcesMinimumMessageMaxOfTen(): void
    {
        // Even on a pathologically narrow terminal the messageMax must be >= 10.
        $input  = new ArrayInput([]);
        $output = new BufferedOutput();
        $io     = new SymfonyStyle($input, $output);

        $result = $this->command->callCreateProgress($io, 1);

        self::assertGreaterThanOrEqual(10, $result['messageMax']);
        self::assertInstanceOf(ProgressBar::class, $result['progress']);
    }
}

/**
 * Test-only fixture that exposes the protected helper methods.
 *
 * Lives in the same file to keep the fixture adjacent to the test cases
 * that drive it and to avoid leaking a test-only class into the global
 * test autoload namespace.
 */
final class AbstractImageCommandTestFixture extends AbstractImageCommand
{
    /**
     * @param array<int|string, mixed> $values
     *
     * @return list<int>
     */
    public function callParseStorageUidsOption(array $values): array
    {
        return $this->parseStorageUidsOption($values);
    }

    public function callGetIntOption(mixed $value, int $default): int
    {
        return $this->getIntOption($value, $default);
    }

    /**
     * @param array<string,mixed> $record
     */
    public function callExtractUid(array $record): int
    {
        return $this->extractUid($record);
    }

    /**
     * @param array<string,mixed> $record
     */
    public function callBuildLabel(array $record): string
    {
        return $this->buildLabel($record);
    }

    public function callShortenLabel(string $text, int $maxLen): string
    {
        return $this->shortenLabel($text, $maxLen);
    }

    public function callFormatMbGb(int $bytes): string
    {
        return $this->formatMbGb($bytes);
    }

    /**
     * @return array{progress: ProgressBar, messageMax: int}
     */
    public function callCreateProgress(SymfonyStyle $io, int $count): array
    {
        return $this->createProgress($io, $count);
    }

    /**
     * Expose the protected countImages contract shape via a generator.
     *
     * Included only so the fixture keeps AbstractImageCommand's public
     * surface intact for other tests; not invoked here.
     *
     * @param list<int> $onlyStorageUids
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function callIterateViaIndex(array $onlyStorageUids): Generator
    {
        yield from $this->iterateViaIndex($onlyStorageUids);
    }
}

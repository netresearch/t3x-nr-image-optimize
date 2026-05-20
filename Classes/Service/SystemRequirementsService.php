<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Service;

use function array_intersect;
use function array_map;
use function array_values;
use function class_exists;

use Composer\InstalledVersions;

use function escapeshellarg;
use function explode;
use function extension_loaded;
use function file_get_contents;
use function function_exists;
use function gd_info;

use Imagick;

use function implode;
use function in_array;
use function ini_get;
use function is_array;
use function is_file;
use function json_decode;
use function phpversion;
use function shell_exec;

use Throwable;

use function trim;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;

use function version_compare;
use function zend_version;

/**
 * Service to collect and check system requirements for the image optimization extension.
 *
 * @author Sebastian Koschel <sebastian.koschel@netresearch.de>
 */
final class SystemRequirementsService
{
    /**
     * Minimum required PHP version.
     */
    private const MIN_PHP_VERSION = '8.2.0';

    /**
     * Minimum required TYPO3 version.
     */
    private const MIN_TYPO3_VERSION = '13.4.0';

    /**
     * TYPO3 version requirement display string.
     */
    private const TYPO3_VERSION_REQUIREMENT = '>= 13.4';

    /**
     * PHP extensions that are optional (warning if missing, not error).
     *
     * @var list<string>
     */
    private const OPTIONAL_PHP_EXTENSIONS = ['imagick', 'gd'];

    /**
     * PHP extensions checked during requirements gathering.
     *
     * @var list<string>
     */
    private const REQUIRED_PHP_EXTENSIONS = ['imagick', 'gd', 'mbstring', 'exif'];

    /**
     * Image formats considered relevant for the supported formats display.
     *
     * @var list<string>
     */
    private const RELEVANT_IMAGE_FORMATS = ['AVIF', 'WEBP', 'JPEG', 'JPG', 'PNG', 'GIF', 'SVG'];

    /**
     * Composer packages required by this extension.
     *
     * @var list<string>
     */
    private const REQUIRED_COMPOSER_PACKAGES = [
        'intervention/image',
        'intervention/gif',
    ];

    /**
     * CLI tools to check for availability, mapped to their translation key.
     *
     * @var array<string, string>
     */
    private const CLI_TOOLS = [
        'magick'   => 'sysreq.cli.magick',
        'convert'  => 'sysreq.cli.convert',
        'identify' => 'sysreq.cli.identify',
        'gm'       => 'sysreq.cli.gm',
    ];

    /**
     * Collect all system requirements and their current status.
     *
     * @return array<string, array{labelKey: string, items: list<array<string, mixed>>}>
     */
    public function collect(): array
    {
        return [
            'php'      => $this->checkPhp(),
            'imagick'  => $this->checkImagick(),
            'gd'       => $this->checkGd(),
            'composer' => $this->checkComposer(),
            'typo3'    => $this->checkTypo3(),
            'cli'      => $this->checkCliTools(),
        ];
    }

    /**
     * Check PHP version and required extensions.
     *
     * @return array{labelKey: string, items: list<array<string, mixed>>}
     */
    private function checkPhp(): array
    {
        $required = '>= ' . self::MIN_PHP_VERSION;
        $current  = PHP_VERSION;
        $ok       = version_compare($current, self::MIN_PHP_VERSION, '>=');

        $items   = [];
        $items[] = $this->makeItem(
            'sysreq.phpVersion',
            $current,
            $required,
            $ok ? 'success' : 'error',
            details: $this->getPhpDetails(),
        );

        foreach (self::REQUIRED_PHP_EXTENSIONS as $ext) {
            $loaded     = extension_loaded($ext);
            $isOptional = in_array($ext, self::OPTIONAL_PHP_EXTENSIONS, true);
            $status     = $loaded ? 'success' : ($isOptional ? 'warning' : 'error');
            $rawVersion = $loaded ? phpversion($ext) : false;
            $extVersion = $rawVersion !== false ? $rawVersion : null;

            $items[] = $this->makeItem(
                'sysreq.phpExtension',
                null,
                null,
                $status,
                details: $extVersion !== null ? 'Version: ' . $extVersion : null,
                labelArguments: [$ext],
                currentKey: $loaded ? 'sysreq.loaded' : 'sysreq.notLoaded',
            );
        }

        return $this->makeCategory('sysreq.phpRequirements', $items);
    }

    /**
     * Build runtime details string for the PHP version row.
     */
    private function getPhpDetails(): string
    {
        return 'Zend Engine ' . zend_version() . ', SAPI: ' . PHP_SAPI;
    }

    /**
     * Check Imagick extension and ImageMagick capabilities.
     *
     * @return array{labelKey: string, items: list<array<string, mixed>>}
     */
    private function checkImagick(): array
    {
        $items = [];

        if (!extension_loaded('imagick')) {
            $items[] = $this->makeItem(
                'sysreq.imagickVersion',
                null,
                null,
                'warning',
                null,
                [],
                'sysreq.notLoaded',
                'sysreq.recommended',
            );

            return $this->makeCategory('sysreq.imageMagickCategory', $items);
        }

        $rawVersion     = phpversion('imagick');
        $imagickVersion = $rawVersion !== false ? $rawVersion : null;
        $items[]        = $this->makeItem(
            'sysreq.imagickVersion',
            $imagickVersion,
            null,
            'success',
            details: $imagickVersion !== null ? 'PECL imagick ' . $imagickVersion : null,
            currentKey: $imagickVersion === null ? 'sysreq.unknown' : null,
        );

        try {
            $imInfo  = Imagick::getVersion();
            $items[] = $this->makeItem(
                'sysreq.imageMagickVersion',
                $imInfo['versionString'],
                null,
                'success',
                details: $this->getImageMagickBuildDetails($imInfo),
            );

            $formats      = Imagick::queryFormats();
            $totalFormats = count($formats);
            $items[]      = $this->makeFormatSupportItem(
                'sysreq.webpSupport',
                in_array('WEBP', $formats, true),
                'Imagick delegate',
            );
            $items[] = $this->makeFormatSupportItem(
                'sysreq.avifSupport',
                in_array('AVIF', $formats, true),
                'Imagick delegate',
            );

            $relevant = array_values(array_intersect($formats, self::RELEVANT_IMAGE_FORMATS));
            $items[]  = $this->makeItem(
                'sysreq.supportedFormats',
                implode(', ', $relevant),
                null,
                'success',
                details: sprintf('%d total formats: %s', $totalFormats, implode(', ', $formats)),
            );
        } catch (Throwable $e) {
            $items[] = $this->makeItem(
                'sysreq.imageMagickVersion',
                null,
                null,
                'warning',
                $e->getMessage(),
                [],
                'sysreq.unavailable',
            );
        }

        return $this->makeCategory('sysreq.imageMagickCategory', $items);
    }

    /**
     * Check GD library capabilities (fallback driver).
     *
     * @return array{labelKey: string, items: list<array<string, mixed>>}
     */
    private function checkGd(): array
    {
        $items = [];

        if (!extension_loaded('gd')) {
            $items[] = $this->makeItem(
                'sysreq.gdVersion',
                null,
                null,
                'warning',
                null,
                [],
                'sysreq.notLoaded',
                'sysreq.fallback',
            );

            return $this->makeCategory('sysreq.gdCategory', $items);
        }

        $info      = gd_info();
        $gdVersion = array_key_exists('GD Version', $info) && is_string($info['GD Version']) ? $info['GD Version'] : null;
        $items[]   = $this->makeItem(
            'sysreq.gdVersion',
            $gdVersion,
            null,
            'success',
            details: $this->getGdBuildDetails($info),
            currentKey: $gdVersion === null ? 'sysreq.unknown' : null,
        );

        $items[] = $this->makeFormatSupportItem(
            'sysreq.webpSupport',
            (bool) ($info['WebP Support'] ?? false),
            'GD libwebp',
        );
        $items[] = $this->makeFormatSupportItem(
            'sysreq.avifSupport',
            (bool) ($info['AVIF Support'] ?? false),
            'GD libavif',
        );

        return $this->makeCategory('sysreq.gdCategory', $items);
    }

    /**
     * Build ImageMagick build-info string from Imagick::getVersion() output.
     *
     * @param array<string, mixed> $imInfo
     */
    private function getImageMagickBuildDetails(array $imInfo): string
    {
        $parts = [];

        if (array_key_exists('versionString', $imInfo) && is_string($imInfo['versionString'])) {
            $parts[] = $imInfo['versionString'];
        }

        if (array_key_exists('versionNumber', $imInfo) && (is_int($imInfo['versionNumber']) || is_string($imInfo['versionNumber']))) {
            $parts[] = 'versionNumber=' . $imInfo['versionNumber'];
        }

        return implode("\n", $parts);
    }

    /**
     * Build a build-info string from gd_info() output (feature flags only).
     *
     * @param array<array-key, mixed> $info
     */
    private function getGdBuildDetails(array $info): ?string
    {
        $features = [];

        foreach (['FreeType Support', 'JPEG Support', 'PNG Support', 'WBMP Support', 'XPM Support', 'XBM Support', 'WebP Support', 'AVIF Support', 'BMP Support', 'TGA Read Support', 'GIF Read Support', 'GIF Create Support'] as $flag) {
            if (array_key_exists($flag, $info) && $info[$flag] === true) {
                $features[] = $flag;
            }
        }

        return $features === [] ? null : 'Compiled with: ' . implode(', ', $features);
    }

    /**
     * Create a format support item (WebP/AVIF) with consistent status and keys.
     *
     * @param string $labelKey  Translation key for the format label
     * @param bool   $supported Whether the format is supported
     * @param string $provider  Library that would provide this format (e.g. "Imagick delegate", "GD libwebp")
     *
     * @return array<string, mixed>
     */
    private function makeFormatSupportItem(string $labelKey, bool $supported, string $provider): array
    {
        return $this->makeItem(
            $labelKey,
            null,
            null,
            $supported ? 'success' : 'warning',
            details: $supported ? 'Provided by ' . $provider : 'Not provided by ' . $provider,
            currentKey: $supported ? 'sysreq.yes' : 'sysreq.no',
            requiredKey: 'sysreq.optional',
        );
    }

    /**
     * Check Composer dependencies.
     *
     * @return array{labelKey: string, items: list<array<string, mixed>>}
     */
    private function checkComposer(): array
    {
        $items = [];

        foreach (self::REQUIRED_COMPOSER_PACKAGES as $name) {
            $source    = null;
            $installed = class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($name);
            $version   = $installed
                ? (InstalledVersions::getPrettyVersion($name) ?? InstalledVersions::getVersion($name))
                : null;

            if ($installed) {
                $source = InstalledVersions::class;
            } else {
                [$version, $source] = $this->findVersionFromComposerInstalledWithSource($name);
                $installed          = $version !== null;
            }

            $items[] = $this->makeItem(
                null,
                $version,
                null,
                $installed ? 'success' : 'error',
                details: $source !== null ? 'Source: ' . $source : null,
                currentKey: $installed ? null : 'sysreq.notInstalled',
                label: $name,
            );
        }

        return $this->makeCategory('sysreq.composerDeps', $items);
    }

    /**
     * Check TYPO3 version.
     *
     * @return array{labelKey: string, items: list<array<string, mixed>>}
     */
    private function checkTypo3(): array
    {
        $versionInfo = new Typo3Version();
        $typo3       = $versionInfo->getVersion();
        $branch      = $versionInfo->getBranch();
        $ok          = version_compare($typo3, self::MIN_TYPO3_VERSION, '>=');

        return $this->makeCategory('sysreq.typo3Requirements', [
            $this->makeItem(
                'sysreq.typo3Version',
                $typo3,
                self::TYPO3_VERSION_REQUIREMENT,
                $ok ? 'success' : 'error',
                details: 'Branch ' . $branch,
            ),
        ]);
    }

    /**
     * Check CLI tools availability (optional).
     *
     * @return array{labelKey: string, items: list<array<string, mixed>>}
     */
    private function checkCliTools(): array
    {
        $items            = [];
        $disableFunctions = ini_get('disable_functions');

        if ($disableFunctions === false) {
            $disableFunctions = '';
        }

        $disabled     = array_map(trim(...), explode(',', $disableFunctions));
        $execAllowed  = function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);
        $disabledList = array_values(array_filter($disabled, static fn (string $f): bool => $f !== ''));

        $items[] = $this->makeItem(
            'sysreq.execAvailability',
            null,
            null,
            $execAllowed ? 'success' : 'warning',
            details: $disabledList === [] ? 'disable_functions is empty' : 'disable_functions: ' . implode(', ', $disabledList),
            currentKey: $execAllowed ? 'sysreq.enabled' : 'sysreq.disabled',
            requiredKey: 'sysreq.optional',
        );

        foreach (self::CLI_TOOLS as $cmd => $labelKey) {
            $res = $this->checkBinaryAvailability($cmd, $execAllowed);

            if ($res['available'] === null) {
                $items[] = $this->makeItem(
                    $labelKey,
                    null,
                    null,
                    'warning',
                    details: 'Cannot probe binary while exec is disabled',
                    currentKey: 'sysreq.unavailable',
                    requiredKey: 'sysreq.optional',
                );

                continue;
            }

            $items[] = $this->makeItem(
                $labelKey,
                $res['version'],
                null,
                $res['available'] ? 'success' : 'warning',
                details: $this->buildCliDetails($res),
                currentKey: $res['available'] ? 'sysreq.found' : 'sysreq.notFound',
                requiredKey: 'sysreq.optional',
            );
        }

        return $this->makeCategory('sysreq.cliTools', $items);
    }

    /**
     * Build the inline details string for a CLI tool item (binary path + full version output).
     *
     * @param array{available: bool|null, version: string|null, path?: string|null, fullVersion?: string|null} $res
     */
    private function buildCliDetails(array $res): ?string
    {
        if ($res['available'] !== true) {
            return null;
        }

        $parts = [];

        if (array_key_exists('path', $res) && is_string($res['path']) && $res['path'] !== '') {
            $parts[] = 'Path: ' . $res['path'];
        }

        if (array_key_exists('fullVersion', $res) && is_string($res['fullVersion']) && $res['fullVersion'] !== '') {
            $parts[] = $res['fullVersion'];
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    /**
     * Check whether a CLI binary is available and retrieve its version.
     *
     * Returned shape:
     *   - `available`: true|false|null (null = exec disabled)
     *   - `version`: short first-line version string for the table cell
     *   - `path`: absolute path to the binary (only when available)
     *   - `fullVersion`: complete (multi-line) version output for the tooltip
     *
     * @param string $cmd         Command name to look up
     * @param bool   $execAllowed Whether exec/shell_exec is permitted
     *
     * @return array{available: bool|null, version: string|null, path?: string|null, fullVersion?: string|null}
     */
    private function checkBinaryAvailability(string $cmd, bool $execAllowed): array
    {
        if (!$execAllowed) {
            return ['available' => null, 'version' => 'n/a'];
        }

        $path = shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null');
        $path = trim((string) $path);

        if ($path === '') {
            return ['available' => false, 'version' => null];
        }

        $ver = shell_exec(escapeshellarg($cmd) . ' -version 2>&1');
        $ver = trim((string) $ver);

        if ($ver === '') {
            $ver = shell_exec(escapeshellarg($cmd) . ' --version 2>&1');
            $ver = trim((string) $ver);
        }

        $firstLine = $ver !== '' ? trim(strtok($ver, "\n")) : '';

        return [
            'available'   => true,
            'version'     => $firstLine !== '' ? $firstLine : null,
            'path'        => $path,
            'fullVersion' => $ver !== '' ? $ver : null,
        ];
    }

    /**
     * Find package version and report which fallback source provided it.
     *
     * @param string $package Composer package name
     *
     * @return array{0: string|null, 1: string|null} [version, source-label]
     */
    private function findVersionFromComposerInstalledWithSource(string $package): array
    {
        $version = $this->findVersionFromInstalledJson($package);

        if ($version !== null) {
            return [$version, 'vendor/composer/installed.json'];
        }

        $version = $this->findVersionFromComposerLock($package);

        if ($version !== null) {
            return [$version, 'composer.lock'];
        }

        return [null, null];
    }

    /**
     * Search for a package version in vendor/composer/installed.json.
     *
     * @param string $package Composer package name
     *
     * @return string|null Version string or null
     */
    private function findVersionFromInstalledJson(string $package): ?string
    {
        $installedJson = Environment::getProjectPath() . '/vendor/composer/installed.json';

        if (!is_file($installedJson)) {
            return null;
        }

        $raw = @file_get_contents($installedJson);

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return null;
        }

        $packages = $data['packages'] ?? $data;

        if (!is_array($packages)) {
            return null;
        }

        foreach ($packages as $p) {
            if (is_array($p) && ($p['name'] ?? '') === $package) {
                $version = $p['pretty_version'] ?? $p['version'] ?? null;

                return is_string($version) ? $version : null;
            }
        }

        return null;
    }

    /**
     * Search for a package version in composer.lock.
     *
     * @param string $package Composer package name
     *
     * @return string|null Version string or null
     */
    private function findVersionFromComposerLock(string $package): ?string
    {
        $lock = Environment::getProjectPath() . '/composer.lock';

        if (!is_file($lock)) {
            return null;
        }

        $raw = @file_get_contents($lock);

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return null;
        }

        foreach (['packages', 'packages-dev'] as $key) {
            $packages = $data[$key] ?? [];

            if (!is_array($packages)) {
                continue;
            }

            foreach ($packages as $p) {
                if (is_array($p) && ($p['name'] ?? '') === $package) {
                    $version = $p['version'] ?? null;

                    return is_string($version) ? $version : null;
                }
            }
        }

        return null;
    }

    /**
     * Create a category array for template rendering.
     *
     * @param string                     $labelKey Translation key for the category header
     * @param list<array<string, mixed>> $items    Category items
     *
     * @return array{labelKey: string, items: list<array<string, mixed>>}
     */
    private function makeCategory(string $labelKey, array $items): array
    {
        return ['labelKey' => $labelKey, 'items' => $items];
    }

    /**
     * Create an item array for template rendering.
     *
     * @param string|null        $labelKey       Translation key for the label
     * @param string|null        $current        Current value (raw string, not translated)
     * @param string|null        $required       Required value (raw string, not translated)
     * @param string             $status         Status: 'success', 'warning', or 'error'
     * @param string|null        $details        Tooltip details
     * @param array<int, string> $labelArguments Arguments for the label translation key
     * @param string|null        $currentKey     Translation key for the current value
     * @param string|null        $requiredKey    Translation key for the required value
     * @param string|null        $label          Raw label (used when no translation key applies)
     *
     * @return array<string, mixed>
     */
    private function makeItem(
        ?string $labelKey,
        ?string $current,
        ?string $required,
        string $status,
        ?string $details = null,
        array $labelArguments = [],
        ?string $currentKey = null,
        ?string $requiredKey = null,
        ?string $label = null,
    ): array {
        return [
            'labelKey'       => $labelKey ?? '',
            'label'          => $label ?? '',
            'labelArguments' => $labelArguments,
            'current'        => $current,
            'currentKey'     => $currentKey,
            'required'       => $required,
            'requiredKey'    => $requiredKey,
            'status'         => $status,
            'details'        => $details,
            'icon'           => $this->iconForStatus($status),
            'badgeClass'     => $this->badgeForStatus($status),
        ];
    }

    /**
     * Get icon identifier for status.
     *
     * @param string $status Status value ('success', 'warning', or 'error')
     *
     * @return string TYPO3 icon identifier
     */
    private function iconForStatus(string $status): string
    {
        return match ($status) {
            'success' => 'status-dialog-ok',
            'warning' => 'status-dialog-warning',
            default   => 'status-dialog-error',
        };
    }

    /**
     * Get badge CSS class for status.
     *
     * @param string $status Status value ('success', 'warning', or 'error')
     *
     * @return string Bootstrap badge CSS class(es)
     */
    private function badgeForStatus(string $status): string
    {
        return match ($status) {
            'success' => 'bg-success',
            'warning' => 'bg-warning text-dark',
            default   => 'bg-danger',
        };
    }
}

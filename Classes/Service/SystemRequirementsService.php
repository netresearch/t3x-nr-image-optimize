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
     * CLI tools to check for availability.
     *
     * @var array<string, string>
     */
    private const CLI_TOOLS = [
        'magick'   => 'CLI: magick',
        'convert'  => 'CLI: convert',
        'identify' => 'CLI: identify',
        'gm'       => 'CLI: gm (GraphicsMagick)',
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
        $items[] = $this->makeItem('sysreq.phpVersion', $current, $required, $ok ? 'success' : 'error');

        foreach (self::REQUIRED_PHP_EXTENSIONS as $ext) {
            $loaded     = extension_loaded($ext);
            $isOptional = in_array($ext, self::OPTIONAL_PHP_EXTENSIONS, true);
            $status     = $loaded ? 'success' : ($isOptional ? 'warning' : 'error');

            $items[] = $this->makeItem(
                'sysreq.phpExtension',
                null,
                null,
                $status,
                null,
                [$ext],
                $loaded ? 'sysreq.loaded' : 'sysreq.notLoaded',
            );
        }

        return $this->makeCategory('sysreq.phpRequirements', $items);
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
            null,
            [],
            $imagickVersion === null ? 'sysreq.unknown' : null,
        );

        try {
            $imInfo  = Imagick::getVersion();
            $items[] = $this->makeItem('sysreq.imageMagickVersion', $imInfo['versionString'], null, 'success');

            $formats = Imagick::queryFormats();
            $items[] = $this->makeFormatSupportItem('sysreq.webpSupport', in_array('WEBP', $formats, true));
            $items[] = $this->makeFormatSupportItem('sysreq.avifSupport', in_array('AVIF', $formats, true));

            $relevant = array_values(array_intersect($formats, self::RELEVANT_IMAGE_FORMATS));
            $items[]  = $this->makeItem('sysreq.supportedFormats', implode(', ', $relevant), null, 'success');
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
        $gdVersion = $info['GD Version'] ?? null;
        $items[]   = $this->makeItem(
            'sysreq.gdVersion',
            $gdVersion,
            null,
            'success',
            null,
            [],
            $gdVersion === null ? 'sysreq.unknown' : null,
        );

        $items[] = $this->makeFormatSupportItem('sysreq.webpSupport', (bool) ($info['WebP Support'] ?? false));
        $items[] = $this->makeFormatSupportItem('sysreq.avifSupport', (bool) ($info['AVIF Support'] ?? false));

        return $this->makeCategory('sysreq.gdCategory', $items);
    }

    /**
     * Create a format support item (WebP/AVIF) with consistent status and keys.
     *
     * @param string $labelKey  Translation key for the format label
     * @param bool   $supported Whether the format is supported
     *
     * @return array<string, mixed>
     */
    private function makeFormatSupportItem(string $labelKey, bool $supported): array
    {
        return $this->makeItem(
            $labelKey,
            null,
            null,
            $supported ? 'success' : 'warning',
            null,
            [],
            $supported ? 'sysreq.yes' : 'sysreq.no',
            'sysreq.optional',
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
            $installed = class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($name);
            $version   = $installed
                ? (InstalledVersions::getPrettyVersion($name) ?? InstalledVersions::getVersion($name))
                : null;

            if (!$installed) {
                $version   = $this->findVersionFromComposerInstalled($name);
                $installed = $version !== null;
            }

            $items[] = $this->makeItem(
                null,
                $version,
                null,
                $installed ? 'success' : 'error',
                null,
                [],
                $installed ? null : 'sysreq.notInstalled',
                null,
                $name,
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
        $typo3 = (new Typo3Version())->getVersion();
        $ok    = version_compare($typo3, self::MIN_TYPO3_VERSION, '>=');

        return $this->makeCategory('sysreq.typo3Requirements', [
            $this->makeItem('sysreq.typo3Version', $typo3, self::TYPO3_VERSION_REQUIREMENT, $ok ? 'success' : 'error'),
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

        $disabled    = array_map(trim(...), explode(',', $disableFunctions));
        $execAllowed = function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);

        $items[] = $this->makeItem(
            'sysreq.execAvailability',
            null,
            null,
            $execAllowed ? 'success' : 'warning',
            null,
            [],
            $execAllowed ? 'sysreq.enabled' : 'sysreq.disabled',
            'sysreq.optional',
        );

        foreach (self::CLI_TOOLS as $cmd => $label) {
            $res    = $this->checkBinaryAvailability($cmd, $execAllowed);
            $status = $res['available'] === true ? 'success' : 'warning';

            $items[] = $this->makeItem(
                null,
                $res['version'],
                null,
                $status,
                null,
                [],
                $res['available'] === true ? 'sysreq.found' : 'sysreq.notFound',
                'sysreq.optional',
                $label,
            );
        }

        return $this->makeCategory('sysreq.cliTools', $items);
    }

    /**
     * Check whether a CLI binary is available and retrieve its version.
     *
     * @param string $cmd         Command name to look up
     * @param bool   $execAllowed Whether exec/shell_exec is permitted
     *
     * @return array{available: bool|null, version: string|null}
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

        return [
            'available' => true,
            'version'   => $ver !== '' ? $ver : null,
        ];
    }

    /**
     * Find package version from composer installed.json or composer.lock.
     *
     * @param string $package Composer package name (e.g., 'vendor/package')
     *
     * @return string|null Package version or null if not found
     */
    private function findVersionFromComposerInstalled(string $package): ?string
    {
        $version = $this->findVersionFromInstalledJson($package);

        if ($version !== null) {
            return $version;
        }

        return $this->findVersionFromComposerLock($package);
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
                return $p['pretty_version'] ?? $p['version'] ?? null;
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
            foreach ($data[$key] ?? [] as $p) {
                if (is_array($p) && ($p['name'] ?? '') === $package) {
                    return $p['version'] ?? null;
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

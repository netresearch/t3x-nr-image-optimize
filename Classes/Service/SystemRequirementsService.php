<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Service;

use Composer\InstalledVersions;
use Imagick;
use Throwable;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * Service to collect and check system requirements for the image optimization extension.
 *
 * @author Sebastian Koschel <sebastian.koschel@netresearch.de>
 */
final class SystemRequirementsService
{
    /**
     * Collect all system requirements and their current status.
     *
     * @return array<string, array<string, mixed>>
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
     * @return array<string, mixed>
     */
    private function checkPhp(): array
    {
        $required = '>= 8.2.0';
        $current  = PHP_VERSION;
        $ok       = version_compare($current, '8.2.0', '>=');

        $items   = [];
        $items[] = $this->makeItem('sysreq.phpVersion', $current, $required, $ok ? 'success' : 'error');

        $extensions = [
            'imagick'  => 'imagick',
            'gd'       => 'gd',
            'mbstring' => 'mbstring',
            'exif'     => 'exif',
        ];

        foreach ($extensions as $ext => $extName) {
            $loaded = extension_loaded($ext);
            if ($ext === 'imagick' || $ext === 'gd') {
                $status = $loaded ? 'success' : 'warning';
            } else {
                $status = $loaded ? 'success' : 'error';
            }

            $items[] = $this->makeItem(
                'sysreq.phpExtension',
                null,
                null,
                $status,
                null,
                [$extName],
                $loaded ? 'sysreq.loaded' : 'sysreq.notLoaded',
            );
        }

        return $this->makeCategory('sysreq.phpRequirements', $items);
    }

    /**
     * Check Imagick extension and ImageMagick capabilities.
     *
     * @return array<string, mixed>
     */
    private function checkImagick(): array
    {
        $items      = [];
        $hasImagick = extension_loaded('imagick');

        if ($hasImagick) {
            $imagickVersion = phpversion('imagick') !== false ? phpversion('imagick') : null;
            $items[]        = $this->makeItem('sysreq.imagickVersion', $imagickVersion, null, 'success', null, [], $imagickVersion === null ? 'sysreq.unknown' : null);

            try {
                $imInfo    = Imagick::getVersion();
                $imVersion = $imInfo['versionString'];
                $items[]   = $this->makeItem('sysreq.imageMagickVersion', $imVersion, null, 'success');

                $formats = Imagick::queryFormats();
                $avif    = in_array('AVIF', $formats, true);
                $webp    = in_array('WEBP', $formats, true);
                $items[] = $this->makeItem(
                    'sysreq.webpSupport',
                    null,
                    null,
                    $webp ? 'success' : 'warning',
                    null,
                    [],
                    $webp ? 'sysreq.yes' : 'sysreq.no',
                    'sysreq.optional',
                );
                $items[] = $this->makeItem(
                    'sysreq.avifSupport',
                    null,
                    null,
                    $avif ? 'success' : 'warning',
                    null,
                    [],
                    $avif ? 'sysreq.yes' : 'sysreq.no',
                    'sysreq.optional',
                );

                $relevant = array_values(array_intersect($formats, ['AVIF', 'WEBP', 'JPEG', 'JPG', 'PNG', 'GIF', 'SVG']));
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
        } else {
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
        }

        return $this->makeCategory('sysreq.imageMagickCategory', $items);
    }

    /**
     * Check GD library capabilities (fallback driver).
     *
     * @return array<string, mixed>
     */
    private function checkGd(): array
    {
        $items = [];

        if (extension_loaded('gd')) {
            $info      = gd_info();
            $gdVersion = $info['GD Version'] ?? null;
            $items[]   = $this->makeItem('sysreq.gdVersion', $gdVersion, null, 'success', null, [], $gdVersion === null ? 'sysreq.unknown' : null);

            $webp    = (bool) ($info['WebP Support'] ?? false);
            $avif    = (bool) ($info['AVIF Support'] ?? false);
            $items[] = $this->makeItem(
                'sysreq.webpSupport',
                null,
                null,
                $webp ? 'success' : 'warning',
                null,
                [],
                $webp ? 'sysreq.yes' : 'sysreq.no',
                'sysreq.optional',
            );
            $items[] = $this->makeItem(
                'sysreq.avifSupport',
                null,
                null,
                $avif ? 'success' : 'warning',
                null,
                [],
                $avif ? 'sysreq.yes' : 'sysreq.no',
                'sysreq.optional',
            );
        } else {
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
        }

        return $this->makeCategory('sysreq.gdCategory', $items);
    }

    /**
     * Check Composer dependencies.
     *
     * @return array<string, mixed>
     */
    private function checkComposer(): array
    {
        $packages = [
            'intervention/image' => 'intervention/image',
            'intervention/gif'   => 'intervention/gif',
        ];
        $items = [];

        foreach ($packages as $name => $label) {
            $installed = class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($name);
            $version   = $installed ? (InstalledVersions::getPrettyVersion($name) ?? InstalledVersions::getVersion($name)) : null;

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
                $label,
            );
        }

        return $this->makeCategory('sysreq.composerDeps', $items);
    }

    /**
     * Check TYPO3 version.
     *
     * @return array<string, mixed>
     */
    private function checkTypo3(): array
    {
        $typo3   = (new Typo3Version())->getVersion();
        $ok      = version_compare($typo3, '13.4.0', '>=');
        $items   = [];
        $items[] = $this->makeItem('sysreq.typo3Version', $typo3, '>= 13.4', $ok ? 'success' : 'error');

        return $this->makeCategory('sysreq.typo3Requirements', $items);
    }

    /**
     * Check CLI tools availability (optional).
     *
     * @return array<string, mixed>
     */
    private function checkCliTools(): array
    {
        $items            = [];
        $disableFunctions = ini_get('disable_functions');
        if ($disableFunctions === false) {
            $disableFunctions = '';
        }

        $disabled    = array_map(trim(...), explode(',', $disableFunctions));
        $execAllowed = function_exists('exec') && !in_array('exec', $disabled, true);

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

        $checkBin = static function (string $cmd) use ($execAllowed): array {
            if (!$execAllowed) {
                return ['available' => null, 'version' => 'n/a'];
            }

            $path = trim((string) @shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null'));
            if ($path === '') {
                return ['available' => false, 'version' => null];
            }

            $ver = trim((string) @shell_exec(escapeshellarg($cmd) . ' -version 2>&1'));
            if ($ver === '') {
                $ver = trim((string) @shell_exec(escapeshellarg($cmd) . ' --version 2>&1'));
            }

            return ['available' => true, 'version' => $ver !== '' ? $ver : null, 'versionKey' => $ver === '' ? 'sysreq.unknown' : null];
        };

        $cliTools = [
            'magick'   => 'CLI: magick',
            'convert'  => 'CLI: convert',
            'identify' => 'CLI: identify',
            'gm'       => 'CLI: gm (GraphicsMagick)',
        ];

        foreach ($cliTools as $cmd => $label) {
            $res     = $checkBin($cmd);
            $status  = $res['available'] === true ? 'success' : 'warning';
            $items[] = $this->makeItem(
                null,
                $res['version'],
                null,
                $status,
                null,
                [],
                ($res['available'] === true) ? 'sysreq.found' : 'sysreq.notFound',
                'sysreq.optional',
                $label,
            );
        }

        return $this->makeCategory('sysreq.cliTools', $items);
    }

    /**
     * Find package version from composer installed.json or composer.lock.
     */
    private function findVersionFromComposerInstalled(string $package): ?string
    {
        $installedJson = Environment::getProjectPath() . '/vendor/composer/installed.json';
        if (is_file($installedJson)) {
            $data = json_decode((string) file_get_contents($installedJson), true);
            if (is_array($data)) {
                $packages = $data['packages'] ?? $data;
                if (is_array($packages)) {
                    foreach ($packages as $p) {
                        if (is_array($p) && ($p['name'] ?? '') === $package) {
                            return $p['pretty_version'] ?? $p['version'] ?? null;
                        }
                    }
                }
            }
        }

        $lock = Environment::getProjectPath() . '/composer.lock';
        if (is_file($lock)) {
            $data = json_decode((string) file_get_contents($lock), true);
            if (is_array($data)) {
                foreach (['packages', 'packages-dev'] as $key) {
                    foreach ($data[$key] ?? [] as $p) {
                        if (is_array($p) && ($p['name'] ?? '') === $package) {
                            return $p['version'] ?? null;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Create a category array for template rendering.
     *
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<string, mixed>
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

<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Service;

use Composer\InstalledVersions;
use Imagick;
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
            $this->checkPhp(),
            $this->checkImagick(),
            $this->checkGd(),
            $this->checkComposer(),
            $this->checkTypo3(),
            $this->checkCliTools(),
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
        $current = PHP_VERSION;
        $ok = version_compare($current, '8.2.0', '>=');

        $items = [];
        $items[] = $this->makeItem('requirements.php.version', $current, $required, $ok ? 'success' : 'error');

        foreach (['imagick', 'gd', 'mbstring', 'exif'] as $ext) {
            $loaded = extension_loaded($ext);
            if ($ext === 'imagick' || $ext === 'gd') {
                $status = $loaded ? 'success' : 'warning';
            } else {
                $status = $loaded ? 'success' : 'error';
            }
            $items[] = $this->makeItem(
                "requirements.extension.$ext",
                $loaded ? 'Loaded' : 'Not loaded',
                null,
                $status
            );
        }

        return $this->makeCategory('requirements.category.php', $items);
    }

    /**
     * Check Imagick extension and ImageMagick capabilities.
     *
     * @return array<string, mixed>
     */
    private function checkImagick(): array
    {
        $items = [];
        $hasImagick = extension_loaded('imagick');

        if ($hasImagick) {
            $imagickVersion = phpversion('imagick') ?: 'unknown';
            $items[] = $this->makeItem('requirements.imagick.extension', $imagickVersion, null, 'success');

            try {
                $im = new Imagick();
                $imInfo = $im->getVersion();
                $imVersion = is_array($imInfo) && isset($imInfo['versionString'])
                    ? $imInfo['versionString']
                    : 'unknown';
                $items[] = $this->makeItem('requirements.imagemagick.version', $imVersion, null, 'success');

                $formats = $im->queryFormats();
                $webp = in_array('WEBP', $formats, true);
                $avif = in_array('AVIF', $formats, true);
                $items[] = $this->makeItem(
                    'requirements.imagick.webp',
                    $webp ? 'Yes' : 'No',
                    'Optional',
                    $webp ? 'success' : 'warning'
                );
                $items[] = $this->makeItem(
                    'requirements.imagick.avif',
                    $avif ? 'Yes' : 'No',
                    'Optional',
                    $avif ? 'success' : 'warning'
                );

                $relevant = array_values(array_intersect($formats, ['AVIF', 'WEBP', 'JPEG', 'JPG', 'PNG', 'GIF', 'SVG']));
                $items[] = $this->makeItem(
                    'requirements.imagemagick.formats',
                    implode(', ', $relevant),
                    null,
                    'success'
                );
            } catch (\Throwable $e) {
                $items[] = $this->makeItem(
                    'requirements.imagemagick.version',
                    'unavailable',
                    null,
                    'warning',
                    $e->getMessage()
                );
            }
        } else {
            $items[] = $this->makeItem('requirements.imagick.extension', 'Not loaded', 'Recommended', 'warning');
        }

        return $this->makeCategory('requirements.category.imagick', $items);
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
            $info = gd_info();
            $items[] = $this->makeItem('requirements.gd.version', $info['GD Version'] ?? 'unknown', null, 'success');

            $webp = (bool)($info['WebP Support'] ?? false);
            $avif = (bool)($info['AVIF Support'] ?? false);
            $items[] = $this->makeItem(
                'requirements.gd.webp',
                $webp ? 'Yes' : 'No',
                'Optional',
                $webp ? 'success' : 'warning'
            );
            $items[] = $this->makeItem(
                'requirements.gd.avif',
                $avif ? 'Yes' : 'No',
                'Optional',
                $avif ? 'success' : 'warning'
            );
        } else {
            $items[] = $this->makeItem('requirements.gd.extension', 'Not loaded', 'Fallback', 'warning');
        }

        return $this->makeCategory('requirements.category.gd', $items);
    }

    /**
     * Check Composer dependencies.
     *
     * @return array<string, mixed>
     */
    private function checkComposer(): array
    {
        $packages = [
            'intervention/image' => 'requirements.composer.intervention_image',
            'intervention/gif' => 'requirements.composer.intervention_gif',
        ];
        $items = [];

        foreach ($packages as $name => $label) {
            $installed = class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($name);
            $version = $installed ? (InstalledVersions::getPrettyVersion($name) ?? InstalledVersions::getVersion($name)) : null;

            if (!$installed) {
                $version = $this->findVersionFromComposerInstalled($name);
                $installed = $version !== null;
            }

            $items[] = $this->makeItem(
                $label,
                $version ?? 'Not installed',
                null,
                $installed ? 'success' : 'error'
            );
        }

        return $this->makeCategory('requirements.category.composer', $items);
    }

    /**
     * Check TYPO3 version.
     *
     * @return array<string, mixed>
     */
    private function checkTypo3(): array
    {
        $typo3 = (new Typo3Version())->getVersion();
        $ok = version_compare($typo3, '13.4.0', '>=');
        $items = [];
        $items[] = $this->makeItem('requirements.typo3.version', $typo3, '>= 13.4', $ok ? 'success' : 'error');

        return $this->makeCategory('requirements.category.typo3', $items);
    }

    /**
     * Check CLI tools availability (optional).
     *
     * @return array<string, mixed>
     */
    private function checkCliTools(): array
    {
        $items = [];
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        $execAllowed = function_exists('exec') && !in_array('exec', $disabled, true);

        $items[] = $this->makeItem(
            'requirements.cli.exec',
            $execAllowed ? 'Enabled' : 'Disabled',
            'Optional',
            $execAllowed ? 'success' : 'warning'
        );

        $checkBin = static function (string $cmd) use ($execAllowed): array {
            if (!$execAllowed) {
                return ['available' => null, 'version' => 'n/a'];
            }

            $path = trim((string)@shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null'));
            if ($path === '') {
                return ['available' => false, 'version' => null];
            }

            $ver = trim((string)@shell_exec(escapeshellarg($cmd) . ' -version 2>&1'));
            if ($ver === '') {
                $ver = trim((string)@shell_exec(escapeshellarg($cmd) . ' --version 2>&1'));
            }

            return ['available' => true, 'version' => $ver ?: 'unknown'];
        };

        foreach (['magick', 'convert', 'identify'] as $cmd) {
            $res = $checkBin($cmd);
            $status = $res['available'] === true ? 'success' : 'warning';
            $items[] = $this->makeItem(
                "requirements.cli.$cmd",
                $res['available'] ? 'Found' : 'Not found',
                'Optional',
                $status,
                $res['version']
            );
        }

        $gm = $checkBin('gm');
        $items[] = $this->makeItem(
            'requirements.cli.gm',
            $gm['available'] ? 'Found' : 'Not found',
            'Optional',
            $gm['available'] ? 'success' : 'warning',
            $gm['version']
        );

        return $this->makeCategory('requirements.category.cli', $items);
    }

    /**
     * Find package version from composer installed.json or composer.lock.
     */
    private function findVersionFromComposerInstalled(string $package): ?string
    {
        $installedJson = Environment::getProjectPath() . '/vendor/composer/installed.json';
        if (is_file($installedJson)) {
            $data = json_decode((string)file_get_contents($installedJson), true);
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
            $data = json_decode((string)file_get_contents($lock), true);
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
     * @return array<string, mixed>
     */
    private function makeCategory(string $labelKey, array $items): array
    {
        return ['label' => $labelKey, 'items' => $items];
    }

    /**
     * Create an item array for template rendering.
     *
     * @return array<string, mixed>
     */
    private function makeItem(
        string $labelKey,
        ?string $current,
        ?string $required,
        string $status,
        ?string $details = null
    ): array {
        return [
            'label' => $labelKey,
            'current' => $current,
            'required' => $required,
            'status' => $status,
            'details' => $details,
            'icon' => $this->iconForStatus($status),
            'badgeClass' => $this->badgeForStatus($status),
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
            default => 'status-dialog-error',
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
            default => 'bg-danger',
        };
    }
}

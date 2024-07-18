<?php

/**
 * This file is part of the package netresearch/nr-imperia-import.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize;

use GuzzleHttp\Psr7\Query;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use TYPO3\CMS\Core\Utility\HttpUtility;

class Processor
{
    private readonly string $variantUrl;

    private readonly ImageManager $imageManager;

    private ImageInterface $image;

    private ?int $targetWidth = null;

    private ?int $targetHeight = null;

    private int $targetQuality = 80;

    private string $pathOriginal;

    private string $pathVariant;

    private string $extension;

    private string $query = '';

    private const CMD_OPTIMIZE_JPG = '/usr/bin/jpegoptim --strip-all %s';

    private const CMD_OPTIMIZE_PNG = '/usr/bin/optipng -o2 %s';

    private const CMD_OPTIMIZE_GIF = '/usr/bin/gifsicle --batch -O2 %s';

    public function __construct(string $variantUrl)
    {
        $parsed = parse_url($variantUrl);

        $this->variantUrl = $parsed['path'];
        $this->query      = $parsed['query'] ?? null;

        $this->imageManager = new ImageManager(
            new Driver()
        );
    }

    private function validateUrl(): bool
    {
        $protocol = ($_SERVER['HTTPS'] == 'on') ? 'https://' : 'http://';
        $domain   = filter_var($_SERVER['HTTP_HOST'], FILTER_VALIDATE_DOMAIN);

        return (bool) filter_var($protocol . $domain . $this->variantUrl, FILTER_VALIDATE_URL);
    }

    private function gatherInformationBasedOnUrl(): void
    {
        $information = [];

        preg_match('/^(.*)\.([0-9whq]+)\.([a-zA-Z]{2,4})$/', $this->variantUrl, $information);

        $this->pathVariant  = __DIR__ . '/processed' . $this->variantUrl;
        $this->pathOriginal = __DIR__ . $information[1];
        $this->extension    = strtolower($information[3]);

        if ($this->extension === 'jpeg') {
            $this->extension = 'jpg';
        }

        $this->targetWidth   = $this->getValueFromMode('w', $information[2]);
        $this->targetHeight  = $this->getValueFromMode('h', $information[2]);
        $this->targetQuality = $this->getValueFromMode('q', $information[2]) ?? 100;
    }

    private function getValueFromMode(string $what, string $mode): ?int
    {
        if ($mode === '') {
            return null;
        }

        $modeMatch = [];

        if (preg_match_all('/(\d+)([hwq]{1})/', $mode, $modeMatch)) {
            $key = array_search($what, $modeMatch[2], true);
            if ($key === false) {
                return null;
            }

            return (int) $modeMatch[1][$key];
        }

        return null;
    }

    private function optimizeImage(): void
    {
        $command = match ($this->extension) {
            'jpg'   => sprintf(self::CMD_OPTIMIZE_JPG, $this->pathOriginal),
            'png'   => sprintf(self::CMD_OPTIMIZE_PNG, $this->pathOriginal),
            'gif'   => sprintf(self::CMD_OPTIMIZE_GIF, $this->pathOriginal),
            default => null,
        };

        if ($command === null) {
            return;
        }

        shell_exec($command);
    }

    private function loadOriginal(): void
    {
        $this->image = $this->imageManager->read($this->pathOriginal);
    }

    private function calculateTargetDimensions(): void
    {
        $aspectRatio = $this->image->width() / $this->image->height();

        if ($this->targetHeight == null) {
            $this->targetHeight = $this->targetWidth / $aspectRatio;
        }

        if ($this->targetWidth == null) {
            $this->targetWidth = $this->targetHeight * $aspectRatio;
        }
    }

    private function isWebpImage(): bool
    {
        return $this->extension === 'webp';
    }

    private function skipWebPCreation(): bool
    {
        if ($this->query === '') {
            return false;
        }

        $query = Query::parse($this->query);

        return isset($query['skipWebP']) && (bool) $query['skipWebP'];
    }

    private function generateWebpVariant(): void
    {
        $this->image->toWebp($this->targetQuality);
        $this->image->save($this->pathVariant . '.webp');
    }

    private function output(): void
    {
        if ($this->skipWebPCreation()) {
            $encodedImage = $this->image->encodeByExtension($this->extension, $this->targetQuality);
            header('Content-Type: ' . $encodedImage->mimetype());
            echo $encodedImage->toString();

            return;
        }

        header('Content-Type: image/webp');
        echo $this->image->toWebp()->toString();
    }

    public function generateAndSend(): void
    {
        if ($this->validateUrl() === false) {
            header(HttpUtility::HTTP_STATUS_500);
            exit('Request-Uri is not a valid url');
        }

        $this->gatherInformationBasedOnUrl();
        $this->optimizeImage();
        $this->loadOriginal();
        $this->calculateTargetDimensions();

        $this->image->coverDown(
            (int) round($this->targetWidth, 0),
            (int) round($this->targetHeight, 0)
        );

        $dir = dirname($this->pathVariant);

        if (is_dir($dir) === false) {
            mkdir($dir, 0775, true);
        }

        $this->image->save($this->pathVariant, $this->targetQuality);

        if ($this->isWebpImage() === false && $this->skipWebPCreation() === false) {
            $this->generateWebpVariant();
        }

        $this->output();
    }
}

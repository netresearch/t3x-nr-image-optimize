<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize;

use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\Locking\Exception\LockCreateException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;

use function array_search;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function header;
use function in_array;
use function is_dir;
use function md5;
use function mkdir;
use function preg_match;
use function preg_match_all;
use function round;
use function strtolower;
use function urldecode;
use function usleep;

class Processor
{
    private RequestInterface $request;

    private string $variantUrl;

    private readonly ImageManager $imageManager;

    private ImageInterface $image;

    private ?int $targetWidth = null;

    private ?int $targetHeight = null;

    private int $targetQuality = 80;

    private int $processingMode;

    private string $pathOriginal;

    private string $pathVariant;

    private string $extension;

    public function __construct()
    {
        $this->checkRequirements();
        $this->imageManager = new ImageManager(
            new Driver()
        );
    }

    public function setRequest(RequestInterface $request): void
    {
        $this->request = $request;
    }

    private function checkRequirements(): void
    {
        if (!file_exists('/usr/bin/jpegoptim')) {
            header(HttpUtility::HTTP_STATUS_500);
            exit('jpegoptim is not installed');
        }

        if (!file_exists('/usr/bin/optipng')) {
            header(HttpUtility::HTTP_STATUS_500);
            exit('optipng is not installed');
        }

        if (!file_exists('/usr/bin/gifsicle')) {
            header(HttpUtility::HTTP_STATUS_500);
            exit('gifsicle is not installed');
        }
    }

    private function gatherInformationBasedOnUrl(): void
    {
        $information = [];

        preg_match(
            '/^(\/processed\/)(.*)\.([0-9whqm]+)\.([a-zA-Z0-9]{1,4})$/',
            $this->variantUrl,
            $information
        );

        $basePath = Environment::getPublicPath();

        $this->pathVariant  = $basePath . $this->variantUrl;
        $this->pathOriginal = $basePath . '/' . ($information[2] ?? '') . '.' . ($information[4] ?? '');
        $this->extension    = strtolower($information[4] ?? '');

        if ($this->extension === 'jpeg') {
            $this->extension = 'jpg';
        }

        $this->targetWidth    = $this->getValueFromMode('w', $information[3] ?? null);
        $this->targetHeight   = $this->getValueFromMode('h', $information[3] ?? null);
        $this->targetQuality  = $this->getValueFromMode('q', $information[3]) ?? 100;
        $this->processingMode = $this->getValueFromMode('m', $information[3]) ?? 0;
    }

    private function getValueFromMode(string $what, string $mode): ?int
    {
        if ($mode === '') {
            return null;
        }

        $modeMatch = [];

        if (preg_match_all('/([hwqm]{1})(\d+)/', $mode, $modeMatch)) {
            $key = array_search($what, $modeMatch[1], true);
            if ($key === false) {
                return null;
            }

            return (int) $modeMatch[2][$key];
        }

        return null;
    }

    private function loadOriginal(): void
    {
        $locker = $this->getLocker($this->pathOriginal . '-read');

        $count = 0;

        while ($locker->acquire() === false) {
            usleep(100000);
            ++$count;
            if ($count === 10) {
                header(HttpUtility::HTTP_STATUS_503);
                echo 'Image is currently being processed';
                exit;
            }
        }

        $this->image = $this->imageManager->read($this->pathOriginal);
        $locker->release();
    }

    private function calculateTargetDimensions(): void
    {
        $aspectRatio = $this->image->width() / $this->image->height();

        if ($this->targetHeight == null) {
            $this->targetHeight = (int) round($this->targetWidth / $aspectRatio, 0);
        }

        if ($this->targetWidth == null) {
            $this->targetWidth = (int) round($this->targetHeight * $aspectRatio, 0);
        }
    }

    private function isWebpImage(): bool
    {
        return $this->extension === 'webp';
    }

    private function isAvifImage(): bool
    {
        return $this->extension === 'avif';
    }

    private function generateWebpVariant(): void
    {
        $this->image->toWebp($this->targetQuality);
        $this->image->save($this->pathVariant . '.webp');
    }

    private function generateAvifVariant(): void
    {
        $this->image->toAvif($this->targetQuality);
        $this->image->save($this->pathVariant . '.avif');
    }

    private function output(): void
    {
        if ($this->hasVariantFor('avif')) {
            header('Content-Type: image/avif');
            echo file_get_contents($this->pathVariant . '.avif');

            return;
        }

        if ($this->hasVariantFor('webp')) {
            header('Content-Type: image/webp');
            echo file_get_contents($this->pathVariant . '.webp');

            return;
        }

        header('Content-Type: ' . $this->image->origin()->mimetype());
        echo $this->image->encodeByExtension($this->extension, $this->targetQuality)->toString();
    }

    public function generateAndSend(): void
    {
        $this->variantUrl = urldecode($this->request->getUri()->getPath());

        $locker = $this->getLocker($this->variantUrl . '-process');

        $lockCount = 0;
        while ($locker->acquire() === false) {
            usleep(100000);
            ++$lockCount;
            if ($lockCount === 10) {
                header(HttpUtility::HTTP_STATUS_503);
                echo 'Image is currently being processed';
                exit;
            }
        }

        $this->gatherInformationBasedOnUrl();

        if (!file_exists($this->pathOriginal)) {
            header(HttpUtility::HTTP_STATUS_404);
            exit;
        }

        $this->loadOriginal();
        $this->calculateTargetDimensions();
        $this->processImage();

        $dir = dirname($this->pathVariant);

        if (is_dir($dir) === false) {
            mkdir($dir, 0775, true);
        }

        if ($this->determineBrowserSupport('image/avif') && $this->isAvifImage() === false) {
            $this->generateAvifVariant();
        } elseif ($this->determineBrowserSupport('image/webp') && $this->isWebpImage() === false) {
            $this->generateWebpVariant();
        } else {
            $this->image->save($this->pathVariant, $this->targetQuality);
        }

        $this->output();

        $locker->release();
    }

    private function processImage(): void
    {
        match ($this->processingMode) {
            1 => $this->image->scale(
                $this->targetWidth,
                $this->targetHeight
            ),
            default => $this->image->cover(
                $this->targetWidth,
                $this->targetHeight
            ),
        };
    }

    private function hasVariantFor(string $variant): bool
    {
        return file_exists($this->pathVariant . '.' . $variant);
    }

    /**
     * @param string $key
     *
     * @return LockingStrategyInterface
     *
     * @throws LockCreateException
     */
    public function getLocker(string $key): LockingStrategyInterface
    {
        $lockFactory = GeneralUtility::makeInstance(LockFactory::class);

        return $lockFactory->createLocker('nr_image_optimize-' . md5($key));
    }

    private function determineBrowserSupport(string $mimeType): bool
    {
        $serverRequest = ServerRequestFactory::fromGlobals();

        $accept   = $serverRequest->getHeader('accept')[0] ?? '';
        $variants = explode(',', $accept);

        return in_array($mimeType, $variants, true);
    }
}

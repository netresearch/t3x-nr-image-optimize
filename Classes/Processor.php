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
use Intervention\Image\Image;
use Intervention\Image\ImageManager;
use TYPO3\CMS\Core\Utility\HttpUtility;

class Processor
{
    /**
     * @var string Url of the requested variant
     */
    private $variantUrl;

    /**
     * @var ImageManager
     */
    private $imageManager;

    /**
     * @var Image
     */
    private $image;

    /**
     * @var ?integer
     */
    private $targetWidth;

    /**
     * @var ?integer
     */
    private $targetHeight;

    /**
     * @var int
     */
    private $targetQuality = 80;

    /**
     * @var string
     */
    private $pathOriginal;

    /**
     * @var string
     */
    private $pathVariant;

    /**
     * @var string
     */
    private $extension;

    /**
     * @var string
     */
    private $query;

    /**
     * Constants for the shell command executed.
     */
    private const CMD_OPTIMIZE_JPG = '/usr/bin/jpegoptim --strip-all %s';
    private const CMD_OPTIMIZE_PNG = '/usr/bin/optipng -o2 %s';
    private const CMD_OPTIMIZE_GIF = '/usr/bin/gifsicle --batch -O2 %s';

    /**
     * @param string $variantUrl
     */
    public function __construct(string $variantUrl)
    {
        $parsed = parse_url($variantUrl);

        $this->variantUrl = $parsed['path'];
        $this->query      = $parsed['query'] ?? null;

        $this->imageManager = new ImageManager(
            new \Intervention\Image\Drivers\Imagick\Driver()
        );
    }

    /**
     * Validates if the url is really a valid url.
     *
     * @return bool
     */
    private function validateUrl(): bool
    {
        $protocol = ($_SERVER['HTTPS'] == 'on') ? 'https://' : 'http://';
        $domain   = filter_var($_SERVER['HTTP_HOST'], FILTER_VALIDATE_DOMAIN);

        return (bool) filter_var($protocol . $domain . $this->variantUrl, FILTER_VALIDATE_URL);
    }

    /**
     * Gather al information which depending on the variant url.
     *
     * @return void
     */
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

    /**
     * Parse the mode string and return the requested value.
     *
     * @param string $what Eg. w,h,q (width, height, quality)
     * @param string $mode mode string e.g. 16h9w30q
     *
     * @return int|null
     */
    private function getValueFromMode(string $what, string $mode): ?int
    {
        if (empty($mode)) {
            return null;
        }

        $modeMatch = [];

        if (preg_match_all('/([0-9]+)([hwq]{1})/', $mode, $modeMatch)) {
            $key = array_search($what, $modeMatch[2]);
            if ($key === false) {
                return null;
            }

            return $modeMatch[1][$key];
        }

        return null;
    }

    /**
     * Optimize the source image depending on its format.
     *
     * @return void
     */
    private function optimizeImage(): void
    {
        switch ($this->extension) {
            case 'jpg':
                $command = sprintf(self::CMD_OPTIMIZE_JPG, $this->pathOriginal);
                break;
            case 'png':
                $command = sprintf(self::CMD_OPTIMIZE_PNG, $this->pathOriginal);
                break;
            case 'gif':
                $command = sprintf(self::CMD_OPTIMIZE_GIF, $this->pathOriginal);
                break;
            default:
                $command = null;
        }

        if (!$command) {
            return;
        }

        shell_exec($command);
    }

    /**
     * Load the original image.
     */
    private function loadOriginal()
    {
        $this->image = $this->imageManager->read($this->pathOriginal);
    }

    /**
     * Calculates the missing dimension value for the target image.
     *
     * @return void
     */
    private function calculateTargetDimensions()
    {
        $aspectRatio = $this->image->width() / $this->image->height();

        if (empty($this->targetHeight)) {
            $this->targetHeight = $this->targetWidth / $aspectRatio;
        }

        if (empty($this->targetWidth)) {
            $this->targetWidth = $this->targetHeight * $aspectRatio;
        }
    }

    /**
     * Returns true if the source image is already a webp image.
     *
     * @return bool
     */
    private function isWebpImage(): bool
    {
        return $this->extension === 'webp';
    }

    private function skipWebPCreation(): bool
    {
        if ($this->query === null) {
            return false;
        }

        $query = Query::parse($this->query);

        return isset($query['skipWebP']) && (bool) $query['skipWebP'] === true;
    }

    /**
     * Creates a webp variant of a image.
     *
     * @return void
     */
    private function generateWebpVariant(): void
    {
        $this->image->toWebp($this->targetQuality);
        $this->image->save($this->pathVariant . '.webp');
    }

    /**
     * Streams the image to the browser.
     *
     * @return void
     */
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

    /**
     * Generate the variant and send it to the browser.
     *
     * @return void
     */
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
            round($this->targetWidth),
            round($this->targetHeight)
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

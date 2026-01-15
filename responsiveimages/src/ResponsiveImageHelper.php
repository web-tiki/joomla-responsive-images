<?php
declare(strict_types=1);

/**
 * @package     Joomla.Plugin
 * @subpackage  System.ResponsiveImages
 *
 * @copyright   (C) 2026 web-tiki
 * @license     GNU General Public License version 3 or later
 */

namespace WebTiki\Plugin\System\ResponsiveImages;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Filesystem\Folder;
use Imagick;
use RuntimeException;
use Throwable;

final class ResponsiveImageHelper
{
    /* ==========================================================
     * Path & URL helpers
     * ========================================================== */

    private static function encodeUrlPath(string $path): string
    {
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);

        return implode(
            '/',
            array_map('rawurlencode', explode('/', $path))
        );
    }

    /* ==========================================================
     * Image helpers
     * ========================================================== */

    private static function calculateCropBox(
        int $originalWidth,
        int $originalHeight,
        float $aspectRatio
    ): array {
        $originalRatio = $originalHeight / $originalWidth;

        if ($originalRatio > $aspectRatio) {
            $targetHeight = (int) round($originalWidth * $aspectRatio);

            return [
                $originalWidth,
                $targetHeight,
                0,
                (int) (($originalHeight - $targetHeight) / 2),
            ];
        }

        $targetWidth = (int) round($originalHeight / $aspectRatio);

        return [
            $targetWidth,
            $originalHeight,
            (int) (($originalWidth - $targetWidth) / 2),
            0,
        ];
    }

    /* ==========================================================
     * SVG helpers
     * ========================================================== */

    private static function getSvgDimensions(string $absolutePath): array
    {
        $width  = null;
        $height = null;

        $svgContent = @file_get_contents($absolutePath);
        if (!$svgContent) {
            return [$width, $height];
        }

        if (
            preg_match(
                '/<svg[^>]+width=["\']?([\d.]+)(?:px)?["\']?[^>]*height=["\']?([\d.]+)(?:px)?["\']?/i',
                $svgContent,
                $matches
            )
        ) {
            $width  = (int) $matches[1];
            $height = (int) $matches[2];
        } elseif (
            preg_match(
                '/viewBox=["\']?([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)["\']?/i',
                $svgContent,
                $matches
            )
        ) {
            $width  = (int) $matches[3];
            $height = (int) $matches[4];
        }

        return [$width, $height];
    }

    /* ==========================================================
     * Error handling
     * ========================================================== */

    private static function fail(string $message): array
    {
        return [
            'ok'    => false,
            'error' => $message,
            'data'  => null,
        ];
    }

    /* ==========================================================
     * Public API
     * ========================================================== */

    public static function getProcessedData(
        mixed $imageField,
        array $options = []
    ): array {
        if (!$imageField) {
            return self::fail('Empty image field');
        }

        /* ---------------- Plugin defaults ---------------- */

        $plugin = PluginHelper::getPlugin('system', 'responsiveimages');

        // Plugin disabled → do nothing
        if (!is_object($plugin)) {
            return [
                'ok'    => true,
                'error' => null,
                'data'  => null,
            ];
        }

        $pluginParams = [];
        if (isset($plugin->params)) {
            $pluginParams = json_decode((string) $plugin->params, true) ?: [];
        }

        $defaultOptions = [
            'lazy'        => (bool) ($pluginParams['lazy'] ?? true),
            'webp'        => (bool) ($pluginParams['webp'] ?? true),
            'sizes'       => (string) ($pluginParams['sizes'] ?? '100vw'),
            'widths'      => array_map(
                'intval',
                explode(',', $pluginParams['widths'] ?? '480,800,1200,1600,2000,2560')
            ),
            'quality'     => max(1, min(100, (int) ($pluginParams['quality'] ?? 75))),
            'outputDir'   => trim($pluginParams['thumb_dir'] ?? 'responsive-images', '/'),
            'alt'         => '',
            'aspectRatio' => null,
        ];

        $options = array_merge($defaultOptions, $options);

        if (empty($options['widths']) || !is_array($options['widths'])) {
            return self::fail('Invalid widths configuration');
        }

        /* ---------------- Normalize field ---------------- */

        if (is_string($imageField)) {
            $imageField = json_decode($imageField, true);
        } elseif (is_object($imageField)) {
            $imageField = (array) $imageField;
        }

        if (!is_array($imageField)) {
            return self::fail('Invalid image field');
        }

        $sourcePath = $imageField['imagefile'] ?? '';

        // Alt text priority resolution (filename fallback later)
        $altText = '';
        if (!empty($imageField['alt_text'])) {
            $altText = trim((string) $imageField['alt_text']);
        } elseif (!empty($options['alt'])) {
            $altText = trim((string) $options['alt']);
        }

        if (!$sourcePath) {
            return [
                'ok'    => true,
                'error' => null,
                'data'  => null,
            ];
        }

        /* ---------------- Normalize path ---------------- */

        $sourcePath = rawurldecode(explode('#', $sourcePath, 2)[0]);
        $sourcePath = str_replace('\\', '/', $sourcePath);

        $isAbsolutePath =
            str_starts_with($sourcePath, '/') ||
            preg_match('#^[A-Za-z]:/#', $sourcePath);

        if (!$isAbsolutePath) {
            $sourcePath = rtrim(JPATH_ROOT, '/') . '/' . ltrim($sourcePath, '/');
        }

        $sourcePath = preg_replace('#/images/images/#', '/images/', $sourcePath, 1);

        $absolutePath = realpath($sourcePath);
        if ($absolutePath === false) {
            return self::fail('Image file not found on disk: ' . $sourcePath);
        }

        $pathInfo  = pathinfo($absolutePath);
        $extension = strtolower($pathInfo['extension'] ?? '');

        // Final alt fallback: filename
        if ($altText === '') {
            $altText = $pathInfo['filename'] ?? '';
        }

        /* ---------------- SVG handling ---------------- */

        if ($extension === 'svg') {
            [$width, $height] = self::getSvgDimensions($absolutePath);

            $publicSrc = '/' . ltrim(
                str_replace(DIRECTORY_SEPARATOR, '/', str_replace(JPATH_ROOT, '', $absolutePath)),
                '/'
            );

            return [
                'ok'    => true,
                'error' => null,
                'data'  => [
                    'isSvg'   => true,
                    'src'     => $publicSrc,
                    'alt'     => htmlspecialchars($altText, ENT_QUOTES),
                    'width'   => $width ?: null,
                    'height'  => $height ?: null,
                    'loading' => $options['lazy'] ? 'loading="lazy"' : '',
                ],
            ];
        }

        /* ---------------- Raster image ---------------- */

        [$originalWidth, $originalHeight] = getimagesize($absolutePath) ?: [0, 0];
        if (!$originalWidth || !$originalHeight) {
            return self::fail('Unable to read image dimensions');
        }

        $aspectRatio = $originalHeight / $originalWidth;
        $cropBox     = null;

        if (is_numeric($options['aspectRatio']) && $options['aspectRatio'] > 0) {
            $cropBox = self::calculateCropBox(
                $originalWidth,
                $originalHeight,
                (float) $options['aspectRatio']
            );

            [$originalWidth, $originalHeight] = [$cropBox[0], $cropBox[1]];
            $aspectRatio = $originalHeight / $originalWidth;
        }

        /* ---------------- Output directory ---------------- */

        $imagesRootPath = realpath(JPATH_ROOT . '/images');
        if (!$imagesRootPath || !str_starts_with($absolutePath, $imagesRootPath)) {
            return self::fail('Image is outside /images directory');
        }

        $relativeDirectory = trim(
            str_replace($imagesRootPath, '', dirname($absolutePath)),
            DIRECTORY_SEPARATOR
        );

        $thumbnailsBasePath = JPATH_ROOT . '/images/' . $options['outputDir'];
        if ($relativeDirectory !== '') {
            $thumbnailsBasePath .= '/' . $relativeDirectory;
        }

        if (!is_dir($thumbnailsBasePath) && !mkdir($thumbnailsBasePath, 0755, true)) {
            return self::fail('Failed to create thumbnail directory: ' . $thumbnailsBasePath);
        }

        $hash = substr(md5($absolutePath . filemtime($absolutePath)), 0, 8);

        $srcsetEntries      = [];
        $webpSrcsetEntries  = [];
        $resizeJobs         = [];

        $targetWidths = array_unique(array_map('intval', $options['widths']));
        sort($targetWidths);

        foreach ($targetWidths as $targetWidth) {
            if ($targetWidth > $originalWidth) {
                $targetWidth = $originalWidth;
            }

            $targetHeight = (int) round($targetWidth * $aspectRatio);
            if ($targetWidth <= 0 || $targetHeight <= 0) {
                continue;
            }

            if (in_array($targetWidth, array_column($resizeJobs, 2), true)) {
                continue;
            }

            $baseFilename = sprintf(
                '%s/%s-%s-q%d-%dx%d',
                $thumbnailsBasePath,
                $pathInfo['filename'],
                $hash,
                $options['quality'],
                $targetWidth,
                $targetHeight
            );

            $thumbnailPath = $baseFilename . '.' . $extension;
            $webpPath      = $baseFilename . '.webp';

            $resizeJobs[] = [$thumbnailPath, $webpPath, $targetWidth, $targetHeight];

            $srcsetEntries[] =
                '/' . self::encodeUrlPath(str_replace(JPATH_ROOT . '/', '', $thumbnailPath))
                . " {$targetWidth}w";

            if ($options['webp']) {
                $webpSrcsetEntries[] =
                    '/' . self::encodeUrlPath(str_replace(JPATH_ROOT . '/', '', $webpPath))
                    . " {$targetWidth}w";
            }
        }

        if (empty($resizeJobs)) {
            return self::fail('No valid thumbnail sizes generated');
        }

        if (!class_exists(Imagick::class)) {
            return self::fail('Imagick extension is not available');
        }

        $lockHandle = fopen($thumbnailsBasePath . '/.lock', 'c');
        if (!$lockHandle) {
            return self::fail('Unable to create lock file');
        }

        if (flock($lockHandle, LOCK_EX)) {
            try {
                $image = new Imagick($absolutePath);
            } catch (Throwable) {
                fclose($lockHandle);
                return self::fail('Imagick failed to load image');
            }

            if ($cropBox) {
                $image->cropImage(...$cropBox);
                $image->setImagePage(0, 0, 0, 0);
            }

            foreach ($resizeJobs as [$thumbnailPath, $webpPath, $targetWidth, $targetHeight]) {
                if (!is_file($thumbnailPath)) {
                    $tmpPath = tempnam(dirname($thumbnailPath), 'ri_');
                    $resizedImage = clone $image;

                    $resizedImage->resizeImage(
                        $targetWidth,
                        $targetHeight,
                        Imagick::FILTER_LANCZOS,
                        1,
                        true
                    );

                    $resizedImage->setImageFormat($extension);
                    $resizedImage->setImageCompressionQuality($options['quality']);
                    $resizedImage->writeImage($tmpPath);

                    rename($tmpPath, $thumbnailPath);
                    chmod($thumbnailPath, 0644);

                    $resizedImage->clear();
                }

                if ($options['webp'] && !is_file($webpPath)) {
                    $tmpPath = tempnam(dirname($webpPath), 'ri_');
                    $resizedImage = clone $image;

                    $resizedImage->resizeImage(
                        $targetWidth,
                        $targetHeight,
                        Imagick::FILTER_LANCZOS,
                        1,
                        true
                    );

                    $resizedImage->setImageFormat('webp');
                    $resizedImage->setImageCompressionQuality($options['quality']);
                    $resizedImage->writeImage($tmpPath);

                    rename($tmpPath, $webpPath);
                    chmod($webpPath, 0644);

                    $resizedImage->clear();
                }
            }

            $image->clear();
            flock($lockHandle, LOCK_UN);
        }

        fclose($lockHandle);

        $fallbackSrc = explode(' ', end($srcsetEntries))[0];

        return [
            'ok'    => true,
            'error' => null,
            'data'  => [
                'isSvg'      => false,
                'srcset'     => implode(', ', $srcsetEntries),
                'webpSrcset' => $options['webp'] ? implode(', ', $webpSrcsetEntries) : null,
                'fallback'   => $fallbackSrc,
                'sizes'      => htmlspecialchars($options['sizes'], ENT_QUOTES),
                'alt'        => htmlspecialchars($altText, ENT_QUOTES),
                'width'      => $originalWidth,
                'height'     => $originalHeight,
                'loading'    => $options['lazy'] ? 'loading="lazy"' : '',
                'decoding'   => 'decoding="async"',
                'extension'  => $extension,
            ],
        ];
    }
}

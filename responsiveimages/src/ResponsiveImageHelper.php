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
use Imagick;
use Throwable;

final class ResponsiveImageHelper
{
    /* ==========================================================
     * Public API
     * ========================================================== */
    public static function getProcessedData(mixed $imageField, array $callOptions = []): array
    {
        
        [$options, $isDebug] = self::mergeCallDefaultOptions($callOptions);

        $debug = new DebugTimeline($isDebug,'');
        $debug->log('ResponsiveImageHelper', 'plugin_start');
        $debug->log('ResponsiveImageHelper', 'Merged default and call options', ['imageField' => $imageField, 'options' => $options]);

        if (!$imageField) {
            return self::fail('Empty image field', $debug);
        }

        if (empty($options['widths']) || !is_array($options['widths'])) {
            return self::fail('Invalid widths option', $debug);
        }

        $image = OriginalImage::getOriginalImageData($imageField, $options['alt'], $debug);

        if (!$image || !is_file($image->filePath)) {
            return self::fail('Original file not accessible', $debug);
        }

        /* ---------- SVG fast path ---------- */
        if ($image->pathInfo['extension'] === 'svg') {
            $debug->log('ResponsiveImageHelper', 'SVG detected, taking quick exit');
            return self::buildFinalResponse(true, null, [
                'isSvg' => true,
                'src' => $image->path,
                'alt' => $image->alt,
                'width' => $image->width,
                'height' => $image->height,
                'loading' => $options['lazy'] ? 'loading="lazy"' : '',
                'mime_type' => $image->mimeType,
                'imageClass' => $options['imageClass'] ?? '',
            ], $debug);
        }

        /* ---------- Aspect ratio ---------- */
        $cropBox = [];
        $thumbRatio = $image->ratio;
        
        if (is_numeric($options['aspectRatio']) && $options['aspectRatio'] > 0) {
            $thumbRatio = $options['aspectRatio'];
            $cropBox = self::calculateAspectRatioCropBox($image->width, $image->height, $image->ratio, (float)$options['aspectRatio'], $debug);
        }

        /* ---------- Thumbnails directory ---------- */
        $thumbsBase = self::buildThumbDirectory($image->filePath, $debug);
        if (!$thumbsBase) {
            return self::fail('Cannot create thumbnails directory', $debug);
        }

        /* ---------- Requested Thumbnails ---------- */
        $thumbnailSet = new ThumbnailSet(self::buildRequestedThumbnails(
            $options,
            $image,
            $thumbRatio,
            $thumbsBase,
            $debug
        ));

        if (count($thumbnailSet) === 0) {
            return self::fail('No thumbnails requested', $debug);
        }


        /* ---------- Manifest ---------- */
        $manifestPath = "{$thumbsBase}/{$image->pathInfo['filename']}-{$image->hash}.manifest.json";

        $manifest = ThumbnailManifest::load($manifestPath, $image, $debug);

        $generationSet = $manifest->getMissingThumbnails($thumbnailSet, $debug);

        if ($manifest->needsBuild() || count($generationSet) > 0 ) {

            ThumbnailGenerator::generateThumbnails(
                $image,
                $generationSet,
                $cropBox,
                $debug
            );            

            $manifest->update(
                $image,
                $generationSet,
                $debug
            );

            $manifest->save($debug);
        }


        /* ---------- Srcsets ---------- */
        return self::buildFinalResponse(true, null, [
            'isSvg' => false,
            'fallback' => $thumbnailSet->getFallBack() ?? $image->path,
            'srcset' => implode(', ', $thumbnailSet->getSrcset()),
            'sizes' => htmlspecialchars($options['sizes'], ENT_QUOTES),
            'alt' => $image->alt,
            'width' => $image->width,
            'height' => $image->height,
            'loading' => $options['lazy'] ? 'loading="lazy"' : '',
            'mime_type' => $image->mimeType,
            'imageClass' => $options['imageClass'] ?? '',
        ], $debug);
    }

    /* ==========================================================
     * Requested thumbnails
     * ========================================================== */
    private static function buildRequestedThumbnails(array $options, OriginalImage $image, float $thumbRatio, string $thumbsBase, DebugTimeline $debug): array
    {
        $thumbnails = [];
        $widths = array_unique(array_map('intval', $options['widths']));
        sort($widths);

        $generatedOriginalSize = false;

        foreach ($widths as $w) {

            // If requested width is bigger than original and we haven't generated original-size yet
            if ($w > $image->width && !$generatedOriginalSize) {
                $w = $image->width;
                $h = (int) round($w / $thumbRatio);
                $generatedOriginalSize = true; // mark that original-size thumb has been added
            } elseif ($w > $image->width) {
                // skip widths bigger than original once we added original-size thumb
                continue;
            } else {
                $h = (int) round($w / $thumbRatio);
            }

            if ($w <= 0 || $h <= 0) continue;

            // get requested thumbnail extension
            $thumbExtension = $image->pathInfo['extension'];
            if($options['webp']) {
                $thumbExtension = 'webp';
            }

            $base = sprintf('%s/%s-%s-q%d-%dx%d.%s', 
                $thumbsBase, 
                $image->pathInfo['filename'], 
                $image->hash, 
                $options['quality'], 
                $w, 
                $h, 
                $thumbExtension
            );
            $thumbnails[] = new RequestedThumbnail(
                $w,
                $h,
                $base,
                $thumbExtension ?? null,
                $options['quality'],
                RequestedThumbnail::ROLE_THUMBNAIL,
            );
        }

        // FALLBACK get the biggest width under 1280 but not bigger than original image
        $largestRequested = max($widths);
        $fallBackWidth = min($largestRequested, $image->width, 1280);
        $fallBackHeight = (int) round($fallBackWidth / $thumbRatio);

          
        $fallBackBase = sprintf('%s/%s-%s-q%d-%dx%d.%s', 
            $thumbsBase, 
            $image->pathInfo['filename'], 
            $image->hash, 
            $options['quality'], 
            $fallBackWidth, 
            $fallBackHeight,
            $image->pathInfo['extension'],
        );
        $thumbnails[] = new RequestedThumbnail(
            $fallBackWidth,
            $fallBackHeight,
            $fallBackBase,
            $image->pathInfo['extension'],
            $options['quality'],
            RequestedThumbnail::ROLE_FALLBACK,
        );

        $debug->log('ResponsiveImageHelper', 'Requested thumbnails built', $thumbnails);

        return $thumbnails;
    }

    /* ==========================================================
     * Utilities
     * ========================================================== */
    private static function mergeCallDefaultOptions(array $callOptions): array
    {
        $plugin = PluginHelper::getPlugin('system', 'responsiveimages');
        $params = json_decode((string) ($plugin->params ?? '{}'), true) ?: [];

        $defaults = [
            'lazy' => (bool) ($params['lazy'] ?? true),
            'webp' => (bool) ($params['webp'] ?? true),
            'sizes' => (string) ($params['sizes'] ?? '100vw'),
            'widths' => array_map('intval', explode(',', $params['widths'] ?? '480,800,1200')),
            'quality' => max(1, min(100, (int) ($params['quality'] ?? 75))),
            'aspectRatio' => null,
            'debug' => (bool) ($params['debug'] ?? false),
            'imageClass' => '',
            'alt' => '',
        ];


        $options = array_merge($defaults, $callOptions);
        return [$options, (bool)$options['debug']];
    }

    private static function calculateAspectRatioCropBox(int $w, int $h, float $originalRatio, float $thumbRatio, DebugTimeline $debug): array
    {
        
        if ($originalRatio > $thumbRatio) {
            $targetHeight = (int) round($w / $thumbRatio);

            $debug->log('ResponsiveImageHelper', 'Calculating aspect ratio, $originalRatio > $thumbRatio', [
                'Original ratio' => $originalRatio,
                'Thumbnail ratio'=> $thumbRatio,
                'Original width' => $w,
                'Thumbnail width' => $w,
                'Orginal height' => $h,
                'Thumbnail height' => $targetHeight
            ]);

            return [
                'width' => $w,
                'height' => $targetHeight
            ];
        }

        $targetWidth = (int) round($h * $thumbRatio);

        $debug->log('ResponsiveImageHelper', 'Calculating aspect ratio, $originalRatio <= $thumbRatio', [
            'Original ratio' => $originalRatio,
            'Thumbnail ratio'=> $thumbRatio,
            'Original width' => $w,
            'Thumbnail width' => $targetWidth,
            'Orginal height' => $h,
            'Thumbnail height' => $h
        ]);

        return [
            'width' => $targetWidth,
            'height' => $h,
        ];
    }

    private static function buildThumbDirectory(string $filePath, DebugTimeline $debug): string
    {
        $imagesRoot = realpath(JPATH_ROOT . '/images');
        $dir = realpath(dirname($filePath));

        if (!$imagesRoot || !$dir) {
            $debug->log('ResponsiveImageHelper', 'buildThumbDirectory: realpath failed');
            return '';
        }

        // Ensure the image is actually inside /images
        if (strpos($dir, $imagesRoot) !== 0) {
            $debug->log('ResponsiveImageHelper', 'buildThumbDirectory: image not inside images folder');
            return '';
        }

        // Compute relative path safely
        $rel = ltrim(
            substr($dir, strlen($imagesRoot)),
            DIRECTORY_SEPARATOR
        );

        $base = JPATH_ROOT . '/media/ri-responsiveimages'
            . ($rel ? '/' . str_replace(DIRECTORY_SEPARATOR, '/', $rel) : '');

        if (!is_dir($base) && !mkdir($base, 0755, true)) {
            $debug->log('ResponsiveImageHelper', 'buildThumbDirectory: mkdir failed', ['base' => $base]);
            return '';
        }

        return $base;
    }


    private static function fail(string $msg, DebugTimeline $debug): array
    {
        $debug->log('ResponsiveImageHelper', 'Plugin failed, existing. Error : ' . $msg);
        return [
            'ok' => false, 
            'error' => $msg, 
            'data' => null, 
            'debug' => $debug->export(),
        ];
    }

    private static function buildFinalResponse(bool $ok, ?string $error, ?array $data, DebugTimeline $debug): array
    {
        $debug->log('ResponsiveImageHelper', 'Returning data', ['response' => $data]);
        return [
            'ok'=>$ok, 
            'error'=>$error, 
            'data'=>$data, 
            'debug' => $debug->export(),
        ];
    }
}
 
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
    private const MAX_DIMENSION = 4096;      // hard safety cap
    private const MAX_PIXELS    = 16_000_000; // ~16 megapixels
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
                'source_mime_type' => '',
                'imageClass' => $options['imageClass'] ?? '',
            ], $debug);
        }

        /* ---------- Aspect ratio ---------- */
        $cropBox = [];
        $thumbRatio = $image->ratio;
        
        if (is_numeric($options['aspectRatio']) && $options['aspectRatio'] > 0) {
            $thumbRatio = $options['aspectRatio'];
            $cropBox = self::calculateAspectRatioCropBox($image->width, $image->height, $image->ratio, (float)$options['aspectRatio'], $debug);
        } else {
            $cropBox = [
                'width'  => $image->width,
                'height' => $image->height,
                'x'      => 0,
                'y'      => 0,
            ];
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
                $options,
                $debug
            );            

            $manifest->update(
                $image,
                $generationSet,
                $debug
            );

            $manifest->save($debug);
        }

        // Get fallback thumb if it exists
        $fallbackThumb = $thumbnailSet->getFallBack();

        // get the mimetype for the source element
        if($options['webp']) {
            $mimeType = 'image/webp';
        } else {
            $mimeType = $image->mimeType;
        }
        

        /* ---------- Srcsets ---------- */
        return self::buildFinalResponse(true, null, [
            'isSvg' => false,
            'fallback' => $fallbackThumb ? $fallbackThumb->getUrl() : $image->path,
            'srcset' => implode(', ', $thumbnailSet->getSrcset()),
            'sizes' => htmlspecialchars($options['sizes'], ENT_QUOTES),
            'alt' => $image->alt,
            'width' => $fallbackThumb ? $fallbackThumb->width  : $image->width,
            'height' => $fallbackThumb ? $fallbackThumb->height : $image->height,
            'loading' => $options['lazy'] ? 'loading="lazy"' : '',
            'source_mime_type' => $mimeType,
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

            // Clamp width to original
            if ($w > $image->width) {
                if ($generatedOriginalSize) {
                    continue;
                }
                $w = $image->width;
                $generatedOriginalSize = true;
            }
        
            if ($w <= 0) {
                continue;
            }
        
            $h = (int) round($w / $thumbRatio);
        
            // 🔒 Never exceed original height
            if ($h > $image->height) {
                $h = $image->height;
                $w = (int) round($h * $thumbRatio);
            }
        
            // 🔒 Hard safety caps
            if (
                $w > self::MAX_DIMENSION ||
                $h > self::MAX_DIMENSION ||
                ($w * $h) > self::MAX_PIXELS
            ) {
                $debug->log(
                    'ResponsiveImageHelper',
                    'Skipping thumbnail (safety limits)',
                    ['w' => $w, 'h' => $h]
                );
                continue;
            }
        
            // get requested thumbnail extension
            $thumbExtension = $options['webp'] ? 'webp' : $image->pathInfo['extension'];
        
            $base = sprintf(
                '%s/%s-%s-q%d-%dx%d.%s',
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
                $thumbExtension,
                $options['quality'],
                RequestedThumbnail::ROLE_THUMBNAIL,
            );
        }
        

        // FALLBACK get the biggest width under 1280 but not bigger than original image
        $largestRequested = max($widths);
        
        $fallBackWidth = min($largestRequested, $image->width, 1280);
        $fallBackHeight = (int) round($fallBackWidth / $thumbRatio);

        // 🔒 Ensure fallback never exceeds original height
        if ($fallBackHeight > $image->height) {
            $fallBackHeight = $image->height;
            $fallBackWidth  = (int) round($fallBackHeight * $thumbRatio);
        }


          
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

        // normalize alt field
        if (!$options['alt']) {
            $options['alt'] = "";
        }
        
        return [$options, (bool)$options['debug']];
    }

    private static function calculateAspectRatioCropBox(int $w, int $h, float $originalRatio, float $thumbRatio, DebugTimeline $debug): array
    {

        $cropBox = [
            'width'  => $w,
            'height' => $h,
            'x'      => 0,
            'y'      => 0,
        ];
        
        if ($originalRatio > $thumbRatio) {
            // Image is wider than target ratio → crop width
            $targetWidth = (int) round($h * $thumbRatio);
            $cropBox['width']  = $targetWidth;
            $cropBox['height'] = $h;
            $cropBox['x']      = (int) round(($w - $targetWidth) / 2); // center crop horizontally
            $cropBox['y']      = 0;
        } elseif ($originalRatio < $thumbRatio) {
            // Image is taller than target ratio → crop height
            $targetHeight = (int) round($w / $thumbRatio);
            $cropBox['width']  = $w;
            $cropBox['height'] = $targetHeight;
            $cropBox['x']      = 0;
            $cropBox['y']      = (int) round(($h - $targetHeight) / 2); // center crop vertically
        }
        
        // else: ratios match, full image → no crop, x=y=0

        $debug->log('ResponsiveImageHelper', 'Calculated CropBox', [
            'Original ratio' => $originalRatio,
            'Thumbnail ratio'=> $thumbRatio,
            'CropBox' => $cropBox
        ]);

        return $cropBox;
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
 
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

     private static function fail(
        string $message, 
        bool $debugMode = false, 
        array $debugLog = [], 
        array $finalOptions = [], 
        array $manifestLog = [] ): array
     {
         return [
            'ok'         => false,
            'error'      => $message,
            'data'       => null,
            'debug_data' => $debugMode ? ['log' => $debugLog, 'options' => $finalOptions, 'manifestLog' => $manifestLog] : null,
         ];
     }

    /* ==========================================================
     * Manifest handling
     * ========================================================== */
    private static function getManifest(string $dir): array
    {
        $file = $dir . '/manifest.json';
        if (is_file($file)) {
            $content = @file_get_contents($file);
            return $content ? (json_decode($content, true) ?: []) : [];
        }
        return [];
    }

    private static function updateManifest(string $dir, string $key, array $data): void
    {
        $file = $dir . '/manifest.json';
        $fp = fopen($file, 'c+');
        if ($fp && flock($fp, LOCK_EX)) {
            $content = '';
            while (!feof($fp)) { $content .= fread($fp, 8192); }
            $manifest = json_decode($content, true) ?: [];
            $manifest[$key] = $data;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($manifest));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /* ==========================================================
     * Public API
     * ========================================================== */

     public static function getProcessedData(
        mixed $imageField,
        array $options = []
    ): array {
        
        $debugLog = [];
        $debugLog[] = "Initializing plugin.";
        $manifestLog = [];

        /* ---------------- Plugin defaults ---------------- */

        $plugin = PluginHelper::getPlugin('system', 'responsiveimages');

        // Plugin disabled → do nothing
        if (!is_object($plugin)) {
            return [
                'ok'    => true,
                'error' => 'Plugin object not found',
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
            'quality'     => (int) ($pluginParams['quality'] ?? 75),
            'alt'         => '',
            'aspectRatio' => null,
            'debug'       => (bool) ($pluginParams['debug'] ?? false),
            'image-class' => '',
        ];

        // Merge options
        $options = array_merge($defaultOptions, $options);
        $isDebug = (bool) $options['debug'];

        // normalize quality field 1-100
        $options['quality'] = max(1, min(100, (int) $options['quality']));

        if ($isDebug) $debugLog[] = "Configuration merged successfully.";

        if (!$imageField) {
            return self::fail('Input image field is empty.', $isDebug, $debugLog, $options);
        }

        if (empty($options['widths']) || !is_array($options['widths'])) {
            return self::fail('Invalid widths configuration provided.', $isDebug, $debugLog, $options);
        }

        /* ---------------- Normalize  image field ---------------- */

        if (is_string($imageField)) {
            $imageField = json_decode($imageField, true);
        } elseif (is_object($imageField)) {
            $imageField = (array) $imageField;
        }

        if (!is_array($imageField)) {
            return self::fail('Invalid image field format (expected string, object, or array).', $isDebug, $debugLog, $options);
        }

        $sourcePath = $imageField['imagefile'] ?? '';

        $altText = '';
        if (!empty($imageField['alt_text'])) {
            $altText = trim((string) $imageField['alt_text']);
        } elseif (!empty($options['alt'])) {
            $altText = trim((string) $options['alt']);
        }

        if (!$sourcePath) {
             return self::fail('No image path found in field.', $isDebug, $debugLog, $options);
        }

        /* ---------------- Path Resolution ---------------- */

        $sourcePath = rawurldecode(explode('#', $sourcePath, 2)[0]);
        $sourcePath = str_replace('\\', '/', $sourcePath);

        if (strpos($sourcePath, '..') !== false || strpos($sourcePath, "\0") !== false) {
            return self::fail('Invalid path: contains traversal sequences.', $isDebug, $debugLog, $options);
        }

        $isAbsolutePath =
            str_starts_with($sourcePath, '/') ||
            preg_match('#^[A-Za-z]:/#', $sourcePath);

        if (!$isAbsolutePath) {
            $sourcePath = rtrim(JPATH_ROOT, '/') . '/' . ltrim($sourcePath, '/');
        }

        $sourcePath = preg_replace('#/images/images/#', '/images/', $sourcePath, 1);
        $absolutePath = realpath($sourcePath);
        
        if ($isDebug) $debugLog[] = "Resolving original path: " . $sourcePath;

        if ($absolutePath === false) {
            return self::fail('Original image file not accessible on disk: ' . $sourcePath, $isDebug, $debugLog, $options);
        }

        /* ---------------- EARLY CACHE CHECK ---------------- */

        $mtime = filemtime($absolutePath);
        $imagesRootPath = realpath(JPATH_ROOT . '/images');
        $relativeDirectory = trim(str_replace($imagesRootPath, '', dirname($absolutePath)), DIRECTORY_SEPARATOR);
        $thumbnailsBasePath = JPATH_ROOT . '/media/ri-responsiveimages' . ($relativeDirectory !== '' ? '/' . $relativeDirectory : '');

        // Generate unique key based on source and processing options
        $manifestKey = md5($absolutePath . $mtime . serialize($options['widths']) . $options['quality'] . $options['aspectRatio'] . $options['webp']);
        
        $manifest = self::getManifest($thumbnailsBasePath);

        if ($isDebug) $manifestLog[] = $manifest;

        if (isset($manifest[$manifestKey])) {
            if ($isDebug) $debugLog[] = "Manifest HIT. Early exit triggered.";
            $cachedData = $manifest[$manifestKey];
            
            // Re-apply runtime-specific attributes
            $cachedData['alt']         = htmlspecialchars($altText !== '' ? $altText : pathinfo($absolutePath, PATHINFO_FILENAME), ENT_QUOTES);
            $cachedData['sizes']       = htmlspecialchars($options['sizes'], ENT_QUOTES);
            $cachedData['loading']     = $options['lazy'] ? 'loading="lazy"' : '';
            $cachedData['image-class'] = $options['image-class'];
            $cachedData['decoding']    = 'decoding="async"';

            return [
                'ok'         => true,
                'error'      => null,
                'data'       => $cachedData,
                'debug_data' => $isDebug ? ['log' => $debugLog, 'options' => $options, 'manifestLog' => $manifestLog] : null,
            ];
        }

        /* ---------------- Standard Metadata ---------------- */

        $pathInfo  = pathinfo($absolutePath);
        $extension = strtolower($pathInfo['extension'] ?? '');

        // Fix MIME Type mapping
        $mimeType = 'image/' . $extension;
        if ($extension === 'jpg' || $extension === 'jpeg') {
            $mimeType = 'image/jpeg';
        } elseif ($extension === 'svg') {
            $mimeType = 'image/svg+xml';
        }

        if ($altText === '') {
            $altText = $pathInfo['filename'] ?? '';
        }

        /* ---------------- Output directory ---------------- */

        if (!is_dir($thumbnailsBasePath)) {
            if ($isDebug) $debugLog[] = "Attempting to create directory: " . $thumbnailsBasePath;
            if (!mkdir($thumbnailsBasePath, 0755, true)) {
                return self::fail('Insufficient permissions to create folder: ' . $thumbnailsBasePath, $isDebug, $debugLog, $options);
            }
        }

        /* ---------------- SVG handling ---------------- */

        if ($extension === 'svg') {
            if ($isDebug) $debugLog[] = "SVG file detected. Skipping raster processing.";
            [$width, $height] = self::getSvgDimensions($absolutePath);

            $publicSrc = '/' . ltrim(
                str_replace(DIRECTORY_SEPARATOR, '/', str_replace(JPATH_ROOT, '', $absolutePath)),
                '/'
            );

            $svgData = [
                'isSvg'     => true,
                'src'       => $publicSrc,
                'width'     => $width ?: null,
                'height'    => $height ?: null,
                'mime_type' => $mimeType,
            ];

            self::updateManifest($thumbnailsBasePath, $manifestKey, $svgData);

            // Add runtime props for current request
            $svgData['alt']      = htmlspecialchars($altText, ENT_QUOTES);
            $svgData['loading']  = $options['lazy'] ? 'loading="lazy"' : '';
            $svgData['decoding'] = 'decoding="async"';

            return [
                'ok'    => true,
                'error' => null,
                'data'  => $svgData,
                'debug_data' => $isDebug ? ['log' => $debugLog, 'options' => $options, 'manifestLog' => $manifestLog] : null,            
            ];
        }

        /* ---------------- Raster image ---------------- */

        if ($isDebug) $debugLog[] = "Reading original dimensions via getimagesize().";
        [$originalWidth, $originalHeight] = getimagesize($absolutePath) ?: [0, 0];
        
        if (!$originalWidth || !$originalHeight) {
            return self::fail('Failed to read dimensions of original image. File might be corrupt.', $isDebug, $debugLog, $options);
        }

        $aspectRatio = $originalHeight / $originalWidth;
        $cropBox     = null;

        if (is_numeric($options['aspectRatio']) && $options['aspectRatio'] > 0) {
            if ($isDebug) $debugLog[] = "Calculating crop for Aspect Ratio: " . $options['aspectRatio'];
            $cropBox = self::calculateCropBox($originalWidth, $originalHeight, (float) $options['aspectRatio']);
            [$originalWidth, $originalHeight] = [$cropBox[0], $cropBox[1]];
            $aspectRatio = $originalHeight / $originalWidth;
        }

        $hash = substr(md5($absolutePath . $mtime), 0, 8);
        $srcsetEntries = [];
        $webpSrcsetEntries = [];
        $resizeJobs = [];

        $targetWidths = array_unique(array_map('intval', $options['widths']));
        sort($targetWidths);

        foreach ($targetWidths as $targetWidth) {
            if ($targetWidth > $originalWidth) $targetWidth = $originalWidth;
            $targetHeight = (int) round($targetWidth * $aspectRatio);
            if ($targetWidth <= 0 || $targetHeight <= 0) {
                continue;
            }

            // Deduplication logic
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

            $webpPath = $baseFilename . '.webp';

            $resizeJobs[] = [
                $options['webp'] ? null : $baseFilename . '.' . $extension,
                $options['webp'] ? $webpPath : null,
                $targetWidth,
                $targetHeight,
            ];

            // JPG / PNG srcset ONLY if webp disabled
            if (!$options['webp']) {
                $srcsetEntries[] =
                    '/' . self::encodeUrlPath(str_replace(JPATH_ROOT . '/', '', $baseFilename . '.' . $extension))
                    . " {$targetWidth}w";
            }

            // WEBP srcset
            if ($options['webp']) {
                $webpSrcsetEntries[] =
                    '/' . self::encodeUrlPath(str_replace(JPATH_ROOT . '/', '', $webpPath))
                    . " {$targetWidth}w";
            }

        }

        if (empty($resizeJobs)) {
            return self::fail('No valid thumbnail sizes generated', $isDebug, $debugLog, $options);
        }

        
        /* ---------------- Imagick Processing ---------------- */

        if (!class_exists(Imagick::class)) {
            return self::fail('Imagick extension is not loaded on this server.', $isDebug, $debugLog, $options);
        }

        if (!count(Imagick::queryFormats(strtoupper($extension)))) {
            return self::fail('Server Imagick does not support ' . $extension, $isDebug, $debugLog, $options);
        }

        $lockFile = $thumbnailsBasePath . '/.lock';
        $lockHandle = fopen($lockFile, 'c');

        if (!$lockHandle) {
            return self::fail('Failed to create lock file for thumbnail generation.', $isDebug, $debugLog, $options);
        }

        @chmod($lockFile, 0600);

        if (flock($lockHandle, LOCK_EX)) {
            try {
                $image = new Imagick($absolutePath);
                
                if ($cropBox) {
                    $image->cropImage(...$cropBox);
                    $image->setImagePage(0, 0, 0, 0);
                }

                foreach ($resizeJobs as [$thumbnailPath, $webpPath, $targetWidth, $targetHeight]) {
                    if ($thumbnailPath && !is_file($thumbnailPath)) {
                        $tmpPath = tempnam(dirname($thumbnailPath), 'ri_');
                        $resizedImage = clone $image;

                        $resizedImage->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1, true);
                        $resizedImage->setImageFormat($extension);
                        $resizedImage->setImageCompressionQuality($options['quality']);
                        $resizedImage->writeImage($tmpPath);

                        rename($tmpPath, $thumbnailPath);
                        chmod($thumbnailPath, 0644);
                        if ($isDebug) $debugLog[] = "Created thumbnail: {$targetWidth}w {$extension}";
                        $resizedImage->clear();
                    }

                    if ($options['webp'] && !is_file($webpPath)) {
                        $tmpPath = tempnam(dirname($webpPath), 'ri_');
                        $resizedImage = clone $image;

                        $resizedImage->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1, true);
                        $resizedImage->setImageFormat('webp');
                        $resizedImage->setImageCompressionQuality($options['quality']);
                        $resizedImage->writeImage($tmpPath);

                        rename($tmpPath, $webpPath);
                        chmod($webpPath, 0644);
                        if ($isDebug) $debugLog[] = "Created thumbnail: {$targetWidth}w webp";
                        $resizedImage->clear();
                    }
                }
                $image->clear();
            } catch (Throwable $e) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
                return self::fail('Processing Error: ' . $e->getMessage(), $isDebug, $debugLog, $options);
            }
            flock($lockHandle, LOCK_UN);
        }

        fclose($lockHandle);

        if ($isDebug) $debugLog[] = "Process completed successfully.";

        $normalizedRoot = str_replace(DIRECTORY_SEPARATOR, '/', realpath(JPATH_ROOT));
        $normalizedPath = str_replace(DIRECTORY_SEPARATOR, '/', $absolutePath);

        if (!str_starts_with($normalizedPath, $normalizedRoot)) {
            return self::fail('Resolved image path is outside site root.', $isDebug, $debugLog, $options);
        }
        

        $relativePath = ltrim(
            str_replace($normalizedRoot, '', $normalizedPath),
            '/'
        );

        $fallbackSrc = '/' . self::encodeUrlPath($relativePath);

        /* ---------------- Final Data Assembly ---------------- */

        $data = [
            'isSvg'       => false,
            'sources'   => [
                'srcset'     => $srcsetEntries,     // Storing as Array
                'webpSrcset' => $webpSrcsetEntries, // Storing as Array
            ],
            'fallback'    => $fallbackSrc,
            'width'       => $originalWidth,
            'height'      => $originalHeight,
            'mime_type'   => $options['webp'] ? 'image/webp' : $mimeType,
        ];

        // Store result in manifest for next time
        self::updateManifest($thumbnailsBasePath, $manifestKey, $data);

        // Add dynamic properties for current response
        $data['sizes']       = htmlspecialchars($options['sizes'], ENT_QUOTES);
        $data['alt']         = htmlspecialchars($altText, ENT_QUOTES);
        $data['loading']     = $options['lazy'] ? 'loading="lazy"' : '';
        $data['decoding']    = 'decoding="async"';
        $data['image-class'] = $options['image-class'];

        return [
            'ok'         => true,
            'error'      => null,
            'data'       => $data,
            'debug_data' => $isDebug ? ['log' => $debugLog, 'options' => $options, 'manifestLog' => $manifestLog] : null,
        ];        
    }
}
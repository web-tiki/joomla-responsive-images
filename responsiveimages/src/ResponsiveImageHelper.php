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

     public static function getProcessedData(
        mixed $imageField,
        array $callOptions = []
    ): array {
        
        $debugLog = ["Initializing plugin."];


        /* ---------------- Merge plugin options with call options ---------------- */
        [$options, $isDebug] = self::mergeCallDefaultOptions($callOptions);

        if ($isDebug) $debugLog[] = "Configuration merged successfully.";

        if (!$imageField) {
            return self::fail('Input image field is empty.', $isDebug, $debugLog, $options);
        }

        if (empty($options['widths']) || !is_array($options['widths'])) {
            return self::fail('Invalid widths configuration provided.', $isDebug, $debugLog, $options);
        }


        /* ---------------- Extract data from imageField ---------------- */
        [
            $originalPath,
            $originalFilePath,
            $originalFragment,
            $pathInfo,
            $extension,
            $mimeType, 
            $altText, 
        ] = self::extractImageFieldData($imageField, $options, $isDebug, $debugLog);

        if (!$originalPath || empty($originalPath)) {
            return self::fail('No image path found in field.', $isDebug, $debugLog, $options);
        }
        if ($originalFilePath === false || empty($originalFilePath)) {
            return self::fail('Original image file not accessible on disk: ' . $originalPath, $isDebug, $debugLog, $options);
        }

        // Check if filePath is inside site root
        $normalizedRoot = str_replace(DIRECTORY_SEPARATOR, '/', realpath(JPATH_ROOT));
        $normalizedPath = str_replace(DIRECTORY_SEPARATOR, '/', $originalFilePath);
        if (!str_starts_with($normalizedPath, $normalizedRoot)) {
            return self::fail('Resolved image path is outside site root.', $isDebug, $debugLog, $options);
        }


        /* ---------------- Get the original image dimesions from the #joomlaImage fragment ---------------- */
        [$originalWidth, $originalHeight] = self::getImageDimensionsFromFragment($originalFragment, $isDebug, $debugLog);


        /* ---------------- SVG quick Exit ---------------- */
        if ($extension === 'svg') {
            if ($isDebug) $debugLog[] = "SVG file detected. Skipping raster processing.";
            if(!$originalWidth || !$originalHeight) {
                [$originalWidth, $originalHeight] = self::getSvgDimensions($originalFilePath, $isDebug, $debugLog) ?: [0, 0];
            }

            return [
                'ok'    => true,
                'error' => null,
                'data'  => [
                    'isSvg'     => true,
                    'src'       => $originalPath,
                    'alt'       => $altText,
                    'width'     => $originalWidth ?: null,
                    'height'    => $originalHeight ?: null,
                    'loading'   => $options['lazy'] ? 'loading="lazy"' : '',
                    'mime_type' => $mimeType,
                    'image-class'=> $options['image-class'],
                ],
                'debug_data' => $isDebug ? ['log' => $debugLog, 'options' => $options] : null,            
            ];
        }

        /* ---------------- Get image size if it still doesn't exist  ---------------- */
        if(!$originalWidth || !$originalHeight) {
            if ($isDebug) $debugLog[] = "No dimesions found, trying with getimagesize().";
            [$originalWidth, $originalHeight] = getimagesize($originalFilePath) ?: [0, 0];
        }
        
        if (!$originalWidth || !$originalHeight) {
            return self::fail('Failed to read dimensions of original image. File might be corrupt.', $isDebug, $debugLog, $options);
        }


        /* ---------------- Get original image aspect Ratio ---------------- */
        $aspectRatio = $originalHeight / $originalWidth;
        $cropBox     = [];


        /* ---------------- If aspect ratio is fixed in options, Calculate image crop box, new width and new height ---------------- */
        if (is_numeric($options['aspectRatio']) && $options['aspectRatio'] > 0) {

            [
                $cropBox,
                $originalWidth,
                $originalHeight,
                $aspectRatio
            ] = self::calculateAspectRatioCropBox($originalWidth, $originalHeight, $options['aspectRatio'], $isDebug, $debugLog);
        
        }


        /* ---------------- Build Output directory ---------------- */
        $thumbnailsBasePath = self::buildThumbDirectory($originalFilePath, $isDebug, $debugLog);
        


        /* ---------------- Build srcset and resizeJobs ---------------- */
        [
            $srcsetEntries, 
            $webpSrcsetEntries, 
            $resizeJobs
        ] = self::buildSrcsetAndResizeJobs(
            $options, 
            $originalFilePath, 
            $aspectRatio,
            $originalWidth, 
            $thumbnailsBasePath,
            $pathInfo,
            $isDebug, 
            $debugLog            
        );

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

        self::generateThumbnails(
            $resizeJobs, 
            $originalFilePath, 
            $thumbnailsBasePath, 
            $options,
            $cropBox,
            $extension,
            $isDebug, 
            $debugLog);
        

        /* ---------------- Build final response ---------------- */
        return [
            'ok'    => true,
            'error' => null,
            'data'  => [
                'isSvg'      => false,
                'srcset'     => !$options['webp'] ? implode(', ', $srcsetEntries) : null,
                'webpSrcset' => $options['webp'] ? implode(', ', $webpSrcsetEntries) : null,
                'fallback'   => $originalPath,
                'sizes'      => htmlspecialchars($options['sizes'], ENT_QUOTES),
                'alt'        => $altText,
                'width'      => $originalWidth,
                'height'     => $originalHeight,
                'loading'    => $options['lazy'] ? 'loading="lazy"' : '',
                'mime_type'  => $mimeType,
                'image-class'=> $options['image-class'],
            ],
            'debug_data' => $isDebug ? ['log' => $debugLog, 'options' => $options] : null,
        ];        
    }


    /* ==========================================================
     * Merge default and call options to get final options 
     * ========================================================== */
    private static function mergeCallDefaultOptions(array $callOptions): array
    {
        $plugin = PluginHelper::getPlugin('system', 'responsiveimages');

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
        $options = array_merge($defaultOptions, $callOptions);
        
        // normalize quality field 1-100
        $options['quality'] = max(1, min(100, (int) $options['quality']));

        $isDebug = $options['debug'];

        return [$options, $isDebug];

    }

    /* ==========================================================
     * Extract data from the image field to return image paths and alt
     * ========================================================== */
    private static function extractImageFieldData(mixed $imageField, array $options, bool $isDebug, array &$debugLog): array
    {
        // normalize imageField (can be a json object or an array)
        if (is_string($imageField)) {
            $imageField = json_decode($imageField, true);
        } elseif (is_object($imageField)) {
            $imageField = (array) $imageField;
        }


        // extract original image path and #joomlaimage fragment
        $originalImagePath = $imageField['imagefile'] ?? '';

        [$originalPath, $originalFragment] = explode('#', $originalImagePath, 2);

        $originalPath = rawurldecode($originalPath);
        $originalFilePath = realpath($originalPath);
        $pathInfo  = pathinfo($originalFilePath);

        if ($isDebug) $debugLog[] = "Resolving original image path: " . $originalPath;
        if ($isDebug) $debugLog[] = "Resolving original file path: " . $originalFilePath;

        if (strpos($originalPath, '..') !== false || strpos($originalPath, "\0") !== false) {
            if($isDebug) $debugLog[] = 'Invalid path: contains traversal sequences.';
            return ['',''];
        }

        // Fix MIME Type mapping
        $extension = strtolower($pathInfo['extension'] ?? '');
        $mimeType = 'image/' . $extension;
        if ($extension === 'jpg' || $extension === 'jpeg') {
            $mimeType = 'image/jpeg';
        } elseif ($extension === 'svg') {
            $mimeType = 'image/svg+xml';
        }

        // handle alt text if image
        $altText = '';
        if (!empty($imageField['alt_text'])) { $altText = trim((string) $imageField['alt_text']); } 
        elseif (!empty($options['alt'])) { $altText = trim((string) $options['alt']); } 
        else { $altText = $pathInfo['filename'] ?? ''; }

        $altText = htmlspecialchars($altText, ENT_QUOTES);

        if($isDebug) $debugLog[] = 'Final alt text : ' . $altText;

        return [
            $originalPath,
            $originalFilePath,
            $originalFragment,
            $pathInfo,
            $extension,
            $mimeType,
            $altText,
        ];
    }

    /* ==========================================================
     * Get width and height of image from original image fragemtn (#joomlaimage...)
     * ========================================================== */
    private static function getImageDimensionsFromFragment(string $originalFragment, bool $isDebug = false, array &$debugLog = []): array 
    {
        $originalWidth = null;
        $originalHeight = null;

        // Check for #joomlaImage fragment
        if (str_starts_with($originalFragment, 'joomlaImage://')) {
            if ($isDebug) $debugLog[] = "Found joomlaImage fragment: {$originalFragment}";

            // Extract the query part after ?
            $queryPos = strpos($originalFragment, '?');
            if ($queryPos !== false) {
                $query = substr($originalFragment, $queryPos + 1);
                parse_str($query, $params);

                if (isset($params['width'])) {
                    $originalWidth = (int) $params['width'];
                    if ($isDebug) $debugLog[] = "Original width from fragment: {$originalWidth}";
                }

                if (isset($params['height'])) {
                    $sourcHheight = (int) $params['height'];
                    if ($isDebug) $debugLog[] = "Original height from fragment: {$sourcHheight}";
                }
            } else {
                if ($isDebug) $debugLog[] = "No query parameters found in fragment.";
            }
        } else {
            if ($isDebug) $debugLog[] = "No joomlaImage fragment found in path.";
        }

        return [$originalWidth, $sourcHheight];
    }

     /* ==========================================================
     * Image helpers
     * ========================================================== */

     private static function calculateAspectRatioCropBox(
        int $originalWidth,
        int $originalHeight,
        float $aspectRatio,
        bool $isDebug,
        array &$debugLog
    ): array {

        if ($isDebug) $debugLog[] = "Calculating crop for Aspect Ratio: " . $aspectRatio;

        $originalRatio = $originalHeight / $originalWidth;

        if ($originalRatio > $aspectRatio) {
            $targetHeight = (int) round($originalWidth * $aspectRatio);

            return [
                [
                    $originalWidth,
                    $targetHeight,
                    0,
                    (int) (($originalHeight - $targetHeight) / 2),
                ],
                $originalWidth, 
                $originalHeight, 
                $aspectRatio
            ];
        }

        $targetWidth = (int) round($originalHeight / $aspectRatio);

        return [
            [
                $targetWidth,
                $originalHeight,
                (int) (($originalWidth - $targetWidth) / 2),
                0,
            ],
            $originalWidth, 
            $originalHeight, 
            $aspectRatio
            
        ];
    }

    /* ==========================================================
     * Image helpers
     * ========================================================== */

    private static function buildThumbDirectory(string $originalFilePath, bool $isDebug, array &$debugLog) :string
    {
        $imagesRootPath = realpath(JPATH_ROOT . '/images');
        $relativeDirectory = trim(str_replace($imagesRootPath, '', dirname($originalFilePath)), DIRECTORY_SEPARATOR);

        $thumbnailsBasePath = JPATH_ROOT . '/media/ri-responsiveimages';
        if ($relativeDirectory !== '') {
            $thumbnailsBasePath .= '/' . $relativeDirectory;
        }

        if (!is_dir($thumbnailsBasePath)) {
            if ($isDebug) $debugLog[] = "Attempting to create directory: " . $thumbnailsBasePath;
            if (!mkdir($thumbnailsBasePath, 0755, true)) {
                return self::fail('Insufficient permissions to create folder: ' . $thumbnailsBasePath, $isDebug, $debugLog, $options);
            }
        } else {
            if ($isDebug) $debugLog[] = "Folder exists: " . $thumbnailsBasePath;
        }

        return $thumbnailsBasePath;
    }

    /* ==========================================================
     * Build srcsets and resize jobs
     * ========================================================== */

    private static function buildSrcsetAndResizeJobs(
        array $options, 
        string $originalFilePath, 
        float $aspectRatio,
        int $originalWidth, 
        string $thumbnailsBasePath,
        array $pathInfo,
        bool $isDebug, 
        array &$debugLog 
    ) :array {

        $srcsetEntries = [];
        $webpSrcsetEntries = [];
        $resizeJobs = [];

        $hash = substr(md5($originalFilePath . filemtime($originalFilePath)), 0, 8);

        // Get the widths that will be outputed
        $targetWidths = array_unique(array_map('intval', $options['widths']));
        sort($targetWidths);

        foreach ($targetWidths as $targetWidth) {

            // prevent outputting thumbs bigger than original
            if ($targetWidth > $originalWidth) $targetWidth = $originalWidth;

            //get target height
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

        return [
            $srcsetEntries, 
            $webpSrcsetEntries, 
            $resizeJobs
        ];

    }

    /* ==========================================================
     * Genrate thumbnails
     * ========================================================== */

     private static function generateThumbnails(
        array $resizeJobs, 
        string $originalFilePath, 
        string $thumbnailsBasePath, 
        array $options,
        array $cropBox,
        string $extension,
        bool $isDebug, 
        array &$debugLog
    ) :void {

        $lockFile = $thumbnailsBasePath . '/.lock';
        $lockHandle = fopen($lockFile, 'c');
        if ($isDebug) $debugLog[] = "Created lock file : " . $lockFile;

        if (!$lockHandle) {
            if ($isDebug) $debugLog[] = "Failed to create lock file for thumbnail generation.";
            return;
        }

        @chmod($lockFile, 0600);

        if (flock($lockHandle, LOCK_EX)) {
            try {
                $image = new Imagick($originalFilePath);
                
                if (!empty($cropBox)) {
                    $image->cropImage(...$cropBox);
                    $image->setImagePage(0, 0, 0, 0);
                }

                foreach ($resizeJobs as [$thumbPath, $webpPath, $targetWidth, $targetHeight]) {

                    if($options['webp'] && $webpPath) {
                        $thumbToGeneratePath = $webpPath;
                        $thumbExtension = 'webp';
                    } else {
                        $thumbToGeneratePath = $thumbPath;
                        $thumbExtension = $extension;
                    }

                    if (!is_file($thumbToGeneratePath)) {
                        $tmpPath = tempnam(dirname($thumbToGeneratePath), 'ri_');
                        $resizedImage = clone $image;

                        $resizedImage->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1, true);
                        $resizedImage->setImageFormat($thumbExtension);
                        $resizedImage->setImageCompressionQuality($options['quality']);
                        $resizedImage->writeImage($tmpPath);

                        rename($tmpPath, $thumbToGeneratePath);
                        chmod($thumbToGeneratePath, 0644);

                        if ($isDebug) $debugLog[] = "Created thumbnail: {$targetWidth}w {$thumbExtension}";

                        $resizedImage->clear();
                    }


                }
                
                $image->clear();

            } catch (Throwable $e) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
                
                if ($isDebug) $debugLog[] = 'Processing Error: ' . $e->getMessage();
                return;
            }
            flock($lockHandle, LOCK_UN);
        }

        fclose($lockHandle);
        if ($isDebug) $debugLog[] = "Closed lock file : " . $lockFile;

        if ($isDebug) $debugLog[] = "Thumbnail generation process completed successfully.";
    }

    


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
     * SVG helpers
     * ========================================================== */

    private static function getSvgDimensions(string $originalFilePath, bool $isDebug, &$debugLog): array
    {
        if($debugLog) $debugLog[] = 'Geting svg image dimesions from viewBox attribut with preg_match';

        $width  = null;
        $height = null;

        $svgContent = @file_get_contents($originalFilePath);
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

     private static function fail(string $message, bool $debugMode = false, array $debugLog = [], array $finalOptions = []): array
     {
         return [
             'ok'         => false,
             'error'      => $message,
             'data'       => null,
             'debug_data' => $debugMode ? ['log' => $debugLog, 'options' => $finalOptions] : null,
         ];
     }
}
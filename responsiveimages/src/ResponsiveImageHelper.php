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
use Joomla\CMS\Uri\Uri;
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
            $errorExtractImageFieldData,
        ] = self::extractImageFieldData($imageField, $options, $isDebug, $debugLog);

        $hash = substr(md5($originalFilePath . filemtime($originalFilePath)), 0, 8);

        if($errorExtractImageFieldData) {
            return self::fail($errorExtractImageFieldData, $isDebug, $debugLog, $options);
        }

        if (!$originalPath || empty($originalPath)) {
            return self::fail('No image path found in field.', $isDebug, $debugLog, $options);
        }
        if ($originalFilePath === false || empty($originalFilePath)) {
            return self::fail('Original image file not accessible on disk: ' . $originalPath, $isDebug, $debugLog, $options);
        }


        /* ---------------- Get the original image dimensions from the #joomlaImage fragment ---------------- */
        if($originalFragment) {
            [$originalWidth, $originalHeight] = self::getImageDimensionsFromFragment($originalFragment, $isDebug, $debugLog);
        }


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
                    'imageClass'=> $options['imageClass'] ?? '',
                ],
                'debug_data' => $isDebug ? ['log' => $debugLog, 'options' => $options] : null,            
            ];
        }

        /* ---------------- Get image size if it still doesn't exist  ---------------- */
        if(!$originalWidth || !$originalHeight) {
            if ($isDebug) $debugLog[] = "No dimensions found, trying with getimagesize().";
            [$originalWidth, $originalHeight] = getimagesize($originalFilePath) ?: [0, 0];
        }
        
        if (!$originalWidth || !$originalHeight) {
            return self::fail('Failed to read dimensions of original image. File might be corrupt.', $isDebug, $debugLog, $options);
        }



        /* ---------------- If aspect ratio is fixed in options, Calculate image crop box, new width and new height ---------------- */
        $cropBox     = [];

        if (is_numeric($options['aspectRatio']) && $options['aspectRatio'] > 0) {

            [
                $cropBox,
                $originalWidth,
                $originalHeight,
                $aspectRatio
            ] = self::calculateAspectRatioCropBox($originalWidth, $originalHeight, $options['aspectRatio'], $isDebug, $debugLog);
        
        } else {
            $aspectRatio = $originalHeight / $originalWidth;
        }


        /* ---------------- Build Output directory ---------------- */
        $thumbnailsBasePath = self::buildThumbDirectory($originalFilePath, $isDebug, $debugLog);
        if(empty($thumbnailsBasePath)) {
            return self::fail('Cannot create thumbnail directory', $isDebug, $debugLog, $options);
        }
        
        
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
            $extension,
            $hash,
            $isDebug, 
            $debugLog            
        );

        if (empty($resizeJobs)) {
            return self::fail('No valid thumbnail sizes generated', $isDebug, $debugLog, $options);
        }


        /* ---------------- Check manifest ---------------- */
        $manifestFile = $thumbnailsBasePath . '/' . $pathInfo['filename'] . '-' . $hash . '.manifest.json';

        [
            $toDoBuildManifest,
            $toDoUpdateManifest,
        ] = self::checkManifest(
            $resizeJobs,
            $manifestFile,
            $options,
            $originalFilePath,
            $aspectRatio,
            $extension,
            $isDebug,
            $debugLog
        );

        if(!$toDoBuildManifest && !$toDoUpdateManifest) {
            // all checks have passed, no thumbnails need to be generated, display them now
            if($isDebug) $debugLog[] = 'Manifest is up to date, outputing result from manifest data.';

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
                    'imageClass'=> $options['imageClass'] ?? '',
                ],
                'debug_data' => $isDebug ? ['log' => $debugLog, 'options' => $options] : null,
            ];
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

       
        /* ---------------- Update Manifest ---------------- */
        self::updateManifest(
            $toDoBuildManifest,
            $toDoUpdateManifest,
            $resizeJobs,
            $manifestFile,
            $srcsetEntries,
            $webpSrcsetEntries,
            $options,
            $originalPath,
            $originalFilePath,
            $extension,
            $originalWidth,
            $originalHeight,
            $aspectRatio,
            $mimeType,
            $isDebug,
            $debugLog
        );        

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
                'imageClass'=> $options['imageClass'] ?? '',
            ],
            'debug_data' => $isDebug ? ['log' => $debugLog, 'options' => $options] : null,
        ];        
    }



    
    /* ==========================================================
     * Create or update the manifest
     * ========================================================== */

     private static function checkManifest(
        array $resizeJobs,
        string $manifestFile,
        array $options,
        string $originalFilePath,
        float $aspectRatio,
        string $extension,
        bool $isDebug,
        array &$debugLog
    ): array {
        /*
        * This returns 2 bool variables : 
        * $toDoBuildManifest : if true manifest should be built (or rebuilt ex: original image changed)
        * $toDoUpdateManifest : if true, manifest needs updating to add thumbnails
        */
    
        if (!is_file($manifestFile)) {
            return [true, false];
        }
    
        $manifest = json_decode((string) file_get_contents($manifestFile), true);

        if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1) {
            return [true, false];
            if($isDebug) $debugLog[] = 'Manifest doesn\'t exists';
        }

        $source = $manifest['source'] ?? [];

        if (
            ($source['mtime'] ?? null) !== filemtime($originalFilePath) ||
            ($source['size'] ?? null)  !== filesize($originalFilePath)
        ) {
            if($isDebug) $debugLog[] = 'Manifest exists but file has changed';
            return [true,false];
        }

        $quality = (string) $options['quality'];

        foreach ($resizeJobs as $index => [, , $width, $height]) {
            $key = "{$width}x{$height}";

            // ---- JPG / PNG / original format ----
            if (
                empty($manifest[$extension][$quality][$key]) ||
                !is_file(JPATH_ROOT . strtok($manifest[$extension][$quality][$key], ' '))
            ) {
                return [false, true];
            }

            // ---- WEBP ----
            if (
                !empty($options['webp']) &&
                (
                    empty($manifest['webp'][$quality][$key]) ||
                    !is_file(JPATH_ROOT . strtok($manifest['webp'][$quality][$key], ' '))
                )
            ) {
                return [false, true];
            }
        }

        // all check are passed and all thumbnails exist in the manifest
        return [false, false];
    }
    
    
    /* ==========================================================
     * Create or update the manifest
     * ========================================================== */

     private static function updateManifest(
        bool $toDoBuildManifest,
        bool $toDoUpdateManifest,
        array $resizeJobs,
        string $manifestFile,
        array $srcsetEntries,
        array $webpSrcsetEntries,
        array $options,
        string $originalPath,
        string $originalFilePath,
        string $extension,
        int $originalWidth,
        int $originalHeight,
        float $aspectRatio,
        string $mimeType,
        bool $isDebug,
        array &$debugLog
    ): void {
    

        if($toDoBuildManifest) {

            if($isDebug) $debugLog[] = "Manifest doesn't exist yet";

            $manifest = [
                'version' => 1,
                'source' => [
                    'path'   => $originalPath,
                    'mtime'  => filemtime($originalFilePath),
                    'size'   => filesize($originalFilePath),
                    'width'  => $originalWidth,
                    'height' => $originalHeight,
                    'mime'   => $mimeType,
                ],
            ];
        } elseif ($toDoUpdateManifest) {

            $manifest = json_decode((string) file_get_contents($manifestFile), true) ?: [];
            if($isDebug) $debugLog[] = 'Manifest exists, needs update';

        } else {
            if($isDebug) $debugLog[] = 'Manifest exists and doesn\'t need updating (this should never display)';
        }

        $quality = (string) $options['quality'];
    
        foreach ($resizeJobs as $index => [, , $width, $height]) {

            $key    = "{$width}x{$height}";
    
            if (!empty($srcsetEntries[$index])) {
                $manifest[$extension][$quality][$key] = $srcsetEntries[$index];
            }
    
            if (!empty($webpSrcsetEntries[$index])) {
                $manifest['webp'][$quality][$key] = $webpSrcsetEntries[$index];
            }
        }

        if($isDebug) $debugLog[] = 'Writting manifest';
    
        file_put_contents(
            $manifestFile,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        @chmod($manifestFile, 0644);
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
            'imageClass' => '',
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
        $explodedOriginalImagePath = explode('#', $originalImagePath, 2);
        if(count($explodedOriginalImagePath) > 1) {
            [$originalPath, $originalFragment] = $explodedOriginalImagePath;
        } else {
            [$originalPath, $originalFragment] = [$originalImagePath, null];
        }

        $originalPath = rawurldecode($originalPath);
        $originalFilePath = realpath($originalPath);

        // Check if original image is inside site root
        $originalImagesRoot = realpath(JPATH_ROOT . '/images');
        if (!$originalFilePath || !str_starts_with($originalFilePath, $originalImagesRoot)) {
            return ['','','','','','','',"Original image is not inside site root : " . $originalImagePath];
        }

        if ($isDebug) $debugLog[] = "Resolving original image path: " . $originalPath;
        if ($isDebug) $debugLog[] = "Resolving original file path: " . $originalFilePath;

        // Fix MIME Type mapping
        $pathInfo  = pathinfo($originalFilePath);
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
            false,
        ];
    }

    /* ==========================================================
     * Get width and height of image from original image fragment (#joomlaimage...)
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
                    $originalHeight = (int) $params['height'];
                    if ($isDebug) $debugLog[] = "Original height from fragment: {$originalHeight}";
                }
            } else {
                if ($isDebug) $debugLog[] = "No query parameters found in fragment.";
            }
        } else {
            if ($isDebug) $debugLog[] = "No joomlaImage fragment found in path.";
        }

        return [$originalWidth, $originalHeight];
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
                $targetHeight, 
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
            $targetWidth, 
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
                if ($isDebug) $debugLog[] = "Cannot create folder: " . $thumbnailsBasePath;
                return '';
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
        string $extension,
        string $hash,
        bool $isDebug, 
        array &$debugLog 
    ) :array {

        $srcsetEntries = [];
        $webpSrcsetEntries = [];
        $resizeJobs = [];

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
     * Generate thumbnails
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

        $lockFile = $thumbnailsBasePath . '/' . md5($originalFilePath) . '.lock';
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
        @unlink($lockFile);
        if ($isDebug) $debugLog[] = "Closed and removed lock file : " . $lockFile;

        if ($isDebug) $debugLog[] = "Thumbnail generation process completed successfully.";
    }


    /* ==========================================================
     * SVG helpers
     * ========================================================== */

    private static function getSvgDimensions(string $originalFilePath, bool $isDebug, &$debugLog): array
    {
        if($isDebug) $debugLog[] = 'Geting svg image dimensions from viewBox attribut with preg_match';

        $width  = null;
        $height = null;

        $svgContent = @file_get_contents($originalFilePath, false, null, 0, 8192);
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
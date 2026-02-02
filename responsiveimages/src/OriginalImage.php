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

use Throwable;

final class OriginalImage
{
    public readonly string $filePath;
    public readonly string $path;
    public readonly int $width;
    public readonly int $height;
    public readonly float $ratio;
    public readonly string $mimeType;
    public readonly array $pathInfo;
    public readonly string $hash;
    public readonly int $mTime;
    public readonly string $alt;

    /**
     * Constructor — sets all properties once, making the object immutable
     */
    public function __construct(
        string $filePath,
        string $path,
        int $width,
        int $height,
        string $mimeType,
        array $pathInfo,
        string $hash,
        int $mTime,
        string $alt = ''
    ) {
        $this->filePath  = $filePath;
        $this->path      = $path;
        $this->width     = $width;
        $this->height    = $height;
        $this->ratio     = ($height > 0) ? ($width / $height) : 1.0;
        $this->mimeType  = $mimeType;
        $this->pathInfo  = $pathInfo;
        $this->hash      = $hash;
        $this->mTime     = $mTime;
        $this->alt       = $alt;
    }

    public static function getOriginalImageData(mixed $field, string $optionsAlt, DebugTimeline $debug): ?self
    {


        // Normalize field ------------------------------------------------------------------------

        if (!$field) {
            $debug->log('OriginalImage', 'field is empty');
            return null;
        }

        // Check if field is a string (ex : straight out of media custom field rawvalue)
        if (is_string($field)) {
            $field = json_decode($field);
        }

        // if field is an stdClass object make it an array
        if (is_object($field)) {
            $field = (array)$field;
        }
        
        // Field normalization failed and couldn't change it to an array
        if (!is_array($field)) {
            $debug->log('OriginalImage', 'unsupported field type : ' . gettype($field));
            return null;
        }

        // Get paths data ------------------------------------------------------------------------

        $src = $field['imagefile'] ?? '';

        if (!$src) {
            $debug->log('OriginalImage', 'empty source path');
            return null;
        }

        // Seperate src and fragment
        $srcParts = explode('#', $src);
        $src        = $srcParts[0];
        $fragment   = $srcParts[1] ?? '';

        // get RAW filesystem paths
        $relativePath = urldecode(ltrim($src, '/'));
        $filePath = JPATH_ROOT . '/' . $relativePath;
        $fileRealPath = realpath($filePath);
        $rootRealPath = realpath(JPATH_ROOT);

        // field sanity checks ------------------------------------------------------------------------

        // Check for external URL
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            $debug->log('OriginalImage', 'external URLs not allowed : ' . $src);
            return null;
        }
        // Safety check: must exist
        if (!is_file($filePath)) {
            $debug->log('OriginalImage', 'file not found : ' . basename($filePath));
            return null;
        }
        // Ensure it exists and is inside the site root
        if ($fileRealPath === false || str_starts_with($fileRealPath, $rootRealPath) === false) {
            $debug->log('OriginalImage', 'file not inside site root or does not exist :' . basename($filePath));
            return null;
        }

        // Validate mimeType
        $mimeType = mime_content_type($filePath);
        $allowedMimeTypes = ['image/jpeg','image/png','image/webp','image/gif','image/svg+xml'];
        if(!in_array($mimeType, $allowedMimeTypes)) {
            $debug->log('OriginalImage', 'MimeType not supported :' . $mimeType);
            return null;
        }

        // Get image data ------------------------------------------------------------------------

        // Get path info
        $pathInfo = pathinfo($filePath);
        $pathInfo['extension'] = strtolower($pathInfo['extension'] ?? '');

        // get alt
        $alt = $field['alt_text'] ?? '';
        if(empty($alt)) { $alt = $optionsAlt; } 
        if(empty($alt)) { $alt = $pathInfo['filename']; }
        
        // --- Determine dimensions ---
        [$width, $height] = self::getImageDimensionsFromFragment($fragment, $debug);
        // If dimensions are still null, fallback for raster or SVG
        if ($width === 0 || $height === 0) {
            if ($pathInfo['extension'] === 'svg') {
                [$width, $height] = self::getSvgDimensions($filePath, $debug) ?? [0, 0];
                $debug->log('OriginalImage', 'SVG image dimensions determined from reading viewBox in file : ' . $width . 'x' . $height);
            } else {
                [$width, $height] = getimagesize($filePath) ?: [0, 0];
                $debug->log('OriginalImage', 'Raster image dimensions determined with getImageSize : ' . $width . 'x' . $height);
            }
        }


        // Get hash and mtime
        $hash = substr(md5($filePath . filemtime($filePath)), 0, 8);
        $mtime = filemtime($filePath);
        // Build original image object ------------------------------------------------------------------------

        $image = new self(
            $filePath,
            $src, // web path
            $width,
            $height,
            $mimeType,
            $pathInfo,
            $hash,
            $mtime,
            $alt
        );

        return $image;
    }

    /**
     * Extract width and height from Joomla #joomlaImage fragment.
     */
    private static function getImageDimensionsFromFragment(string $fragment, DebugTimeline $debug): array
    {
        $width = 0;
        $height = 0;

        if (str_contains($fragment, 'joomlaImage://')) {
            
            // Separate path and query
            $queryPos = strpos($fragment, '?');
            if ($queryPos !== false) {
                $query = substr($fragment, $queryPos + 1);
                parse_str($query, $params);

                if (isset($params['width'])) { $width = (int) $params['width']; }
                if (isset($params['height'])) { $height = (int) $params['height']; }
                
            }
        }

        $debug->log('OriginalImage', 'getting size from fragment : ' . $width . 'x' . $height);


        return [$width, $height];
    }

    /**
     * Get the dimensions of an SVG from its width/height attributes or viewBox.
     */
    public static function getSvgDimensions(string $filePath, DebugTimeline $debug): array
    {
        try {
            $content = file_get_contents($filePath);

            if (!$content) {
                $debug->log('OriginalImage', 'Cannot get content of SVG file : ' . basename($filePath));
                return [0, 0];
            }

            // Try width and height attributes first
            if (preg_match('/<svg[^>]+width="([\d.]+)[^"]*"[^>]*height="([\d.]+)[^"]*"/i', $content, $matches)) {
                return [(int)$matches[1], (int)$matches[2]];
            }

            // Fallback to viewBox attribute
            if (preg_match('/<svg[^>]+viewBox="[\d.\-]+[\s,]+[\d.\-]+[\s,]+([\d.]+)[\s,]+([\d.]+)"/i', $content, $matches)) {
                return [(int)$matches[1], (int)$matches[2]];
            }

            $debug->log('OriginalImage', 'SVG has no width/height or viewBox attributes : ' . basename($filePath));


        } catch (\Throwable $e) {
            $debug->log('OriginalImage', 'SVG read error :' . $e->getMessage());
        }

        return [0, 0];
    }

}

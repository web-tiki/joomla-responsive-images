<?php
/**
 * @package    Joomla.Plugin
 * @subpackage System.ResponsiveImages
 *
 * Helper for responsive image rendering and thumbnail generation (Imagick-based)
 */


namespace WebTiki\Plugin\System\ResponsiveImages;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Imagick;

class ResponsiveImageHelper
{
    /**
     * Normalize and sanitize a full path for URL usage: all folders + filename
     * Preserves hyphens for SEO-friendly URLs
     *
     * @param string $fullPath
     * @return string
     */
    private static function safePath(string $fullPath): string
    {
        $fullPath = str_replace('\\', '/', $fullPath); // Convert backslashes
        $parts = explode('/', $fullPath);
        foreach ($parts as &$part) {
            // Transliterate UTF-8 accents to ASCII
            $part = iconv('UTF-8', 'ASCII//TRANSLIT', $part);
            // Keep letters, numbers, underscores, dots, and hyphens
            $part = preg_replace('![^a-z0-9._-]+!i', '_', $part);
            // Replace multiple underscores with a single underscore
            $part = preg_replace('!_+!', '_', $part);
            $part = trim($part, '_');
        }
        $safePath = implode('/', $parts);
        $safePath = preg_replace('!/{2,}!', '/', $safePath); // Remove duplicate slashes
        return $safePath;
    }

    private static function calculateCropDimensions(int $originalW, int $originalH, float|int $targetRatio): ?array
    {
        if (!is_numeric($targetRatio) || $targetRatio <= 0) return null;

        $targetRatio = (float)$targetRatio;
        $originalRatio = $originalH / $originalW;

        if ($originalRatio > $targetRatio) {
            $cropW = $originalW;
            $cropH = (int)round($originalW * $targetRatio);
            $cropX = 0;
            $cropY = (int)round(($originalH - $cropH) / 2);
        } else {
            $cropH = $originalH;
            $cropW = (int)round($originalH / $targetRatio);
            $cropX = (int)round(($originalW - $cropW) / 2);
            $cropY = 0;
        }

        return ['width'=>$cropW,'height'=>$cropH,'x'=>$cropX,'y'=>$cropY,'ratio'=>$targetRatio];
    }

    public static function getProcessedData($imageField, array $options = []): array
    {
        if (!$imageField) return '';

        // 1. Setup Plugin Params & Options
        $plugin       = PluginHelper::getPlugin('system', 'responsiveimages');
        $pluginParams = isset($plugin->params) ? json_decode($plugin->params, true) : [];

        // Check if the plugin is actually enabled in Joomla and render the normal (with the renderSimple function) image tag if it isn't enabled
        if (!$plugin) {
            return self::renderSimple($imageField, $options);
        }

        $defaultOptions = [
            'lazy'        => $pluginParams['lazy'] ?? true,
            'webp'        => $pluginParams['webp'] ?? true,
            'alt'         => '', // This acts as the "default" if backend is empty
            'sizes'       => $pluginParams['sizes'] ?? '100vw',
            'widths'      => isset($pluginParams['widths']) ? explode(',', $pluginParams['widths']) : [640, 1280, 1920],
            'heights'     => null,
            'outputDir'   => $pluginParams['thumb_dir'] ?? 'thumbnails/responsive',
            'quality'     => $pluginParams['quality'] ?? 70,
            'aspectRatio' => null,
        ];

        $opt = array_merge($defaultOptions, $options);

        // 2. Parse Image Field Data
        if (is_string($imageField)) $imageField = json_decode($imageField, true);

        if (is_array($imageField)) {
            $imageFieldPath = $imageField['imagefile'] ?? '';
            $imageFieldAlt  = $imageField['alt_text'] ?? '';
        } elseif ($imageField instanceof \stdClass) {
            $imageFieldPath = $imageField->imagefile ?? '';
            $imageFieldAlt  = $imageField->alt_text ?? '';
        } else {
            return '';
        }

        if (!$imageFieldPath) return '';

        // 3. Resolve File and Path Info
        $imageParts = explode('#', $imageFieldPath);
        $oImagePath = str_replace('%20', ' ', $imageParts[0]);
        
        if (!is_file($oImagePath)) return "";

        $oImagePathInfo  = pathinfo($oImagePath);
        $oImageExtension = strtolower($oImagePathInfo['extension']);
        $oImageName      = $oImagePathInfo['filename'];
        $oImageDir       = $oImagePathInfo['dirname'];

        // 4. DETERMINE FINAL ALT TEXT (The fix)
        // Priority: Joomla Backend > Template Option > Filename
        $finalAlt = trim($imageFieldAlt) ?: ($opt['alt'] ?: $oImageName);
        $escAlt   = htmlspecialchars($finalAlt, ENT_QUOTES);

        // 5. Get Original Dimensions
        $oImageParams = ['width' => 0, 'height' => 0];
        if (isset($imageParts[1])) {
            $params = parse_url($imageParts[1]);
            if (isset($params['query'])) parse_str($params['query'], $oImageParams);
        }
        
        if (empty($oImageParams['width']) || empty($oImageParams['height'])) {
            [$oImageParams['width'], $oImageParams['height']] = getimagesize($oImagePath);
        }
        
        if (!$oImageParams['width'] || !$oImageParams['height']) return '';

        $oImageRatio = $oImageParams['height'] / $oImageParams['width'];

        // 6. Handle Cropping/Aspect Ratio
        $cropDimensions = null;
        $filenameRatioAppendix = '';
        if ($opt['aspectRatio'] !== null && is_numeric($opt['aspectRatio']) && $opt['aspectRatio'] > 0) {
            $cropDimensions = self::calculateCropDimensions($oImageParams['width'], $oImageParams['height'], $opt['aspectRatio']);
            if ($cropDimensions) {
                $oImageParams['width']  = $cropDimensions['width'];
                $oImageParams['height'] = $cropDimensions['height'];
                $oImageRatio            = $cropDimensions['ratio'];
                $filenameRatioAppendix  = '-ar' . str_replace('.', '', (string)$opt['aspectRatio']);
            }
        }

        // 7. Early Exit for SVG
        if ($oImageExtension == 'svg') {
            $src = self::safePath(str_replace(JPATH_ROOT . '/', '', $oImagePath));
            $loading = $opt['lazy'] ? ' loading="lazy"' : '';
            return sprintf('<img src="%s" alt="%s" width="%d" height="%d"%s>', $src, $escAlt, $oImageParams['width'], $oImageParams['height'], $loading);
        }

        // 8. Prepare Thumbnail Processing
        $thumbDir = self::safePath(trim($opt['outputDir'], '/') . '/' . $oImageDir);
        if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);

        $thumbHash = substr(md5($oImageName . filemtime($oImagePath) . $filenameRatioAppendix), 0, 8);
        
        $dimensionList = !empty($opt['heights']) && is_array($opt['heights']) ? $opt['heights'] : $opt['widths'];
        $dimensionBy   = !empty($opt['heights']) && is_array($opt['heights']) ? 'height' : 'width';

        $srcsetParts = [];
        $srcsetPartsWebp = [];
        $thumbPaths = [];
        $needsProcessing = false;

        foreach ($dimensionList as $dimension) {
            if ($dimensionBy == 'width') {
                $thumbW = min($dimension, $oImageParams['width']);
                $thumbH = (int)round($thumbW * $oImageRatio);
            } else {
                $thumbH = min($dimension, $oImageParams['height']);
                $thumbW = (int)round($thumbH / $oImageRatio);
            }
            
            if ($thumbW <= 0 || $thumbH <= 0) continue;

            $thumbBase = sprintf('%s/%s-%s%s-q%d-%dx%d', $thumbDir, self::safePath($oImageName), $thumbHash, $filenameRatioAppendix, $opt['quality'], $thumbW, $thumbH);
            $thumbFile = $thumbBase . '.' . $oImageExtension;
            $webpFile  = $thumbBase . '.webp';

            $thumbPaths[] = ['thumbFile' => $thumbFile, 'webpFile' => $webpFile, 'width' => $thumbW, 'height' => $thumbH];

            $srcsetParts[] = '/' . self::safePath(str_replace(JPATH_ROOT . '/', '', $thumbFile)) . " {$thumbW}w";
            if ($opt['webp']) {
                $srcsetPartsWebp[] = '/' . self::safePath(str_replace(JPATH_ROOT . '/', '', $webpFile)) . " {$thumbW}w";
            }

            if (!is_file($thumbFile) || ($opt['webp'] && !is_file($webpFile))) {
                $needsProcessing = true;
            }
        }

        // 9. Imagick Processing
        if ($needsProcessing) {
            $oImage = new \Imagick($oImagePath);
            if ($cropDimensions !== null) {
                $oImage->cropImage($cropDimensions['width'], $cropDimensions['height'], $cropDimensions['x'], $cropDimensions['y']);
                $oImage->setImagePage(0, 0, 0, 0);
            }
            foreach ($thumbPaths as $p) {
                if (!is_file($p['thumbFile'])) {
                    $thumb = clone $oImage;
                    $thumb->resizeImage($p['width'], $p['height'], \Imagick::FILTER_LANCZOS, 1, true);
                    $thumb->setImageFormat($oImageExtension);
                    if ($oImageExtension == 'png') $thumb->setOption('png:compression-level', 9);
                    else $thumb->setImageCompressionQuality($opt['quality']);
                    $thumb->writeImage($p['thumbFile']);
                    $thumb->destroy();
                }
                if ($opt['webp'] && !is_file($p['webpFile'])) {
                    $tw = clone $oImage;
                    $tw->resizeImage($p['width'], $p['height'], \Imagick::FILTER_LANCZOS, 1, true);
                    $tw->setImageFormat('webp');
                    $tw->setImageCompressionQuality($opt['quality']);
                    $tw->writeImage($p['webpFile']);
                    $tw->destroy();
                }
            }
            $oImage->clear();
            $oImage->destroy();
        }

        // RETURN FORMAT FOR LAYOUTS
        return [
            'isSvg'      => ($oImageExtension === 'svg'),
            'src'        => $oImageExtension === 'svg' ? self::safePath(str_replace(JPATH_ROOT . '/', '', $oImagePath)) : null,
            'srcset'     => implode(', ', $srcsetParts),
            'webpSrcset' => $opt['webp'] ? implode(', ', $srcsetPartsWebp) : null,
            'fallback'   => explode(' ', end($srcsetParts))[0],
            'sizes'      => htmlspecialchars($opt['sizes']),
            'alt'        => $escAlt,
            'width'      => $oImageParams['width'],
            'height'     => $oImageParams['height'],
            'loading'    => $opt['lazy'] ? 'loading="lazy"' : '',
            'extension'  => $oImageExtension
        ];
    }

    /**
     * Fallback to render a standard image tag if the plugin is disabled.
     */
    private static function renderSimple($imageField, $options): string
    {
        if (is_string($imageField)) $imageField = json_decode($imageField, true);
        
        $path = $imageField['imagefile'] ?? '';
        $alt  = htmlspecialchars($imageField['alt_text'] ?? ($options['alt'] ?? ''), ENT_QUOTES);

        if (!$path) return '';

        // Remove the # dimensions Joomla adds to the path
        $src = explode('#', $path)[0];

        return sprintf('<img src="%s" alt="%s" loading="lazy">', $src, $alt);
    }
}
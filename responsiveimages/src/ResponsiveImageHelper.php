<?php
declare(strict_types=1);

/**
 * @package     Joomla.Plugin
 * @subpackage  System.ResponsiveImages
 *
 * @copyright   (C) 2026 web-tiki
 * @license     GNU General Public License version 2 or later
 */

namespace WebTiki\Plugin\System\ResponsiveImages;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\PluginHelper;
use Imagick;
use RuntimeException;
use Throwable;

final class ResponsiveImageHelper
{
    /* ==========================================================
     * Path & URL helpers
     * ========================================================== */

    private static function safeUrl(string $path): string
    {
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function assertInsideRoot(string $path): string
    {
        $real = realpath($path);
        $root = realpath(JPATH_ROOT);

        if (!$real || !$root || !str_starts_with(str_replace('\\','/', $real), str_replace('\\','/', $root))) {
            throw new RuntimeException('Invalid image path or outside Joomla root');
        }

        return $real;
    }

    /* ==========================================================
     * Image helpers
     * ========================================================== */

    private static function calculateCrop(
        int $ow,
        int $oh,
        float $ratio
    ): array {
        $or = $oh / $ow;

        if ($or > $ratio) {
            $h = (int) round($ow * $ratio);
            return [$ow, $h, 0, (int)(($oh - $h) / 2)];
        }

        $w = (int) round($oh / $ratio);
        return [$w, $oh, (int)(($ow - $w) / 2), 0];
    }

    /* ==========================================================
     * Get svg image dimensions
     * ========================================================== */
    private static function getSvgDimensions(string $path): array
    {
        $w = $h = null;
        $svg = @file_get_contents($path);
        if (!$svg) {
            return [$w, $h];
        }

        // Match width/height attributes
        if (preg_match('/<svg[^>]+width=["\']?([\d.]+)(?:px)?["\']?[^>]*height=["\']?([\d.]+)(?:px)?["\']?/i', $svg, $matches)) {
            $w = (int) $matches[1];
            $h = (int) $matches[2];
        } 
        // Fallback: viewBox
        elseif (preg_match('/viewBox=["\']?([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)["\']?/i', $svg, $matches)) {
            $w = (int) $matches[3];
            $h = (int) $matches[4];
        }

        return [$w, $h];
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

        // If plugin is disabled or not found → do nothing
        if (!is_object($plugin)) {
            return [
                'ok'    => true,
                'error' => null,
                'data'  => null,
            ];
        }
        
        if (is_object($plugin) && isset($plugin->params)) {
            $params = json_decode((string) $plugin->params, true) ?: [];
        }

        $defaults = [
            'lazy'        => (bool)($params['lazy'] ?? true),
            'webp'        => (bool)($params['webp'] ?? true),
            'sizes'       => (string)($params['sizes'] ?? '100vw'),
            'widths'      => array_map('intval', explode(',', $params['widths'] ?? '640,1280,1920')),
            'quality'     => max(1, min(100, (int)($params['quality'] ?? 75))),
            'outputDir'   => trim($params['thumb_dir'] ?? 'responsive-images', '/'),
            'alt'         => '',
            'aspectRatio' => null,
        ];

        $opt = array_merge($defaults, $options);

        if (empty($opt['widths']) || !is_array($opt['widths'])) {
            return self::fail('Invalid widths configuration');
        }

        /* ---------------- Parse field ---------------- */

        // Normalize $imageField to array
        if (is_string($imageField)) {
            $imageField = json_decode($imageField, true);
        } elseif (is_object($imageField)) {
            $imageField = (array) $imageField;
        }

        if (!is_array($imageField)) {
            return self::fail('Invalid image field');
        }

        $path = $imageField['imagefile'] ?? '';
        $alt  = $imageField['alt_text'] ?? '';

        if (!$path) {
            // if the path is empty ( = the media field is empty), don't diaply errors
            return [
                'ok'    => true,
                'error' => null,
                'data'  => null,
            ];
        }

        /* ---------------- Normalize path ---------------- */

        $path = explode('#', $path, 2)[0];
        $path = rawurldecode($path);
        $path = str_replace(['\\','\/'], DIRECTORY_SEPARATOR, $path);

        if (!str_starts_with($path, JPATH_ROOT)) {
            $path = rtrim(JPATH_ROOT, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
            $path = preg_replace('#/images/images/#', '/images/', $path, 1);
        }

        $segments = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') array_pop($segments);
            else $segments[] = $seg;
        }
        $path = implode(DIRECTORY_SEPARATOR, $segments);

        try {
            $path = self::assertInsideRoot($path);
        } catch (\RuntimeException $e) {
            return self::fail($e->getMessage());
        }

        if (!is_file($path)) {
            return self::fail('Image file not found: ' . $path);
        }

        $info = pathinfo($path);
        $ext  = strtolower($info['extension'] ?? '');

        /* ---------------- SVG handling ---------------- */
        if ($ext === 'svg') {
            $src = '/' . ltrim(str_replace(DIRECTORY_SEPARATOR, '/', str_replace(JPATH_ROOT, '', $path)), '/');
            [$w, $h] = self::getSvgDimensions($path);
        
            return [
                'ok'    => true,
                'error' => null,
                'data'  => [
                    'isSvg'   => true,
                    'src'     => $src,
                    'alt'     => htmlspecialchars(trim($alt) ?: $info['filename'], ENT_QUOTES),
                    'width'   => $w ?: null,
                    'height'  => $h ?: null,
                    'loading' => $opt['lazy'] ? 'loading="lazy"' : '',
                ],
            ];
        }

        /* ---------------- Raster image handling ---------------- */

        [$ow, $oh] = getimagesize($path) ?: [0, 0];
        if (!$ow || !$oh) {
            return self::fail('Unable to read image dimensions');
        }

        $ratio = $oh / $ow;
        $crop  = null;

        if (is_numeric($opt['aspectRatio']) && $opt['aspectRatio'] > 0) {
            $crop = self::calculateCrop($ow, $oh, (float)$opt['aspectRatio']);
            [$ow, $oh] = [$crop[0], $crop[1]];
            $ratio = $oh / $ow;
        }

        /* ---------------- Output directory ---------------- */

        $imagesRoot = realpath(JPATH_ROOT . '/images');
        $realImage  = realpath($path);

        if (!$imagesRoot || !$realImage || !str_starts_with($realImage, $imagesRoot)) {
            return self::fail('Image is outside /images directory');
        }

        $relativeDir = trim(
            str_replace($imagesRoot, '', dirname($realImage)),
            DIRECTORY_SEPARATOR
        );

        $outBase = JPATH_ROOT . '/images/' . trim($opt['outputDir'], '/');
        if ($relativeDir !== '') {
            $outBase .= '/' . $relativeDir;
        }

        if (!is_dir($outBase) && !mkdir($outBase, 0755, true)) {
            return self::fail('Failed to create thumbnail directory : ' . $outBase);
        }

        $hash = substr(md5($path . filemtime($path)), 0, 8);

        $srcset = [];
        $srcsetWebp = [];
        $jobs = [];

        // Remove duplicate widths and sort ascending
        $widths = array_unique(array_map('intval', $opt['widths']));
        sort($widths);

        foreach ($widths as $w) {
            // Never overscale
            if ($w > $ow) {
                $w = $ow;
            }
        
            $h = (int) round($w * $ratio);
        
            if ($w <= 0 || $h <= 0) {
                continue;
            }
        
            // Avoid duplicate jobs if width already processed
            if (in_array($w, array_column($jobs, 2), true)) {
                continue;
            }
        
            $base = sprintf(
                '%s/%s-%s-q%d-%dx%d',
                $outBase,
                $info['filename'],
                $hash,
                $opt['quality'],
                $w,
                $h
            );
        
            $file = $base . '.' . $ext;
            $webp = $base . '.webp';
        
            $jobs[] = [$file, $webp, $w, $h];
        
            $srcset[] = '/' . self::safeUrl(str_replace(JPATH_ROOT . '/', '', $file)) . " {$w}w";
            if ($opt['webp']) {
                $srcsetWebp[] = '/' . self::safeUrl(str_replace(JPATH_ROOT . '/', '', $webp)) . " {$w}w";
            }
        }

        if (empty($jobs)) {
            return self::fail('No valid thumbnail sizes generated');
        }

        if (!class_exists(Imagick::class)) {
            return self::fail('Imagick extension is not available');
        }

        $lock = fopen($outBase . '/.lock', 'c');
        if (!$lock) {
            return self::fail('Unable to create lock file');
        }

        if (flock($lock, LOCK_EX)) {
            try {
                $img = new Imagick($path);
            } catch (Throwable) {
                fclose($lock);
                return self::fail('Imagick failed to load image');
            }

            if ($crop) {
                $img->cropImage(...$crop);
                $img->setImagePage(0, 0, 0, 0);
            }

            foreach ($jobs as [$file, $webp, $w, $h]) {
                if (!is_file($file)) {
                    $tmp = tempnam(dirname($file), 'ri_');
                    $t = clone $img;
                    $t->resizeImage($w, $h, Imagick::FILTER_LANCZOS, 1, true);
                    $t->setImageFormat($ext);
                    $t->setImageCompressionQuality($opt['quality']);
                    $t->writeImage($tmp);

                    if (!rename($tmp, $file)) {
                        return self::fail('Failed to write thumbnail: ' . basename($file));
                    }

                    $t->clear();
                }

                if ($opt['webp'] && !is_file($webp)) {
                    $tmp = tempnam(dirname($webp), 'ri_');
                    $t = clone $img;
                    $t->resizeImage($w, $h, Imagick::FILTER_LANCZOS, 1, true);
                    $t->setImageFormat('webp');
                    $t->setImageCompressionQuality($opt['quality']);
                    $t->writeImage($tmp);

                    if (!rename($tmp, $webp)) {
                        return self::fail('Failed to write WebP thumbnail: ' . basename($webp));
                    }

                    $t->clear();
                }
            }

            $img->clear();
            flock($lock, LOCK_UN);
        }

        fclose($lock);

        $fallback = explode(' ', end($srcset))[0];

        return [
            'ok'    => true,
            'error' => null,
            'data'  => [
                'isSvg'      => false,
                'srcset'     => implode(', ', $srcset),
                'webpSrcset' => $opt['webp'] ? implode(', ', $srcsetWebp) : null,
                'fallback'   => $fallback,
                'sizes'      => htmlspecialchars($opt['sizes'], ENT_QUOTES),
                'alt'        => htmlspecialchars(trim($alt) ?: $info['filename'], ENT_QUOTES),
                'width'      => $ow,
                'height'     => $oh,
                'loading'    => $opt['lazy'] ? 'loading="lazy"' : '',
                'decoding'   => 'decoding="async"',
                'extension'  => $ext,
            ],
        ];
    }
}

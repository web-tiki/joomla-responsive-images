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

use Imagick;
use Throwable;

final class ThumbnailGenerator

{
    public static function generateThumbnails(
        OriginalImage $image,
        ThumbnailSet $set,
        array $cropBox,
        array $options,
        DebugTimeline $debug
    ): void {
        if (!class_exists(\Imagick::class)) {
            $debug->log(
                'ThumbnailGenerator',
                'Imagick not available, generation aborted'
            );
            return;
        }
        
    
        $lockFile = JPATH_ROOT
            . '/media/ri-responsiveimages/.locks/'
            . sha1($image->hash . serialize($cropBox))
            . '.lock';

        $lockAcquired = false;

        if (!self::acquireLock($lockFile, $debug)) {
            $debug->log('ThumbnailGenerator', 'Generation locked, skipping');
            return;
        }

        $lockAcquired = true;
    
        try {
            $img = new \Imagick($image->filePath);
    
            if (
                is_numeric($options['aspectRatio']) &&
                $options['aspectRatio'] > 0 &&
                (
                    $image->width  !== $cropBox['width'] ||
                    $image->height !== $cropBox['height']
                )
            ) {
                $img->cropImage(
                    $cropBox['width'],
                    $cropBox['height'],
                    $cropBox['x'],
                    $cropBox['y']
                );
                $img->setImagePage(0, 0, 0, 0);
                $debug->log('ThumbnailGenerator', 'Cropped original image for generation with this cropBox : ', $cropBox);
            } else {
                $debug->log('ThumbnailGenerator', 'No fixed aspectRatio given or cropBox width = original image width, taking original image for generation');
            }

            // Determine max working width (largest requested thumb)
            $maxWidth = 0;
            foreach ($set as $thumb) {
                $maxWidth = max($maxWidth, $thumb->width);
            }
            // Never exceed original image size
            $maxWidth = min($maxWidth, $image->width);

            // Resize ONCE to working size
            $currentWidth  = $img->getImageWidth();
            $currentHeight = $img->getImageHeight();

            if ($maxWidth < $currentWidth) {
                $baseHeight = (int) round(
                    $maxWidth * $currentHeight / $currentWidth
                );

                $debug->log('ThumbnailGenerator', 'Resized original image to working size : ' . $maxWidth . 'x' . $baseHeight);

                $img->resizeImage(
                    $maxWidth,
                    $baseHeight,
                    \Imagick::FILTER_LANCZOS,
                    1,
                    false
                );

                // Store working width/height in variables
                $workingWidth = $maxWidth;
                $workingHeight = $baseHeight;
            } else {
                $workingWidth = $currentWidth;
                $workingHeight = $currentHeight;
            }


                
            foreach ($set as $thumb) {
                $path = $thumb->filePath;

                if (!$path || is_file($path)) {
                    continue;
                }

                $clone = clone $img;
                $clone->resizeImage($thumb->width, $thumb->height, \Imagick::FILTER_LANCZOS, 1, false);
                $clone->setImageFormat($thumb->extension);
                $clone->setImageCompressionQuality($thumb->quality);

                $dir = dirname($path);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                    $debug->log(
                        'ThumbnailGenerator',
                        'Cannot create thumbnail directory',
                        ['dir' => $dir]
                    );
                    continue;
                }

                if (!$clone->writeImage($path)) {
                    $debug->log(
                        'ThumbnailGenerator',
                        'Failed to write thumbnail',
                        ['path' => $path]
                    );
                    $clone->clear();
                    continue;
                }

                
                $debug->log('ThumbnailGenerator', 'Generated thumbnail : ' . $thumb->width . 'x' . $thumb->height . ' ' . $thumb->extension );
            }
    
            $img->clear();
    
        } catch (\Throwable $e) {
            $debug->log('ThumbnailGenerator', 'Thumbnail generation failed', [
                'error' => $e->getMessage()
            ]);
        } finally {
            if ($lockAcquired) {
                self::releaseLock($lockFile, $debug);
            }
        }
    }
    

    private const LOCK_TTL = 30; // seconds

    private static function acquireLock(string $lockFile, DebugTimeline $debug): bool
    {
        $dir = dirname($lockFile);

        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                $debug->log(
                    'ThumbnailGenerator',
                    'Cannot create lock directory',
                    ['dir' => $dir]
                );
                return false;
            }
        }

        // If lock exists, check TTL
        if (is_file($lockFile)) {
            $data = json_decode((string) @file_get_contents($lockFile), true);

            $created = $data['created'] ?? 0;
            $ttl     = $data['ttl'] ?? self::LOCK_TTL;

            if ($created && (time() - $created) < $ttl) {
                $debug->log(
                    'ThumbnailGenerator',
                    'Lock active, skipping generation',
                    ['age' => time() - $created]
                );
                return false;
            }

            // Lock expired → remove it
            @unlink($lockFile);
            $debug->log(
                'ThumbnailGenerator',
                'Expired lock removed',
                ['age' => time() - $created]
            );
        }

        $payload = json_encode([
            'created' => time(),
            'ttl'     => self::LOCK_TTL,
        ]);

        return (bool) @file_put_contents($lockFile, $payload, LOCK_EX);
    }

    

    private static function releaseLock(string $lockFile, DebugTimeline $debug): void
    {
        if (is_file($lockFile) && !@unlink($lockFile)) {
            $debug->log(
                'ThumbnailGenerator',
                'Cannot remove lock file',
                ['file' => $lockFile]
            );
        }
    }

}

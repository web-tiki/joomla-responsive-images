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

final class ThumbnailManifest
{
    private string $path;
    private OriginalImage $image;
    private array $data = [];
    private bool $buildRequired = false;

    private function __construct(string $path)
    {
        $this->path  = $path;
    }

    public static function load(
        string $path,
        OriginalImage $image,
        DebugTimeline $debug
    ): self {
        $self = new self($path);
    
        if (!is_file($path)) {
            $self->buildRequired = true;
            $self->init($image, $debug);
            $debug->log('manifest', 'Manifest doesn\'t exist : ' . basename($path));
            return $self;
        }
    
        $json = json_decode((string) file_get_contents($path), true);
    
        if (!is_array($json)) {
            $self->buildRequired = true;
            $self->init($image,$debug);
            $debug->log('manifest', 'Invalid json from manifest');
            return $self;
        }

        if (($json['source']['mtime'] ?? null) !== $image->mTime) {
            $self->buildRequired = true;
            $self->init($image,$debug);
            $debug->log('manifest', 'Manifest source mtime mismatch, rebuilding');
            return $self;
        }
    
        $self->data = $json;
    
        $debug->log('manifest', 'Manifest mtime matches original image', $self->data);
    
        return $self;
    }
    

    private function init(OriginalImage $image, DebugTimeline $debug): void
    {
        $this->data = [
            'version' => 1,
            'source' => [
                'path'   => $image->path,
                'mtime'  => $image->mTime,
                'size'   => filesize($image->filePath),
                'width'  => $image->width,
                'height' => $image->height,
                'mime'   => $image->mimeType,
            ],
            'thumbnails' => [],
        ];

        $debug->log('manifest', 'initializing manifest', $this->data);
    }

    public function needsBuild(): bool
    {
        return $this->buildRequired;
    }

    public function needsUpdate(
        ThumbnailSet $set,
        DebugTimeline $debug
    ): array {
        if ($this->buildRequired) {

            $debug->log('manifest', 'Manifest build required', $this->data);
            
            return [];
        }

        return $set->needsUpdate($this->data, $debug);
    }

    public function update(
        OriginalImage $image,
        ThumbnailSet $set,
        DebugTimeline $debug
    ): void {

        foreach ($set as $thumb) {

            // ❌ Skip thumbnails that failed to generate
            if (!$thumb->exists()) {
                $debug->log(
                    'ThumbnailManifest',
                    'Skipping thumbnail, file does not exist',
                    [
                        'key'  => $thumb->getKey(),
                        'path' => $thumb->getAbsolutePath(),
                    ]
                );
                continue;
            }
        
            $thumbKey = $thumb->getKey();
        
            $this->data['thumbnails'][$thumbKey]
                = $thumb->getSrcsetEntry();
        
            $debug->log(
                'ThumbnailManifest',
                'Manifest updated',
                $thumbKey
            );
        }           
    }

    public function save(DebugTimeline $debug): void
    {
        $this->sortThumbnails();

        $tmp = $this->path . '.tmp';

        file_put_contents(
            $tmp,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );


        rename($tmp, $this->path);
        chmod($this->path, 0644);

        $debug->log('ThumbnailManifest', 'Saving manifest : ' . basename($this->path), $this->data);
    }

    /**
     * Get the missing thumbnails
     */
    public function getMissingThumbnails(
        ThumbnailSet $requested,
        DebugTimeline $debug
    ): ThumbnailSet {
        $missing = [];
    
        foreach ($requested as $thumb) {
            $key = $thumb->getKey();
    
            if (empty($this->data['thumbnails'][$key])) {
                $missing[] = $thumb;
            }
        }
    
        if ($missing) {
            $debug->log(
                'ThumbnailManifest',
                'Missing thumbnails detected',
                array_map(fn ($t) => $t->getKey(), $missing)
            );
        }
    
        return new ThumbnailSet($missing);
    }

    /**
     * Sort the thumbnails in the manifest
     */
    private function sortThumbnails(): void
    {
        if (empty($this->data['thumbnails']) || !is_array($this->data['thumbnails'])) {
            return;
        }

        uksort(
            $this->data['thumbnails'],
            static function (string $a, string $b): int {
                [$qa, $ea, $sa] = explode(':', $a);
                [$qb, $eb, $sb] = explode(':', $b);

                // quality
                $cmp = (int)$qa <=> (int)$qb;
                if ($cmp !== 0) {
                    return $cmp;
                }

                // extension
                $cmp = strcmp($ea, $eb);
                if ($cmp !== 0) {
                    return $cmp;
                }

                // dimensions
                [$wa, $ha] = array_map('intval', explode('x', $sa));
                [$wb, $hb] = array_map('intval', explode('x', $sb));

                return ($wa <=> $wb) ?: ($ha <=> $hb);
            }
        );
    }


}

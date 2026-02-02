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

use InvalidArgumentException;

final class ThumbnailSet implements \IteratorAggregate, \Countable
{
    /** @var RequestedThumbnail[] */
    private array $thumbnails = [];

    /**
     * @param RequestedThumbnail[] $thumbnails
     */
    public function __construct(array $thumbnails)
    {
        foreach ($thumbnails as $thumbnail) {
            if (!$thumbnail instanceof RequestedThumbnail) {
                throw new InvalidArgumentException(
                    'ThumbnailSet only accepts RequestedThumbnail objects'
                );
            }

            $this->add($thumbnail);
        }

        $this->sort();
    }

    private function add(RequestedThumbnail $thumbnail): void
    {
        $key = $thumbnail->getKey();

        // prevent duplicates
        $this->thumbnails[$key] = $thumbnail;
    }

    private function sort(): void
    {
        uasort(
            $this->thumbnails,
            fn (RequestedThumbnail $a, RequestedThumbnail $b) => $a->width <=> $b->width
        );
    }

    /* ==========================================================
     * Collection API
     * ========================================================== */

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->thumbnails);
    }

    public function count(): int
    {
        return count($this->thumbnails);
    }
    
    /**
     * @return RequestedThumbnail[]
     */
    public function all(): array
    {
        return array_values($this->thumbnails);
    }

    /* ==========================================================
     * Srcset helpers
     * ========================================================== */
    public function getSrcset(): array
    {
        $srcset = [];
        foreach ($this as $thumb) {
            $srcset[] = $thumb->getSrcsetEntry();
        }
        return $srcset;
    }

    public function getFallBack(): string
    {
        foreach ($this as $thumb) {
            if($thumb->isFallback()){
                return $thumb->getUrl();
            }
        }
        return '';
    }

}

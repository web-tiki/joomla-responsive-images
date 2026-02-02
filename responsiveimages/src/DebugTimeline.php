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

final class DebugTimeline
{
    private bool $enabled;
    private float $start;
    private array $events = [];
    private string $image;

    public function __construct(bool $enabled, string $imagePath)
    {
        $this->enabled = $enabled;
        $this->image   = $imagePath;
        $this->start   = microtime(true);
    }

    public function log(string $step, string $event, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->events[] = [
            't'      => round(microtime(true) - $this->start, 4),
            'step'   => $step,
            'event'  => $event,
            'data'   => $context,
        ];
    }

    public function export(): array
    {
        if (!$this->enabled) {
            return [];
        }

        return [
            'image' => $this->image,
            'events' => $this->events,
            'total_time' => round(microtime(true) - $this->start, 4),
        ];
    }
}

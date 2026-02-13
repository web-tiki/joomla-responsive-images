<?php
declare(strict_types=1);

/**
 * @package     Joomla.Plugin
 * @subpackage  System.ResponsiveImages
 *
 * @copyright   (C) 2026 web-tiki
 * @license     GNU General Public License version 3 or later;
 */

defined('_JEXEC') or die;

$debug = $displayData ?? null;



if (!$debug || empty($debug['events'])) {
    return;
}
?>

<div class="ri-debug-container" style="position:relative;z-index:999;text-align:left;background:#1e1e1e; color:#d4d4d4; padding:15px; border-left:5px solid #0078d4; font-family:monospace; font-size:12px; line-height:1.5; margin: 20px 0; overflow:auto;">    
    <details>
        <summary>
            <strong style="color:#569cd6;">[ResponsiveImages Debug]</strong>
            <small>
                <?= htmlspecialchars($debug['image']) ?>
                (<?= $debug['total_time'] ?> ms)
            </small>
        </summary>

        <ul style="display: block;width: 750px;overflow:auto; padding-bottom: 15px;">
            <?php foreach ($debug['events'] as $e): ?>
                <li style="aspect-ratio: initial;border-top:1px solid #474747">
                    <div style="white-space:nowrap">
                        <strong style="color:#fff;">[<?= $e['t'] ?>s]</strong>
                        <?= $e['step'] ?> → <?= $e['event'] ?>
                    </div>

                    <?php if (!empty($e['data'])): ?>
                        <details>
                            <summary>Data</summary>
                            <pre><?= json_encode($e['data'], JSON_PRETTY_PRINT) ?></pre>
                        </details>
                    <?php endif; ?>
                    
                </li>
            <?php endforeach; ?>
        </ul>
    </details>
</div>
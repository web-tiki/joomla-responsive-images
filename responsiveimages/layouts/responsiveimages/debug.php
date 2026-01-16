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

$log     = $displayData['log'] ?? [];
$options = $displayData['options'] ?? [];
?>
<div class="ri-debug-container" style="background:#1e1e1e; color:#d4d4d4; padding:15px; border-left:5px solid #0078d4; font-family:monospace; font-size:12px; line-height:1.5; margin: 20px 0; overflow:auto;">
    <strong style="color:#569cd6;">[ResponsiveImages Debug]</strong><br/>
    <span>This is debugging information. It can be disabled in the ResponsiveImages plugin options.</span>
    
    <div style="margin-top:10px;">
        <strong style="color:#ce9178;">--- Execution Steps ---</strong>
        <?php foreach ($log as $index => $step) : ?>
            <div style="white-space:nowrap;">
                <span style="color:#808080;">[<?= sprintf('%02d', $index + 1); ?>]</span> <?= htmlspecialchars($step); ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div style="margin-top:10px;">
        <strong style="color:#ce9178;">--- Merged Options ---</strong>
        <pre style="margin:0; color:#9cdcfe;"><?= json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></pre>
    </div>
</div>
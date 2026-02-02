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

/**
 * @var array $displayData The processed image data from the Helper
 */
$data = $displayData;

if (empty($data)) {
    return;
}

// Handle SVG
if (!empty($data['isSvg'])) { ?>
    <img 
        class="<?= $data['imageClass']; ?>"
        src="<?= $data['src']; ?>"
        alt="<?= $data['alt']; ?>"
        width="<?= (int)$data['width']; ?>"
        height="<?= (int)$data['height']; ?>"
        <?= $data['loading']; ?>>
    <?php return; ?>
<?php } ?>

<?php // Handle Raster Images ?>
<picture>
    
    <?php if (!empty($data['srcset'])) { ?>
        <source 
            srcset="<?= $data['srcset']; ?>"
            sizes="<?= $data['sizes']; ?>"
            type="<?= $data['mime_type']; ?>">
    <?php } ?>


    <img 
        class="ri-responsiveimage <?= $data['imageClass']; ?>"
        src="<?= $data['fallback']; ?>"
        alt="<?= $data['alt']; ?>"
        width="<?= (int)$data['width']; ?>"
        height="<?= (int)$data['height']; ?>"
        <?= $data['loading']; ?>>
</picture>
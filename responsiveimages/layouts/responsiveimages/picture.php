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
if (!empty($data['isSvg'])) : ?>
    <img src="<?= $data['src']; ?>"
         alt="<?= $data['alt']; ?>"
         width="<?= (int)$data['width']; ?>"
         height="<?= (int)$data['height']; ?>"
         <?= $data['loading']; ?>
         <?= $data['decoding'] ?? ''; ?>>
<?php return; endif; ?>

<?php // Handle Raster Images ?>
<picture>
    <?php if (!empty($data['webpSrcset'])) : ?>
        <source srcset="<?= $data['webpSrcset']; ?>"
                sizes="<?= $data['sizes']; ?>"
                type="image/webp">
    <?php endif; ?>

    <source srcset="<?= $data['srcset']; ?>"
            sizes="<?= $data['sizes']; ?>"
            type="image/<?= $data['extension']; ?>">

    <img src="<?= $data['fallback']; ?>"
         alt="<?= $data['alt']; ?>"
         width="<?= (int)$data['width']; ?>"
         height="<?= (int)$data['height']; ?>"
         <?= $data['loading']; ?>
         <?= $data['decoding']; ?>>
</picture>
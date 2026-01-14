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

use WebTiki\Plugin\System\ResponsiveImages\ResponsiveImageHelper;

$field   = $displayData['field'] ?? null;
$options = $displayData['options'] ?? [];

if (!class_exists(ResponsiveImageHelper::class)) {
    return;
}

$result = ResponsiveImageHelper::getProcessedData($field, $options);

// Silent exit if plugin disabled
if ($result['ok'] && empty($result['data'])) {
    return;
}

if (!$result['ok']) {
    echo '<!-- ResponsiveImages error: ' .
         htmlspecialchars($result['error'], ENT_QUOTES) .
         ' -->';
    return;
}

$data = $result['data'];

// Safety check and if no image is given, display nothing
if (empty($data)) {
    return;
}

if (!empty($data['isSvg'])) : ?>
<img src="<?= $data['src']; ?>"
     alt="<?= $data['alt']; ?>"
     width="<?= (int)$data['width']; ?>"
     height="<?= (int)$data['height']; ?>"
     <?= $data['loading']; ?>
     <?= $data['decoding'] ?? ''; ?>>
<?php return; endif; ?>

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
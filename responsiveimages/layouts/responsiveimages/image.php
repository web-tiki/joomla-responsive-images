<?php
declare(strict_types=1);

defined('_JEXEC') or die;

use WebTiki\Plugin\System\ResponsiveImages\ResponsiveImageHelper;

$field   = $displayData['field'] ?? null;
$options = $displayData['options'] ?? [];

if (!class_exists(ResponsiveImageHelper::class)) {
    return;
}

$data = ResponsiveImageHelper::getProcessedData($field, $options);

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
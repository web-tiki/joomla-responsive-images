<?php
defined('_JEXEC') or die;

$data = $displayData;

if (empty($data)) {
    return;
}

// Handle SVG
if (!empty($data['isSvg'])) { ?>
    <img 
        class="<?= $data['image-class']; ?>"
        src="<?= $data['src']; ?>"
        alt="<?= $data['alt']; ?>"
        width="<?= (int)$data['width']; ?>"
        height="<?= (int)$data['height']; ?>"
        <?= $data['loading']; ?>
        <?= $data['decoding'] ?? ''; ?>>
    <?php return; ?>
<?php } ?>

<?php 
// Handle Raster Images
// Safely prepare the strings from the child 'sources' array
$webpSrcsetString = !empty($data['sources']['webpSrcset']) ? implode(', ', $data['sources']['webpSrcset']) : '';
$stdSrcsetString  = !empty($data['sources']['srcset'])     ? implode(', ', $data['sources']['srcset'])     : '';
?>

<picture>
    <?php if ($webpSrcsetString) : ?>
        <source
            srcset="<?= $webpSrcsetString; ?>"
            sizes="<?= $data['sizes']; ?>"
            type="image/webp">
    <?php endif; ?>

    <?php if ($stdSrcsetString) : ?>
        <source 
            srcset="<?= $stdSrcsetString; ?>"
            sizes="<?= $data['sizes']; ?>"
            type="<?= $data['mime_type']; ?>">
    <?php endif; ?>

    <img 
        class="ri-responsiveimage <?= $data['image-class']; ?>"
        src="<?= $data['fallback']; ?>"
        alt="<?= $data['alt']; ?>"
        width="<?= (int)$data['width']; ?>"
        height="<?= (int)$data['height']; ?>"
        <?= $data['loading']; ?>
        <?= $data['decoding']; ?>>
</picture>
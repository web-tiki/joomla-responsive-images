<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.ResponsiveImages
 */

defined('_JEXEC') or die;

use WebTiki\Plugin\System\ResponsiveImages\ResponsiveImageHelper;

$field   = $displayData['field'] ?? null;
$options = $displayData['options'] ?? [];

// Insurance policy: prevents site crash if plugin is partially missing
if (!class_exists(ResponsiveImageHelper::class)) {
    return;
}

// Get the raw data from the helper
$data = ResponsiveImageHelper::getProcessedData($field, $options);

if (!$data) { return '<!-- data is null -->'; }
if (empty($data)) { return '<!-- data is empty -->'; }

// 1. Handle SVG Case
if ($data['isSvg']) : ?>
    <img src="<?php echo $data['src']; ?>" 
         alt="<?php echo $data['alt']; ?>" 
         width="<?php echo $data['width']; ?>" 
         height="<?php echo $data['height']; ?>" 
         <?php echo $data['loading']; ?>>
<?php return; endif; ?>

<?php // 2. Handle Responsive Picture Case ?>
<picture>
    <?php if ($data['webpSrcset']) : ?>
        <source srcset="<?php echo $data['webpSrcset']; ?>" 
                sizes="<?php echo $data['sizes']; ?>" 
                type="image/webp">
    <?php endif; ?>
    
    <source srcset="<?php echo $data['srcset']; ?>" 
            sizes="<?php echo $data['sizes']; ?>" 
            type="image/<?php echo $data['extension']; ?>">
            
    <img src="<?php echo $data['fallback']; ?>" 
         alt="<?php echo $data['alt']; ?>" 
         width="<?php echo $data['width']; ?>" 
         height="<?php echo $data['height']; ?>" 
         <?php echo $data['loading']; ?>>
</picture>
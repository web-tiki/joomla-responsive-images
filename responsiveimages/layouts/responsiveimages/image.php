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
use Joomla\CMS\Layout\LayoutHelper;

$imageField = $displayData['imageField'] ?? null;
$options    = $displayData['options'] ?? [];
$basePath   = JPATH_PLUGINS . '/system/responsiveimages/layouts';

if (!class_exists(ResponsiveImageHelper::class)) return;

$result = ResponsiveImageHelper::getProcessedData($imageField, $options);

// Render the Debug Layout if it exists (on success OR failure)
if (!empty($result['debug_data'])) {
    echo LayoutHelper::render('responsiveimages.debug', $result['debug_data'], $basePath);
}

if ($result['ok'] && !empty($result['data'])) {
    echo LayoutHelper::render('responsiveimages.picture', $result['data'], $basePath);
} elseif (!$result['ok']) {
    // Hidden error comment if not in debug mode
    echo '';
}
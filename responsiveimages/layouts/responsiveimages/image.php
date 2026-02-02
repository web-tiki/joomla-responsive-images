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

use Joomla\CMS\Plugin\PluginHelper;
use WebTiki\Plugin\System\ResponsiveImages\ResponsiveImageHelper;
use Joomla\CMS\Layout\LayoutHelper;

$plugin = PluginHelper::getPlugin('system', 'responsiveimages');

// Plugin disabled → do nothing
if (!is_object($plugin)) {
    return;
}

$imageField = $displayData['imageField'] ?? null;
$options    = $displayData['options'] ?? [];
$basePath   = JPATH_PLUGINS . '/system/responsiveimages/layouts';

if (!class_exists(ResponsiveImageHelper::class)) return;

$result = ResponsiveImageHelper::getProcessedData($imageField, $options);

// Render the Debug Layout if ther is debug data
if (!empty($result['debug'])) {
    // echo LayoutHelper::render('responsiveimages.debug', $result['debug'], $basePath);
    echo LayoutHelper::render('responsiveimages.debug', $result['debug'], $basePath);
}

if ($result['ok'] && !empty($result['data'])) {
    echo LayoutHelper::render('responsiveimages.picture', $result['data'], $basePath);
} elseif (!$result['ok']) {
    // Hidden error comment if not in debug mode but there still is an error message
    echo '<!--' . $result['error'] . '-->';
}
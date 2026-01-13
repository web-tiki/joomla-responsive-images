<?php
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Filesystem\Folder;

class PlgSystemResponsiveImages extends CMSPlugin
{
    
    public function onAfterInitialise()
    {
        // Register the layout path
        LayoutHelper::addIncludePath(JPATH_PLUGINS . '/system/responsiveimages/layouts');
        
        // Handle your purge task if needed (uncommented and fixed for Joomla 6)
        if ($this->app->isClient('administrator')) {
            if ($this->app->input->getCmd('task') === 'plugin.purgeResponsiveCache') {
                $this->purgeCache();
            }
        }
    }
    /*

    private function purgeCache()
    {
        $path = JPATH_ROOT . '/images/responsive';
        if (is_dir($path)) {
            Folder::delete($path);
            $this->app->enqueueMessage('✅ Responsive image cache purged successfully.');
        } else {
            $this->app->enqueueMessage('ℹ️ No responsive cache directory found.');
        }
        $this->app->redirect('index.php?option=com_plugins&view=plugins&filter[folder]=system');
    }
        */
}

<?php
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Filesystem\Folder;

class PlgSystemResponsiveImages extends CMSPlugin
{
    /*
    public function onAfterInitialise()
    {
        if ($this->app->isClient('administrator')) {
            if ($this->app->input->getCmd('task') === 'plugin.purgeResponsiveCache') {
                $this->purgeCache();
            }
        }
    }

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

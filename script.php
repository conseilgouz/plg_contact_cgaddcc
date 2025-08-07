<?php
/**
* Plugin Contact Add bCC email to contact messages
* copyright 		: Copyright (C) 2025 ConseilGouz. All rights reserved.
* license    		: https://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
*/
// No direct access to this file
defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;
use Joomla\Filesystem\Folder;
use Joomla\Filesystem\File;

class plgcontactcgaddccInstallerScript
{
    private $min_joomla_version      = '4.0.0';
    private $min_php_version         = '8.0';
    private $name                    = 'Plugin Contact CG Add CC';
    private $exttype                 = 'plugin';
    private $extname                 = 'cgaddcc';
    private $previous_version        = '';
    private $dir           = null;
    private $db;
    private $lang;
    private $installerName = 'plgcontactcgaddccinstaller';
    public function __construct()
    {
        $this->dir = __DIR__;
        $this->lang = Factory::getApplication()->getLanguage();
        $this->lang->load($this->extname);
    }

    public function preflight($type, $parent)
    {
        if (! $this->passMinimumJoomlaVersion()) {
            $this->uninstallInstaller();
            return false;
        }

        if (! $this->passMinimumPHPVersion()) {
            $this->uninstallInstaller();
            return false;
        }
        // To prevent installer from running twice if installing multiple extensions
        if (! file_exists($this->dir . '/' . $this->installerName . '.xml')) {
            return true;
        }
    }
    public function uninstall($parent)
    {
        return true;
    }

    public function postflight($type, $parent)
    {
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);

        if (($type == 'install') || ($type == 'update')) { // remove obsolete dir/files
            $this->postinstall_cleanup();
        }

        switch ($type) {
            case 'install': $message = Text::_('INSTALLED');
                break;
            case 'uninstall': $message = Text::_('UNINSTALLED');
                break;
            case 'update': $message = Text::_('UPDATED');
                break;
            case 'discover_install': $message = Text::_('DISC_INSTALLED');
                break;
        }
        return true;
    }
    private function postinstall_cleanup()
    {
        $db = $this->db;
        // update Com_Contact : set Custom_Reply to 1
        $query = $db->getQuery(true);
        $query->select('params')
        ->from($db->quoteName('#__extensions'))
        ->where($db->quoteName('type').'='.$db->quote('component'))
        ->where($db->quoteName('element').'='.$db->quote('com_contact'));
        $db->setQuery($query);
        $params = $db->loadResult();
        $prms = json_decode($params);
        if ($prms->custom_reply == 0) {
            $prms->custom_reply = 1;
            $params = json_encode($prms);
            $conditions = array(
                $db->qn('type') . ' = ' . $db->q('component'),
                $db->qn('element') . ' = ' . $db->quote('com_contact')
            );
            $fields = array($db->qn('params') . ' = ' .$db->q($params) );
            $query = $db->getQuery(true);
            $query->update($db->quoteName('#__extensions'))->set($fields)->where($conditions);
            $db->setQuery($query);
            try {
                $db->execute();
            } catch (\RuntimeException $e) {
                Log::add('unable to update com_contact', Log::ERROR, 'jerror');
            }
        }
        // enable plugin
        $conditions = array(
            $db->qn('type') . ' = ' . $db->q('plugin'),
            $db->qn('element') . ' = ' . $db->quote($this->extname)
        );
        $fields = array($db->qn('enabled') . ' = 1');

        $query = $db->getQuery(true);
        $query->update($db->quoteName('#__extensions'))->set($fields)->where($conditions);
        $db->setQuery($query);
        try {
            $db->execute();
        } catch (\RuntimeException $e) {
            Log::add('unable to enable '.$this->name, Log::ERROR, 'jerror');
        }
    }
    // Check if Joomla version passes minimum requirement
    private function passMinimumJoomlaVersion()
    {
        $j = new Version();
        $version = $j->getShortVersion();
        if (version_compare($version, $this->min_joomla_version, '<')) {
            Factory::getApplication()->enqueueMessage(
                'Incompatible Joomla version : found <strong>' . $version . '</strong>, Minimum : <strong>' . $this->min_joomla_version . '</strong>',
                'error'
            );

            return false;
        }

        return true;
    }

    // Check if PHP version passes minimum requirement
    private function passMinimumPHPVersion()
    {

        if (version_compare(PHP_VERSION, $this->min_php_version, '<')) {
            Factory::getApplication()->enqueueMessage(
                'Incompatible PHP version : found  <strong>' . PHP_VERSION . '</strong>, Minimum <strong>' . $this->min_php_version . '</strong>',
                'error'
            );
            return false;
        }

        return true;
    }
    private function uninstallInstaller()
    {
        if (! is_dir(JPATH_PLUGINS . '/system/' . $this->installerName)) {
            return;
        }
        $this->delete([
            JPATH_PLUGINS . '/system/' . $this->installerName . '/language',
            JPATH_PLUGINS . '/system/' . $this->installerName,
        ]);
        $db = $this->db;
        $query = $db->getQuery(true)
            ->delete('#__extensions')
            ->where($db->quoteName('element') . ' = ' . $db->quote($this->installerName))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'));
        $db->setQuery($query);
        $db->execute();
        Factory::getApplication()->getCache()->clean('_system');
    }
    public function delete($files = [])
    {
        foreach ($files as $file) {
            if (is_dir($file)) {
                Folder::delete($file);
            }

            if (is_file($file)) {
                File::delete($file);
            }
        }
    }
}

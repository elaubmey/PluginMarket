<?php

require_once 'lib/datei.inc.php';
require_once __DIR__."/../vendor/Parsedown.php";

class MarketRelease extends SimpleORMap {

    static public function getReleaseDataPath() {
        return $GLOBALS['STUDIP_BASE_PATH'] . "/data/pluginmarket_releases";
    }

    static public function findByPlugin_id($plugin_id) {
        return self::findBySQL("plugin_id = ? ORDER BY version DESC", array($plugin_id));
    }

    protected static function configure($config = array())
    {
        $config['db_table'] = 'pluginmarket_releases';
        $config['belongs_to']['plugin'] = array(
            'class_name' => 'MarketPlugin',
            'foreign_key' => 'plugin_id',
        );
        $config['has_many']['followers'] = array(
            'class_name' => 'MarketReleaseFollower',
            'on_delete' => 'delete',
            'on_store' => 'store',
        );
        parent::configure($config);
    }

    public function delete() {
        parent::delete();
        unlink($this->getFilePath());
    }

    public function installFile() {
        $hash = md5(uniqid());
        $tmp_folder = $GLOBALS['TMP_PATH']."/temp_plugin_".$hash;
        mkdir($tmp_folder);
        $file = $GLOBALS['TMP_PATH']."/temp_plugin_".$hash.".zip";
        if ($this['repository_download_url']) {
            file_put_contents($file, file_get_contents($this['repository_download_url']));
        } elseif ($_FILES['release_file']['tmp_name']) {
            move_uploaded_file($_FILES['release_file']['tmp_name'], $file);
        } else {
            return false;
        }
        unzip_file($file, $tmp_folder);
        $objects = scandir($tmp_folder);
        if (count($objects) === 3) {
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    $plugin_dir = $tmp_folder."/".$object;
                }
            }
        } else {
            $plugin_dir = $tmp_folder;
        }
        $this->installFromDirectory($plugin_dir);

        rmdirr($tmp_folder);
        unlink($file);
        $this['chdate'] = time();

        NotificationCenter::postNotification("PluginReleaseDidUpdateCode", $this);
    }

    protected function getFilePath() {
        if (!file_exists(self::getReleaseDataPath())) {
            mkdir(self::getReleaseDataPath());
        }
        if (!$this->getId()) {
            $this->setId($this->getNewId());
        }
        return self::getReleaseDataPath()."/".$this->getId();
    }

    public function outputZip()
    {
        header('Content-Type: ' . $this->getMimeType());
        header('Content-Disposition: attachment; filename="' . $this->getFilename() . '"');

        readfile($this->getFilePath());
    }

    public function getMimeType()
    {
        return 'application/zip';
    }

    public function getFilename()
    {
        return $this->plugin['name'] . '.zip';
    }

    public function getContentLength()
    {
        return filesize($this->getFilePath());
    }

    public function getContent()
    {
        return file_get_contents($this->getFilePath());
    }

    public function getChecksum() {
        return md5_file($this->getFilePath());
    }

    public function getSecurityHash() {
        return md5($this->getId()."-".$this['mkdate']);
    }

    protected function installFromDirectory($dir) {
        $manifest = PluginManager::getInstance()->getPluginManifest($dir);
        if ($manifest['pluginclassname']) {
            $this->plugin['pluginclassname'] = $manifest['pluginclassname'];
            $this->plugin->store();
        }
        $this['studip_min_version'] = $manifest['studipMinVersion'];
        $this['studip_max_version'] = $manifest['studipMaxVersion'];
        $this['version'] = $manifest['version'];
        if ($this['repository_overwrites_descriptionfrom']) {
            $readme = "";
            $scanner = scandir($dir);
            foreach ($scanner as $file) {
                if (strtolower($file) === "readme.md" || strtolower($file) === "readme.markdown") {
                    $readme = file_get_contents($dir."/".$file);
                }
            }
            if ($readme) {
                $html = Parsedown::instance()->text($readme);
                $this->plugin['description'] = "<div>".studip_utf8decode($html)."</div>";
                $this->plugin->store();
            }
        }
        $this->store();
        $hash = md5(uniqid());
        $plugin_raw = $GLOBALS['TMP_PATH']."/plugin_$hash.zip";
        create_zip_from_directory($dir, $plugin_raw);

        copy($plugin_raw, $this->getFilePath());
        unlink($plugin_raw);
        return true;
    }

    /**
     * Checks if the release works with the given Stud.IP version
     *
     * @param String $version Version to check for
     */
    public function checkVersion($version) {
        return ( !$this->studip_min_version || version_compare($version, $this->studip_min_version) >= 0 )
                && ( !$this->studip_max_version || version_compare($version, $this->studip_max_version) <= 0 );
    }

}
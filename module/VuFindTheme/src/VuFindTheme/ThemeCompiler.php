<?php
/**
 * Class to compile a theme hierarchy into a single flat theme.
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2017.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Theme
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Site
 */
namespace VuFindTheme;

/**
 * Class to compile a theme hierarchy into a single flat theme.
 *
 * @category VuFind
 * @package  Theme
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Site
 */
class ThemeCompiler
{
    /**
     * Theme info object
     *
     * @var ThemeInfo
     */
    protected $info;

    /**
     * Last error message
     *
     * @var string
     */
    protected $lastError = null;

    /**
     * Constructor
     *
     * @param ThemeInfo $info Theme info object
     */
    public function __construct(ThemeInfo $info)
    {
        $this->info = $info;
    }

    /**
     * Compile from $source theme into $target theme.
     *
     * @param string $source         Name of source theme
     * @param string $target         Name of target theme
     * @param bool   $forceOverwrite Should we overwrite the target if it exists?
     *
     * @return bool
     */
    public function compile($source, $target, $forceOverwrite = false)
    {
        // Validate input:
        try {
            $this->info->setTheme($source);
        } catch (\Exception $ex) {
            return $this->setLastError($ex->getMessage());
        }
        // Validate output:
        $baseDir = $this->info->getBaseDir();
        $targetDir = "$baseDir/$target";
        if (file_exists($targetDir)) {
            if (!$forceOverwrite) {
                return $this->setLastError(
                    'Cannot overwrite ' . $targetDir . ' without --force switch!'
                );
            }
            if (!$this->deleteDir($targetDir)) {
                return false;
            }
        }
        if (!mkdir($targetDir)) {
            return $this->setLastError("Cannot create $targetDir");
        }

        // Copy all the files, relying on the fact that the output of getThemeInfo
        // includes the entire theme inheritance chain in the appropriate order:
        $info = $this->info->getThemeInfo();
        $config = [];
        foreach ($info as $source => $currentConfig) {
            $config = $this->mergeConfig($currentConfig, $config);
            if (!$this->copyDir("$baseDir/$source", $targetDir)) {
                return false;
            }
        }
        $configFile = "$targetDir/theme.config.php";
        $configContents = '<?php return ' . var_export($config, true) . ';';
        if (!file_put_contents($configFile, $configContents)) {
            return $this->setLastError("Problem exporting $configFile.");
        }
        return true;
    }

    /**
     * Get last error message.
     *
     * @return string
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Remove a theme directory (used for cleanup in testing).
     *
     * @param string $theme Name of theme to remove.
     *
     * @return bool
     */
    public function removeTheme($theme)
    {
        return $this->deleteDir($this->info->getBaseDir() . '/' . $theme);
    }

    /**
     * Copy the contents of $src into $dest if no matching files already exist.
     *
     * @param string $src  Source directory
     * @param string $dest Target directory
     *
     * @return bool
     */
    protected function copyDir($src, $dest)
    {
        if (!is_dir($dest)) {
            if (!mkdir($dest)) {
                return $this->setLastError("Cannot create $dest");
            }
        }
        $dir = opendir($src);
        while ($current = readdir($dir)) {
            if ($current === '.' || $current === '..') {
                continue;
            }
            if (is_dir("$src/$current")) {
                if (!$this->copyDir("$src/$current", "$dest/$current")) {
                    return false;
                }
            } else if (!file_exists("$dest/$current")
                && !copy("$src/$current", "$dest/$current")
            ) {
                return $this->setLastError(
                    "Cannot copy $src/$current to $dest/$current."
                );
            }
        }
        closedir($dir);
        return true;
    }

    /**
     * Recursively delete a directory and its contents.
     *
     * @param string $path Directory to delete.
     *
     * @return bool
     */
    protected function deleteDir($path)
    {
        $dir = opendir($path);
        while ($current = readdir($dir)) {
            if ($current === '.' || $current === '..') {
                continue;
            }
            if (is_dir("$path/$current")) {
                if (!$this->deleteDir("$path/$current")) {
                    return false;
                }
            } else if (!unlink("$path/$current")) {
                return $this->setLastError("Cannot delete $path/$current");
            }
        }
        closedir($dir);
        return rmdir($path);
    }

    /**
     * Merge configurations from $src into $dest; return the result.
     *
     * @param array $src  Source configuration
     * @param array $dest Destination configuration
     *
     * @return array
     */
    protected function mergeConfig($src, $dest)
    {
        foreach ($src as $key => $value) {
            switch ($key) {
            case 'extends':
                // always set "extends" to false; we're flattening, after all!
                $dest[$key] = false;
                break;
            case 'helpers':
                // Call this function recursively to deal with the helpers
                // sub-array:
                $dest[$key] = $this
                    ->mergeConfig($value, isset($dest[$key]) ? $dest[$key] : []);
                break;
            case 'mixins':
                // Omit mixin settings entirely
                break;
            default:
                // Default behavior: merge arrays, let existing flat settings
                // trump new incoming ones:
                if (!isset($dest[$key])) {
                    $dest[$key] = $value;
                } else if (is_array($dest[$key])) {
                    $dest[$key] = array_merge($value, $dest[$key]);
                }
                break;
            }
        }
        return $dest;
    }

    /**
     * Set last error message and return a boolean false.
     *
     * @param string $error Error message.
     *
     * @return bool
     */
    protected function setLastError($error)
    {
        $this->lastError = $error;
        return false;
    }
}

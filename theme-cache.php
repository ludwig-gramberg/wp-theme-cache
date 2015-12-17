<?php
/**
 * Plugin Name: Theme Cache
 * Plugin URI: https://github.com/ludwig-gramberg/wp-theme-cache
 * Description: WP Block Cache with Tags
 * Version: 0.1
 * Author: Ludwig Gramberg
 * Author URI: http://www.ludwig-gramberg.de/
 * Text Domain:
 * License: MIT
 */
class ThemeCache {

    public static function flush_fpc() {
        global $wpdb;
        if(self::has_fpc()) {
            $config = self::get_fpc_config();
            if(array_key_exists('folder', $config) && $config['folder'] != '') {
                $cacheFolder = ABSPATH.'/'.$config['folder'];
                if(is_dir($cacheFolder)) {
                    exec('rm '.$cacheFolder.'/*.html');
                }
            }
            $sql = '
              DELETE FROM `'.$wpdb->prefix.'theme_cache_fullpage`;
            ';
            $wpdb->query($sql);
        }
    }

    public static function init() {
        global $wpdb;

        $optionName = 'theme_cache_db_version';
        $dbVersion = (int)get_option($optionName);
        if($dbVersion == 0) {
            add_option($optionName, 0);
        }
        $v = $dbVersion;
        while(true) {
            $v++;
            if(method_exists('ThemeCache','init_db_'.$v)) {
                call_user_func('ThemeCache::init_db_'.$v, $wpdb);
                update_option($optionName, $v);
            } else {
                break;
            }
        }
    }

    public static function settings_init() {
        register_setting('theme_cache', 'theme_cache', array('ThemeCache','settings_process'));
    }

    public static function settings_menu() {
        add_options_page('Theme Cache', 'Theme Cache', 'manage_options', 'theme_cache', array('ThemeCache', 'settings_page'));
    }

    public static function settings_page() {

        $size_fpc = 0;
        if(self::has_fpc()) {
            $config = self::get_fpc_config();
            $dir = ABSPATH.'/'.$config['folder'];
            if(is_dir($dir)) {
                $rc = null; $ro = array();
                exec('du -b '.escapeshellarg($dir).' | tail -n 1', $ro, $rc);
                preg_match('/^([0-9]+)/', $ro[0], $m);
                $size_fpc = $m[1];
            }
        }

        ?>
        <div class="wrap">
            <h2>Theme Cache</h2>
            <form method="post" action="options.php">
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo __('Full Page Cache Size');?></th>
                            <td>
                                <?php
                                $unit = 'byte';
                                if($size_fpc > 1024) {
                                    $unit = 'Kib';
                                    $size_fpc /= 1024;
                                }
                                if($size_fpc > 1024) {
                                    $unit = 'Mib';
                                    $size_fpc /= 1024;
                                }
                                if($size_fpc > 1024) {
                                    $unit = 'Gib';
                                    $size_fpc /= 1024;
                                }
                                ?>
                                <?php echo number_format($size_fpc,1,',','.').' '.$unit ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php settings_fields( 'theme_cache' ); ?>
                <?php submit_button(__('Empty Full Page Cache'), 'delete', 'empty_fpc'); ?>
            </form>
        </div>
        <?php
    }

    public static function settings_process() {
        if(array_key_exists('empty_fpc', $_POST)) {
            do_action('theme_cache_flush_fpc');
        }
    }

    public static function has_fpc() {
        return file_exists(ABSPATH.'/tc-fullpage-config.php');
    }

    public static function get_fpc_config() {
        if(!self::has_fpc()) {
            return null;
        }
        /* @var $tc_fp_folder string */
        require ABSPATH.'/tc-fullpage-config.php';
        return array(
            'folder' => $tc_fp_folder
        );
    }

    protected static function init_db_1(wpdb $wpdb) {
        $wpdb->query('
            CREATE TABLE IF NOT EXISTS `'.$wpdb->prefix.'theme_cache_fullpage` (
              `file` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
              `request` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
              `created` datetime DEFAULT NULL,
              PRIMARY KEY (`file`),
              KEY `created` (`created`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
        ');
    }
}

add_action('theme_cache_flush_fpc', array('ThemeCache','flush_fpc'), 10, 0);
add_action('init', array('ThemeCache', 'init'));
add_action('admin_init', array('ThemeCache', 'settings_init'));
add_action('admin_menu', array('ThemeCache', 'settings_menu'));

register_activation_hook(__FILE__, array( 'ThemeCache', 'init'));
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

    public static function get($cacheKey, $return) {
        global $wpdb;
        $sql = $wpdb->prepare('
            SELECT `data`
            FROM `'.$wpdb->prefix.'theme_cache`
            WHERE `key` = %s;
        ', $cacheKey);
        foreach($wpdb->get_results($sql) as $result) {
            $return->data = $result->data;
            return;
        }
        $return->data = null;
    }

    public static function set($cacheKey, $data, array $tags = array()) {
        global $wpdb;

        // remove old entries

        $wpdb->query($wpdb->prepare('
            DELETE FROM `'.$wpdb->prefix.'theme_cache`
            WHERE `key`= %s
        ', $cacheKey));

        // insert new

        $sql = $wpdb->prepare('
            INSERT IGNORE INTO `'.$wpdb->prefix.'theme_cache`
            SET
                `key` = %s,
                `data` = %s
            ;
        ', $cacheKey, $data);
        $wpdb->query($sql);

        // add tags

        if(!empty($tags)) {
            $values = array();
            $sql = '
                INSERT IGNORE
                INTO `'.$wpdb->prefix.'theme_cache_tag`
                (`key`,`tag`) VALUES
            ';
            foreach($tags as $tag) {
                $sql .= "\n".'(%s, %s),';
                $values[] = $cacheKey;
                $values[] = $tag;
            }
            $sql = rtrim($sql,',');
            $wpdb->query($wpdb->prepare($sql, $values));
        }
    }

    public static function flush() {
        global $wpdb;
        $sql = '
            DELETE FROM `'.$wpdb->prefix.'theme_cache`;
        ';
        $wpdb->query($sql);
    }

    public static function invalidate($tag) {
        global $wpdb;
        $sql = $wpdb->prepare('
            DELETE FROM `'.$wpdb->prefix.'theme_cache`
            WHERE `key` IN (
                SELECT `key` FROM `'.$wpdb->prefix.'theme_cache_tag`
                WHERE `tag` = %s
            )
        ', $tag);
        $wpdb->query($sql);
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
        ?>
        <div class="wrap">
            <h2>Theme Cache</h2>
            <form method="post" action="options.php">
                <?php settings_fields( 'theme_cache' ); ?>
                <?php submit_button(__('Empty Cache'), 'delete', 'empty'); ?>
            </form>
        </div>
        <?php
    }

    public static function settings_process() {
        if(array_key_exists('empty', $_POST)) {
            do_action('theme_cache_flush');
        }
    }

    protected static function init_db_1(wpdb $wpdb) {
        $wpdb->query('
            CREATE TABLE IF NOT EXISTS `'.$wpdb->prefix.'theme_cache` (
              `key` varchar(40) COLLATE utf8_unicode_ci NOT NULL,
              `data` longtext COLLATE utf8_unicode_ci NOT NULL,
              PRIMARY KEY (`key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
        ');
        $wpdb->query('
            CREATE TABLE IF NOT EXISTS `'.$wpdb->prefix.'theme_cache_tag` (
              `key` varchar(40) COLLATE utf8_unicode_ci NOT NULL,
              `tag` varchar(60) COLLATE utf8_unicode_ci NOT NULL,
              PRIMARY KEY (`key`,`tag`),
              KEY `tag` (`tag`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
        ');
        $wpdb->query('
            ALTER TABLE `'.$wpdb->prefix.'theme_cache_tag`
            ADD CONSTRAINT `'.$wpdb->prefix.'theme_cache_tag_ibfk_1`
            FOREIGN KEY (`key`)
            REFERENCES `'.$wpdb->prefix.'theme_cache` (`key`)
            ON DELETE CASCADE ON UPDATE CASCADE;
        ');
    }
}

add_action('theme_cache_get', array('ThemeCache','get'), 10, 2);
add_action('theme_cache_set', array('ThemeCache','set'), 10, 3);
add_action('theme_cache_flush', array('ThemeCache','flush'), 10, 0);
add_action('theme_cache_invalidate', array('ThemeCache','invalidate'), 10, 1);
add_action('init', array('ThemeCache', 'init'));
add_action('admin_init', array('ThemeCache', 'settings_init'));
add_action('admin_menu', array('ThemeCache', 'settings_menu'));

register_activation_hook(__FILE__, array( 'ThemeCache', 'init'));
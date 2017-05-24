<?php
$tc_fp_hostname = array(
    'domain.com',
    'domain.de',
);
$tc_fp_mysql = array(
    'host' => '',
    'user' => '',
    'pass' => '',
    'name' => '',
    'table' => 'wp_theme_cache_fullpage',
);
$tc_fp_folder = 'wp-content/uploads/tcfpc/';
$tc_fp_prefix = $_SERVER['HTTPS'] ? 'https-' : 'http-';

$tc_fp_requests = array(
    '#^(/a-route-with-param/[a-z0-9\-_]+)*/$#i' => array(
        'some-param' => '//'
    ),
    '#^(/[a-z0-9\-_]+)*/$#i' => array(),
);

/**
 * @param null|string $pageType
 * @return array($cache, $tags)
 */
$tc_fp_cache = function($page_type = null) {

    $cache = true;
    $tags = array();

    // control which content to display
    $tags[] = true // some condition like if a user is logged in based on session
        ? 'TAG_VERSION_A'
        : 'TAG_VERSION_B';

    // default params to bypass cache
    if(array_key_exists('nc', $_GET) || array_key_exists('preview', $_GET)) {
        $cache = false;
    }

    // cache control by page type
    // page type must be first output in html from server
    // like <!--CACHE_PAGE_TYPE:NOCACHE-->

    switch($page_type) {
        case 'NOCACHE' :
            $cache = false;
            break;
        case 'SHOP' :
            // example: dont cache shop pages when the cart isnt empty
            if( array_key_exists('shop_cart', $_SESSION) && !empty($_SESSION['shop_cart'])) {
                $cache = false;
            }
            // example: dont cache versions of pages which are manipulation the cart or displaying the checkout
            if( array_key_exists('cart-add', $_GET) ||
                array_key_exists('cart-remove', $_GET) ||
                array_key_exists('checkout', $_GET)
            ) {
                $cache = false;
            }
            break;
    }

    return array($cache, $tags);
};
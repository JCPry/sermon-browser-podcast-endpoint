<?php
/**
 * Plugin Name: Sermon Browser Podcast Endpoint
 * Plugin URI: https://github.com/JCPry/sermon-browser-podcast-endpoint
 * Description: This allows you to create an endpoint of <code>/podcast/</code> for the Sermon Browser plugin
 * Version: 1.0
 * Author: Jeremy Pry
 * Author URI: http://jeremypry.com/
 * License: GPL2
 */

// Ensure that the Sermon Browser plugin is actually active
if ( ! defined( SB_INCLUDES_DIR ) ) {
    return;
}

// Register Activation & Deactivation hooks
register_activation_hook( __FILE__, 'jpry_sbpe_activate' );
register_deactivation_hook( __FILE__, 'jpry_sbpe_deactivate' );

// Remove the default sermon browser init function, so we can supply a modified version
add_action( 'plugins_loaded', 'jpry_sbpe_replace_init' );
/**
 * Replace the sb_sermon_init function with our own init function
 * 
 * @since 1.0
 */
function jpry_sbpe_replace_init() {
    remove_action( 'init', 'sb_sermon_init' );
    add_action( 'init', 'jpry_sbpe_init' );
	add_action( 'init', 'jpry_sbpe_add_endpoint' );
}

/**
 * Add the 'podcast' rewrite endpoint and then flush the rewrite rules
 * 
 * @since 1.0
 */
function jpry_sbpe_activate() {
    jpry_sbpe_add_endpoint();
    flush_rewrite_rules();
}

/**
 * Flush the rewrite rules to clean up after ourselves
 * 
 * @since 1.0
 */
function jpry_sbpe_deactivate() {
    flush_rewrite_rules();
}


function jpry_sbpe_add_endpoint() {
	add_rewrite_endpoint( 'podcast', EP_PERMALINK | EP_PAGES );
}

add_action( 'template_redirect', 'jpry_sbpe_template_redirect' );
/**
 * Handle the 
 * @global WP_Query $wp_query
 */
function jpry_sbpe_template_redirect() {
    global $wp_query;

    // Ensure this is a query we want to modify
    if ( ! isset( $wp_query->query_vars['podcast'] ) || ! is_singular() ) {
        return;
    }

    // Load the Podcast template file
    include( SB_INCLUDES_DIR . '/podcast.php' );
    exit;
}

/**
 * Custom init for the Sermon Browser plugin
 * 
 * @since 1.0
 * 
 * @see sb_sermon_init()
 * @global string $sermon_domain
 */
function jpry_sbpe_init() {
	global $sermon_domain;
    $sermon_domain = 'sermon-browser';
    if ( IS_MU ) {
        load_plugin_textdomain( $sermon_domain, '', 'sb-includes' );
    }
    else {
        load_plugin_textdomain( $sermon_domain, '', 'sermon-browser/sb-includes' );
    }
    if ( WPLANG != '' )
        setlocale( LC_ALL, WPLANG . '.UTF-8' );

    // Register custom CSS and javascript files
    wp_register_script( 'sb_64', SB_PLUGIN_URL . '/sb-includes/64.js', false, SB_CURRENT_VERSION );
    wp_register_script( 'sb_datepicker', SB_PLUGIN_URL . '/sb-includes/datePicker.js', array( 'jquery' ), SB_CURRENT_VERSION );
    wp_register_style( 'sb_datepicker', SB_PLUGIN_URL . '/sb-includes/datepicker.css', false, SB_CURRENT_VERSION );
    if ( get_option( 'permalink_structure' ) == '' )
        wp_register_style( 'sb_style', trailingslashit( site_url() ) . '?sb-style&', false, sb_get_option( 'style_date_modified' ) );
    else
        wp_register_style( 'sb_style', trailingslashit( site_url() ) . 'sb-style.css', false, sb_get_option( 'style_date_modified' ) );

    // Register [sermon] shortcode handler
    add_shortcode( 'sermons', 'sb_shortcode' );
    add_shortcode( 'sermon', 'sb_shortcode' );

    // Attempt to set php.ini directives
    if ( sb_return_kbytes( ini_get( 'upload_max_filesize' ) ) < 15360 )
        ini_set( 'upload_max_filesize', '15M' );
    if ( sb_return_kbytes( ini_get( 'post_max_size' ) ) < 15360 )
        ini_set( 'post_max_size', '15M' );
    if ( sb_return_kbytes( ini_get( 'memory_limit' ) ) < 49152 )
        ini_set( 'memory_limit', '48M' );
    if ( intval( ini_get( 'max_input_time' ) ) < 600 )
        ini_set( 'max_input_time', '600' );
    if ( intval( ini_get( 'max_execution_time' ) ) < 600 )
        ini_set( 'max_execution_time', '600' );
    if ( ini_get( 'file_uploads' ) <> '1' )
        ini_set( 'file_uploads', '1' );

    // Check whether upgrade required
    if ( current_user_can( 'manage_options' ) && is_admin() ) {
        if ( get_option( 'sb_sermon_db_version' ) )
            $db_version = get_option( 'sb_sermon_db_version' );
        else
            $db_version = sb_get_option( 'db_version' );
        if ( $db_version && $db_version != SB_DATABASE_VERSION ) {
            require_once (SB_INCLUDES_DIR . '/upgrade.php');
            sb_database_upgrade( $db_version );
        }
        elseif ( !$db_version ) {
            require (SB_INCLUDES_DIR . '/sb-install.php');
            sb_install();
        }
        $sb_version = sb_get_option( 'code_version' );
        if ( $sb_version != SB_CURRENT_VERSION ) {
            require_once (SB_INCLUDES_DIR . '/upgrade.php');
            sb_version_upgrade( $sb_version, SB_CURRENT_VERSION );
        }
    }

    // Load shared (admin/frontend) features
    add_action( 'save_post', 'update_podcast_url' );

    // Check to see what functions are required, and only load what is needed
    if ( stripos( $_SERVER['REQUEST_URI'], '/wp-admin/' ) === FALSE ) {
        require (SB_INCLUDES_DIR . '/frontend.php');
        add_action( 'wp_head', 'sb_add_headers', 0 );
        add_action( 'wp_head', 'wp_print_styles', 9 );
        add_action( 'admin_bar_menu', 'sb_admin_bar_menu', 45 );
        add_filter( 'wp_title', 'sb_page_title' );
        if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES )
            add_action( 'wp_footer', 'sb_footer_stats' );
    } else {
        require (SB_INCLUDES_DIR . '/admin.php');
        add_action( 'admin_menu', 'sb_add_pages' );
        add_action( 'rightnow_end', 'sb_rightnow' );
        add_action( 'admin_init', 'sb_add_admin_headers' );
        add_filter( 'contextual_help', 'sb_add_contextual_help' );
        if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES )
            add_action( 'admin_footer', 'sb_footer_stats' );
    }
}


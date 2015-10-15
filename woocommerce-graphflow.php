<?php
/*
 	Plugin Name: Predictive Marketing for WooCommerce
 	Plugin URI: http://www.woothemes.com/products/woocommerce-recommendations/
	Description: Recommendations, follow-up email, coupons, customer insights, analytics dashboard, conversion tracking and much more!
	Author: Graphflow
	Author URI: http://graphflow.com/
	Version: 3.0.3
	Requires at least: 3.9
	Tested up to: 4.3
	Developer: Graphflow
	Developer URI: http://graphflow.com/

 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Required functions
 */
if ( ! function_exists( 'woothemes_queue_update' ) ) {
	require_once( 'woo-includes/woo-functions.php' );
}

// Check if WooCommerce is active
if ( ! is_woocommerce_active() )
	return;

// get active plugins
$active_plugins = (array) get_option( 'active_plugins', array() );
if ( is_multisite() ) {
	$active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );
}

// if old graphflow plugin is installed and active, deactivate it
if ( in_array( 'woocommerce-recommendations-by-graphflow/woocommerce-graphflow.php', $active_plugins ) || array_key_exists( 'woocommerce-recommendations-by-graphflow/woocommerce-graphflow.php', $active_plugins ) ) {
	require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	deactivate_plugins( 'woocommerce-recommendations-by-graphflow/woocommerce-graphflow.php' );
}

if ( ! function_exists( 'gf_activation_hook_2' ) ) {
	function gf_activation_hook_2() {
		update_option( 'woocommerce_graphflow_install_notice', false );
		update_option( 'woocommerce_graphflow_plugin_version', '3.0.3' );
	}
}

register_activation_hook( __FILE__, 'gf_activation_hook_2' );

require_once( 'includes/class-wc-graphflow.php' );
$GLOBALS['wc_graphflow'] = new WC_Graphflow( __FILE__ );
<?php

if ( is_admin() ) {
	// Add form handler for data export
	add_action( 'init', 'graphflow_admin_form_handler' );
	add_action( 'admin_menu', 'graphflow_admin_add_menu' );
	add_action( 'admin_init', 'graphflow_admin_settings_init' );
	add_action( 'admin_enqueue_scripts', 'graphflow_admin_enqueue_scripts' );
	// Show notices for data export
	add_action( 'admin_notices', 'graphflow_admin_export_notices' );
	// Show notice on first install
	add_action( 'admin_notices', 'graphflow_install_notice', 10 );
}

function graphflow_get_timestamp_utc( $time = 'now' ) {
	$date_time = new DateTime( $time );
	$date_time->setTimezone( new DateTimeZone('UTC') );
	$timestamp_utc = $date_time->getTimestamp();
	return $timestamp_utc;
}

function graphflow_admin_enqueue_scripts() {
	wp_register_style( 'gfadmin-css', plugins_url( '/assets/css/gfadmin.css' , dirname(__FILE__) ) );
	wp_enqueue_style( 'gfadmin-css' );
	wp_register_script( 'bootstrap-tooltip',  plugins_url( 'assets/js/bootstrap-tooltip.js' , dirname(__FILE__) ), array( 'jquery' ), '1.0', true );
	wp_enqueue_script( 'bootstrap-tooltip' );
	wp_register_script( 'gfadmin',  plugins_url( 'assets/js/gfadmin.js' , dirname(__FILE__) ), array( 'jquery' ), '1.0', true );
	wp_enqueue_script( 'gfadmin' );
}

/**
 * Show install message when plugin is activated
 * @return void
 */
function graphflow_install_notice() {
	if ( get_option( 'woocommerce_graphflow_install_notice' ) == false ) {
		$admin_url = admin_url();
		echo '<div id="gf-message" class="update-nag"><p><strong>' .__( "Thanks for activating Graphflow recommendations! Please head over to the <a href='" . $admin_url . "admin.php?page=graphflow-admin'>settings page</a> to configure the plugin.", 'wp-graphflow' ) . '</strong></p></div>';
		update_option( 'woocommerce_graphflow_install_notice', true );
	}
}

function graphflow_is_plugin_page() {
	return is_admin()
	       && current_user_can('manage_options')
	       && isset( $_REQUEST['page'] )
	       && ( $_REQUEST['page'] == 'graphflow-admin' || $_REQUEST['page'] == 'graphflow-admin-email' )
		? true : false;
}

/**
 * Listen for form request to export products
 * @return void
 */
function graphflow_admin_form_handler() {
	if ( !graphflow_is_plugin_page() ) {
		return;
	}

	// check auth if settings updated or export buttons clicked
	if ( isset( $_REQUEST['settings-updated'] ) ) {
		$auth = $GLOBALS['wc_graphflow']->get_api()->test_auth();
		if ( !$auth ) {
			add_action( 'admin_notices', 'graphflow_admin_notices_auth_error' );
			return;
		} else {
			add_action( 'admin_notices', 'graphflow_admin_notices_auth_ok' );
			return;
		}
	} else {
		$auth = $GLOBALS['wc_graphflow']->get_api()->test_auth();
		if ( !$auth ) {
			add_action( 'admin_notices', 'graphflow_admin_notices_auth_error' );
			return;
		}
	}

	// check auth if export buttons clicked
	if ( isset( $_REQUEST['gf_export'] ) ) {
		$auth = $GLOBALS['wc_graphflow']->get_api()->test_auth();
		if ( !$auth ) {
			add_action( 'admin_notices', 'graphflow_admin_notices_auth_error' );
			return;
		}
	}

	// handle export button clicks
	if ( isset( $_REQUEST['gf_export'] ) && 'products' == $_REQUEST['gf_export'] ) {
		$total_exported = $GLOBALS['wc_graphflow']->capture_all_products();
		$http_query = remove_query_arg( 'gf_export' );
		wp_redirect( esc_url_raw( add_query_arg( array( 'gf_exported' => 'products', 'gf_exported_total' => $total_exported ), $http_query ) ) );
	} else if ( isset( $_REQUEST['gf_export'] ) && 'orders' == $_REQUEST['gf_export'] ) {
		$total_exported = $GLOBALS['wc_graphflow']->capture_all_orders();
		$http_query = remove_query_arg( 'gf_export' );
		wp_redirect( esc_url_raw( add_query_arg( array( 'gf_exported' => 'orders', 'gf_exported_total' => $total_exported ), $http_query ) ) );
	} else if ( isset( $_REQUEST['gf_export'] ) && 'posts' == $_REQUEST['gf_export'] ) {
		$total_exported = $GLOBALS['wc_graphflow']->capture_all_posts();
		$http_query = remove_query_arg( 'gf_export' );
		wp_redirect( esc_url_raw( add_query_arg( array( 'gf_exported' => 'posts', 'gf_exported_total' => $total_exported ), $http_query ) ) );
	}
}

/**
 * Display admin notices related to product, order and post export
 */
function graphflow_admin_export_notices() {
	if ( !graphflow_is_plugin_page() || isset( $_REQUEST['settings-updated'] ) ) {
		return;
	}

	if ( isset( $_REQUEST['gf_exported'] ) && 'products' == $_REQUEST['gf_exported'] ) {
		$total_exported = 0;
		$total = graphflow_count_posts( 'product', 'publish' );
		if ( isset( $_REQUEST['gf_exported_total'] ) ) {
			$total_exported = $_REQUEST['gf_exported_total'];
		}
		echo '<div id="message" class="updated notice notice-success is-dismissible below-h2"><p><strong>' .__( 'Exported ' . $total_exported . ' products to Graphflow of total ' . $total . '.', 'wp-graphflow' ) . '</strong></p></div>';
	} else if ( isset( $_REQUEST['gf_exported'] ) && 'orders' == $_REQUEST['gf_exported'] ) {
		$total_exported = 0;
		$total = graphflow_count_posts( 'shop_order', array( 'wc-completed', 'wc-pending', 'wc-on-hold', 'wc-processing') );
		if ( isset( $_REQUEST['gf_exported_total'] ) ) {
			$total_exported = $_REQUEST['gf_exported_total'];
		}
		echo '<div id="message" class="updated notice notice-success is-dismissible below-h2"><p><strong>' .__( 'Exported ' . $total_exported . ' orders to Graphflow of total ' . $total . '.', 'wp-graphflow' ) . '</strong></p></div>';
	} else if ( isset( $_REQUEST['gf_exported'] ) && 'posts' == $_REQUEST['gf_exported'] ) {
		$total_exported = 0;
		$total = graphflow_count_posts( 'post', 'publish' );
		if ( isset( $_REQUEST['gf_exported_total'] ) ) {
			$total_exported = $_REQUEST['gf_exported_total'];
		}
		echo '<div id="message" class="updated notice notice-success is-dismissible below-h2"><p><strong>' .__( 'Exported ' . $total_exported . ' posts to Graphflow of total ' . $total . '.', 'wp-graphflow' ) . '</strong></p></div>';
	}
}

function graphflow_admin_notices_auth_error() {
	echo '<div id="message " class="error notice notice-success is-dismissible below-h2"><p><strong>' .__( 'Authorization for Graphflow API failed! Please check your access credentials and try again.', 'wp-graphflow' ) . '</strong></p></div>';
}

function graphflow_admin_notices_auth_ok() {
	echo '<div id="message" class="updated notice notice-success is-dismissible below-h2"><p><strong>' .__( 'Your Graphflow access keys have been verified. Happy recommending!', 'wp-graphflow' ) . '</strong></p></div>';
}

/**
 * Count the number of orders of status 'wc-completed', 'wc-pending', 'wc-on-hold' and 'wc-processing'
 * @return int
 */
function graphflow_count_posts( $type, $vars ) {
	$total_orders = wp_count_posts( $type );
	$total = 0;
	if ( !is_array( $vars ) ){
		$vars = array( $vars );
	}
	foreach ( $vars as $var ) {
		$total += $total_orders->$var;
	}
	return $total;
}

function graphflow_admin_add_menu() {
	add_menu_page( 'Graphflow Settings', 'Graphflow', 'manage_options', 'graphflow-admin', 'graphflow_admin_page_main' );
	add_submenu_page('graphflow-admin', 'Graphflow Settings', 'Settings', 'manage_options', 'graphflow-admin' );
}

function graphflow_register_general_settings() {
	// old Graphflow plugin settings
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_client_key' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_api_key' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_export_products' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_export_orders' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_max_order_export' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_force_order_export' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_user_roles_track' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_use_ajax' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_show_on_product' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_product_rec_title' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_product_rec_num' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_product_rec_col' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_product_rec_restrict_category' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_show_on_cart' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_cart_rec_title' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_cart_rec_num' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_cart_rec_col' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_show_on_shop' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_user_rec_title' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_user_rec_num' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_user_rec_col' );
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_shortcode_rec_title' );

	// new settings
	register_setting( 'graphflow_plugin_options', 'woocommerce_graphflow_show_on_cart_location' );
}

function graphflow_render_help_tip( $text, $extra_class = '' ) {
	if ( empty( $text) ) return;
	?>
	<span class='dashicons dashicons-editor-help <?php if ( !empty( $extra_class ) )
		echo $extra_class; ?>' title='<?php echo $text; ?>' xmlns="http://www.w3.org/1999/html"></span>
	<?php
}

function graphflow_admin_settings_init() {
	graphflow_register_general_settings();

	// === Main Admin Page === //

	// General Settings Section
	add_settings_section(
		'graphflow_general_options', 
		__( 'General', 'wp-graphflow' ), 
		'graphflow_general_options_callback', 
		'graphflow-admin'
	);

	$account_text = "You can find this on your Graphflow <a href='https://app.graphflow.com/dashboard/account' target='_blank'>Account Page</a>.";
	// Client Key
	add_settings_field( 
		'woocommerce_graphflow_client_key',
		__( 'Client Key', 'wp-graphflow' ),
		'graphflow_text_field_render', 
		'graphflow-admin',
		'graphflow_general_options',
		array(
			'id'        => 'woocommerce_graphflow_client_key',
			'label_for' => 'woocommerce_graphflow_client_key',
			'css' 		=> 'min-width:400px;',
			'desc'		=> __( '<i>Your Client Key. ' . $account_text . '</i>', 'wp-graphflow' )
		)
	);

	// API Key
	add_settings_field( 
		'woocommerce_graphflow_api_key',
		__( 'API Key', 'wp-graphflow' ), 
		'graphflow_text_field_render', 
		'graphflow-admin',
		'graphflow_general_options',
		array(
			'id'        => 'woocommerce_graphflow_api_key',
			'label_for' => 'woocommerce_graphflow_api_key',
			'css' 		=> 'min-width:400px;',
			'desc'		=> __( '<i>Your API Key. ' . $account_text . '</i>', 'wp-graphflow' )
		)
	);

	// Export Products
	add_settings_field( 
		'woocommerce_graphflow_export_products',
		__( 'Export Products', 'wp-graphflow' ),
		'graphflow_export_button_render', 
		'graphflow-admin',
		'graphflow_general_options',
		array(
			'id'        => 'woocommerce_graphflow_export_products',
			'label_for' => 'woocommerce_graphflow_export_products',
			'tip'		=> esc_html( __( 'Click this button to export product details to Graphflow (<i>recommended when you first install the plugin</i>)', 'wp-graphflow' ) )
		)
	);

	// Export Orders
	add_settings_field( 
		'woocommerce_graphflow_export_orders',
		__( 'Export Orders', 'wp-graphflow' ), 
		'graphflow_export_button_render', 
		'graphflow-admin',
		'graphflow_general_options',
		array(
			'id'        => 'woocommerce_graphflow_export_orders',
			'label_for' => 'woocommerce_graphflow_export_orders',
			'tip'		=> esc_html( __( 'Click this button to export product details to Graphflow (<i>recommended when you first install the plugin</i>)', 'wp-graphflow' ) )
		)
	);

	// Export Posts
	/*
	add_settings_field( 
		'graphflow_export_posts', 
		__( 'Export Posts', 'wp-graphflow' ), 
		'graphflow_export_button_render', 
		'graphflow-admin',
		'graphflow_general_options',
		array(
			'id'        => 'graphflow_export_posts',
			'label_for' => 'graphflow_export_posts',
			'desc'		=> __( 'Export blog post details to Graphflow (recommended when you first install the plugin)', 'wp-graphflow' )
		)
	);
	*/

	// Max Orders to Export
	add_settings_field( 
		'woocommerce_graphflow_max_order_export',
		__( 'Max Orders to Export', 'wp-graphflow' ), 
		'graphflow_number_field_render', 
		'graphflow-admin',
		'graphflow_general_options',
		array(
			'id'        => 'woocommerce_graphflow_max_order_export',
			'label_for' => 'woocommerce_graphflow_max_order_export',
			'tip'		=> __( 'Maximum number of orders to export. Set higher to export more of your historical orders', 'wp-graphflow' ),
			'css' 		=> 'max-width:100px;',
			'default'   => 1000
		)
	);

	// Force Order Export
	add_settings_field( 
		'woocommerce_graphflow_force_order_export',
		__( 'Force Order Export', 'wp-graphflow' ),
		'graphflow_checkbox_field_render', 
		'graphflow-admin',
		'graphflow_general_options',
		array( 
			'id'        => 'woocommerce_graphflow_force_order_export',
			'label_for' => 'woocommerce_graphflow_force_order_export',
			'tip'       => __( 'Check this box to force export of previously exported orders', 'wp-graphflow' ),
			'default'   => 'no'
		)
	);

	// User Roles to Track
	add_settings_field( 
		'woocommerce_graphflow_user_roles_track',
		__( 'User Roles to Track', 'wp-graphflow' ), 
		'graphflow_user_roles_track_render', 
		'graphflow-admin',
		'graphflow_general_options',
		array(
			'id'        => 'woocommerce_graphflow_user_roles_track',
			'label_for' => 'woocommerce_graphflow_user_roles_track',
			'default'   => array( 'subscriber', 'customer' ),
			'tip'       => esc_html( __( 'Select the user roles for event tracking to be enabled (e.g. product views, add-to-cart, purchases, etc). By default only events from users with <i>Customer</i> or <i>Subscriber</i> roles are tracked.', 'wp-graphflow' ) )
		)
	);

	// Use AJAX Recommendations
	add_settings_field( 
		'woocommerce_graphflow_use_ajax',
		__( 'Use AJAX Recommendations', 'wp-graphflow' ), 
		'graphflow_checkbox_field_render', 
		'graphflow-admin',
		'graphflow_general_options',
		array(
			'id'         => 'woocommerce_graphflow_use_ajax',
			'label_for'  => 'woocommerce_graphflow_use_ajax',
			'default'    => 'yes',
			'tip'        => esc_html( __( 'Check this box to show recommendations using AJAX. This is friendly for cached environments, and also does not impact your page load times. Uncheck to use non-AJAX recommendations (<i>Not advised for cached sites</i>).', 'wp-graphflow' ) )
		)
	);

	// === Recommendation Settings Sections === //

	// Similar Products Settings Section
	add_settings_section(
		'graphflow_similar_options',
		__( 'Similar Products', 'wp-graphflow' ),
		'graphflow_similar_options_callback',
		'graphflow-admin'
	);

	// Show similar products
	add_settings_field(
		'woocommerce_graphflow_show_on_product',
		__( 'Show Similar Products', 'wp-graphflow' ),
		'graphflow_checkbox_field_render',
		'graphflow-admin',
		'graphflow_similar_options',
		array(
			'id'        => 'woocommerce_graphflow_show_on_product',
			'label_for' => 'woocommerce_graphflow_show_on_product',
			'default'  => 'yes'
		)
	);

	// Similar product title
	add_settings_field(
		'woocommerce_graphflow_product_rec_title',
		__( 'Title', 'wp-graphflow' ),
		'graphflow_text_field_render',
		'graphflow-admin',
		'graphflow_similar_options',
		array(
			'id'        => 'woocommerce_graphflow_product_rec_title',
			'label_for' => 'woocommerce_graphflow_product_rec_title',
			'default'	=> 'You may also like',
			'tip'       => __( 'Title text to display for similar product recommendations', 'wp-graphflow' )
		)
	);

	// Number recs
	add_settings_field(
		'woocommerce_graphflow_product_rec_num',
		__( 'Number', 'wp-graphflow' ),
		'graphflow_number_field_render',
		'graphflow-admin',
		'graphflow_similar_options',
		array(
			'id'        => 'woocommerce_graphflow_product_rec_num',
			'label_for' => 'woocommerce_graphflow_product_rec_num',
			'min'       => 1,
			'max'       => 12,
			'default'   => 4,
			'css' 		=> 'max-width:50px;',
			'tip'       => __( 'Number of recommendations to display', 'wp-graphflow' )
		)
	);

	// Number rec cols
	add_settings_field(
		'woocommerce_graphflow_product_rec_col',
		__( 'Columns', 'wp-graphflow' ),
		'graphflow_number_field_render',
		'graphflow-admin',
		'graphflow_similar_options',
		array(
			'id'        => 'woocommerce_graphflow_product_rec_col',
			'label_for' => 'woocommerce_graphflow_product_rec_col',
			'min'       => 1,
			'max'       => 12,
			'default'   => 4,
			'css' 		=> 'max-width:50px;',
			'tip'       => __( 'Number of columns to display', 'wp-graphflow' )
		)
	);

	// Restrict category
	add_settings_field(
		'woocommerce_graphflow_product_rec_restrict_category',
		__( 'Restrict Category', 'wp-graphflow' ),
		'graphflow_checkbox_field_render',
		'graphflow-admin',
		'graphflow_similar_options',
		array(
			'id'        => 'woocommerce_graphflow_product_rec_restrict_category',
			'label_for' => 'woocommerce_graphflow_product_rec_restrict_category',
			'default'   => 'yes',
			'tip'       => __( 'Check this box to restrict similar product recommendations to the same category as the current product', 'wp-graphflow' )
		)
	);

	// Cart Recommendations Settings Section
	add_settings_section(
		'graphflow_cart_options',
		__( 'Cart Recommendations', 'wp-graphflow' ),
		'graphflow_cart_options_callback',
		'graphflow-admin'
	);

	// Show cart recommendations
	add_settings_field(
		'woocommerce_graphflow_show_on_cart',
		__( 'Show Cart Recommendations', 'wp-graphflow' ),
		'graphflow_checkbox_field_render',
		'graphflow-admin',
		'graphflow_cart_options',
		array(
			'id'        => 'woocommerce_graphflow_show_on_cart',
			'label_for' => 'woocommerce_graphflow_show_on_cart',
			'default'   => 'yes'
		)
	);

	// Cart recommendation location
	add_settings_field(
		'woocommerce_graphflow_show_on_cart_location',
		__( 'Location', 'wp-graphflow' ),
		'graphflow_select_field_render',
		'graphflow-admin',
		'graphflow_cart_options',
		array(
			'id'        => 'woocommerce_graphflow_show_on_cart_location',
			'label_for' => 'woocommerce_graphflow_show_on_cart_location',
			'default'   => 'after_cart',
			'options'   => array( 'after_cart' => 'After Cart', 'cart_collaterals' => 'Cart Collaterals'),
			'tip'       => __( 'Cart recommendations can either be shown below the cart section, or within the cart collaterals section (next to the cart totals)', 'wp-graphflow' )
		)
	);

	// Cart rec title
	add_settings_field(
		'woocommerce_graphflow_cart_rec_title',
		__( 'Title', 'wp-graphflow' ),
		'graphflow_text_field_render',
		'graphflow-admin',
		'graphflow_cart_options',
		array(
			'id'        => 'woocommerce_graphflow_cart_rec_title',
			'label_for' => 'woocommerce_graphflow_cart_rec_title',
			'default'	=> 'Consider adding these to your order',
			'css' 		=> 'min-width:400px;',
			'tip'       => __( 'Title text to display for cart recommendations', 'wp-graphflow' )
		)
	);

	// Cart rec num
	add_settings_field(
		'woocommerce_graphflow_cart_rec_num',
		__( 'Number', 'wp-graphflow' ),
		'graphflow_number_field_render',
		'graphflow-admin',
		'graphflow_cart_options',
		array(
			'id'        => 'woocommerce_graphflow_cart_rec_num',
			'label_for' => 'woocommerce_graphflow_cart_rec_num',
			'min'       => 1,
			'max'       => 12,
			'default'   => 4,
			'css' 		=> 'max-width:50px;',
			'tip'       => __( 'Number of recommendations to display', 'wp-graphflow' )
		)
	);

	// Cart rec cols
	add_settings_field(
		'woocommerce_graphflow_cart_rec_col',
		__( 'Columns', 'wp-graphflow' ),
		'graphflow_number_field_render',
		'graphflow-admin',
		'graphflow_cart_options',
		array(
			'id'        => 'woocommerce_graphflow_cart_rec_col',
			'label_for' => 'woocommerce_graphflow_cart_rec_col',
			'min'       => 1,
			'max'       => 12,
			'default'   => 4,
			'css' 		=> 'max-width:50px;',
			'tip'       => __( 'Number of columns to display', 'wp-graphflow' )
		)
	);

	// User / Shop Recommendations Settings Section
	add_settings_section(
		'graphflow_user_rec_options',
		__( 'User Recommendations', 'wp-graphflow' ),
		'graphflow_user_rec_options_callback',
		'graphflow-admin'
	);

	// Show user recommendations
	add_settings_field(
		'woocommerce_graphflow_show_on_shop',
		__( 'Show User Recommendations', 'wp-graphflow' ),
		'graphflow_checkbox_field_render',
		'graphflow-admin',
		'graphflow_user_rec_options',
		array(
			'id'        => 'woocommerce_graphflow_show_on_shop',
			'label_for' => 'woocommerce_graphflow_show_on_shop',
			'default'   => 'yes'
		)
	);

	// User rec title
	add_settings_field(
		'woocommerce_graphflow_user_rec_title',
		__( 'Title', 'wp-graphflow' ),
		'graphflow_text_field_render',
		'graphflow-admin',
		'graphflow_user_rec_options',
		array(
			'id'        => 'woocommerce_graphflow_user_rec_title',
			'label_for' => 'woocommerce_graphflow_user_rec_title',
			'css' 		=> 'min-width:400px;',
			'tip'       => __( 'Title text to display for user recommendations on Shop and Category pages', 'wp-graphflow' ),
			'default'	=> 'Recommended for you'
		)
	);

	// User rec num
	add_settings_field(
		'woocommerce_graphflow_user_rec_num',
		__( 'Number', 'wp-graphflow' ),
		'graphflow_number_field_render',
		'graphflow-admin',
		'graphflow_user_rec_options',
		array(
			'id'        => 'woocommerce_graphflow_user_rec_num',
			'label_for' => 'woocommerce_graphflow_user_rec_num',
			'min'       => 1,
			'max'       => 12,
			'default'   => 4,
			'css' 		=> 'max-width:50px;',
			'tip'       => __( 'Number of recommendations to display', 'wp-graphflow' )
		)
	);

	// User rec cols
	add_settings_field(
		'woocommerce_graphflow_user_rec_col',
		__( 'Columns', 'wp-graphflow' ),
		'graphflow_number_field_render',
		'graphflow-admin',
		'graphflow_user_rec_options',
		array(
			'id'        => 'woocommerce_graphflow_user_rec_col',
			'label_for' => 'woocommerce_graphflow_user_rec_col',
			'min'       => 1,
			'max'       => 12,
			'default'   => 4,
			'css' 		=> 'max-width:50px;',
			'tip'       => __( 'Number of columns to display', 'wp-graphflow' )
		)
	);

	// Recommendations Shortcode Settings Section
	add_settings_section(
		'graphflow_shortcode_options',
		__( 'Recommendations Shortcode', 'wp-graphflow' ),
		'graphflow_shortcode_options_callback',
		'graphflow-admin'
	);

	// Shortcode default title
	add_settings_field(
		'woocommerce_graphflow_shortcode_rec_title',
		__( 'Default Title', 'wp-graphflow' ),
		'graphflow_text_field_render',
		'graphflow-admin',
		'graphflow_shortcode_options',
		array(
			'id'        => 'woocommerce_graphflow_shortcode_rec_title',
			'label_for' => 'woocommerce_graphflow_shortcode_rec_title',
			'default'	=> 'Recommended for you',
			'css' 		=> 'min-width:400px;',
			'tip'       => __( 'Default title text to display when using the shortcode.', 'wp-graphflow' ),
		)
	);
}

function graphflow_general_options_callback() {
	$notice_text = '';
	if ( get_option('woocommerce_graphflow_client_key') == '' or get_option('woocommerce_graphflow_api_key') == '' ) {
		$notice_text = "<div class='error fade'>We see that you don't have your Graphflow keys yet. Please <strong><a href='https://app.graphflow.com/accounts/signup' target='_blank'>sign up</a></strong> for your FREE Graphflow account to get your keys!</div>";
	}
	echo __( $notice_text, 'wp-graphflow' );
}

function graphflow_similar_options_callback() {
	echo __( 'Graphflow similar product recommendations are shown on product pages. They replace the built-in WooCommerce related products.', 'wp-graphflow' );
}

function graphflow_cart_options_callback() {
	echo __( 'Graphflow cart recommendations are shown on the cart page, based on the products in the cart and the current user.', 'wp-graphflow' );
}

function graphflow_user_rec_options_callback() {
	echo __( 'Graphflow user recommendations are shown on your main Shop page, as well as Product Category pages. On Category pages, the recommendations are filtered by the relevant Category.<p>Recommendations are personalized to the current user if we have enough user history for them. If not, we base the recommendations on the popularity of your products.</p>', 'wp-graphflow' );
}

function graphflow_shortcode_options_callback() {
	echo 'You can show recommendations anywhere using the shortcode <code>[graphflow_recommendations]</code>. You can customize the number of products by using the <code>per_page</code> setting: <code>[graphflow_recommendations per_page="6"]</code>. You can customize the title using the <code>title</code> setting: <code>[graphflow_recommendations title="Your custom title"]</code>.';
	echo '<p>For more advanced usage see the <a href="http://docs.woothemes.com/document/woocommerce-graphflow/#section-4" target="_blank">Documentation</a>.</p>';
}

function graphflow_text_field_render( $args ) { 

	$id = $args['id'];
	$option_value = get_option( $args['id'], isset( $args['default'] ) ? $args['default'] : '' );
	graphflow_render_help_tip( isset( $args['tip'] ) ? $args['tip'] : '' );
	?>
	<input id='<?php echo $id; ?>'
	       type='text'
	       style='<?php echo esc_attr( $args['css'] ); ?>'
	       name='<?php echo $id; ?>'
	       value='<?php echo $option_value; ?>'>
	<label for='<?php echo $id; ?>'><?php echo isset( $args['desc'] ) ? $args['desc'] : ''; ?></label>
	<?php
}

function graphflow_textarea_field_render( $args ) {
	$id = $args['id'];
	$option_value = get_option( $args['id'], $args['default'] );
	graphflow_render_help_tip( isset( $args['tip'] ) ? $args['tip'] : '' );
	?>
	<textarea id='<?php echo $id; ?>'
	          cols='<?php echo $args['cols']; ?>'
	          rows='<?php echo $args['rows']; ?>'
	          name='<?php echo $id; ?>'><?php echo $option_value; ?>
	</textarea>
	<?php
}

function graphflow_number_field_render( $args ) {
	$id = $args['id'];
	$option_value = get_option( $args['id'], $args['default'] );
	$min = isset( $args['min'] ) ? $args['min'] : 0;
	$max = isset( $args['max'] ) ? $args['max'] : 10000;
	graphflow_render_help_tip( isset( $args['tip'] ) ? $args['tip'] : '' );
	?>
	<input id='<?php echo $id; ?>'
	       type='number'
	       style='<?php echo esc_attr( $args['css'] ); ?>'
	       min='<?php echo esc_attr( $min ); ?>'
	       max='<?php echo esc_attr( $max ); ?>'
	       name='<?php echo $id; ?>' value='<?php echo $option_value; ?>'>
	<?php
	if ( !empty( $args['after'] ) ) {
		echo $args['after'];
	}
}

function graphflow_user_roles_track_render( $args ) {
	global $wp_roles;
	$args['options'] = $wp_roles->get_names();
	graphflow_multiselect_field_render ( $args );
}


function graphflow_multiselect_field_render( $args ) {
	$id = $args['id'];
	$option_value = get_option( $args['id'], $args['default'] );
	graphflow_render_help_tip( isset( $args['tip'] ) ? $args['tip'] : '' );
	?>
	<select id='<?php echo $id; ?>'
	        style='<?php echo esc_attr( $args['css'] ); ?>'
	        multiple='multiple'
	        name='<?php echo $id . '[]'; ?>'>
	<?php foreach ( $args['options'] as $slug => $name) {
		echo "<option value='" . $slug . "'";
		if ( in_array( $slug, $option_value ) )
			echo "selected='selected'";
		echo ">" . $name . "</option>";
	}
	?>
	</select>
	<?php
}

function graphflow_select_field_render( $args ) {
	$id = $args['id'];
	$option_value = get_option( $args['id'], $args['default'] );
	graphflow_render_help_tip( isset( $args['tip'] ) ? $args['tip'] : '' );
	?>
	<select id='<?php echo $id; ?>'
	        style='<?php echo esc_attr( $args['css'] ); ?>'
	        name='<?php echo $id; ?>'>
		<?php foreach ( $args['options'] as $slug => $name ) {
			echo "<option value='" . $slug . "'";
			if ( $slug == $option_value ) {
				echo "selected='selected'";
			}
			echo ">" . $name . "</option>";
		}
		?>
	</select>
	<?php
}

function graphflow_coupon_field_render( $args ) {
	$id = $args['id'];
	$options = get_option( $args['id'], $args['default'] );
	$coupon_title = isset( $options['coupon_title'] ) ? $options['coupon_title'] : '';
	$coupon_text = isset( $options['coupon_text'] ) ? $options['coupon_text'] : '';
	$coupon_amount = isset( $options['coupon_amount'] ) ? $options['coupon_amount'] : '';
	$expiry_date = isset( $options['expiry_date'] ) ? $options['expiry_date'] : '';
	$minimum_amount = isset( $options['minimum_amount'] ) ? $options['minimum_amount'] : '';
	$maximum_amount = isset( $options['maximum_amount'] ) ? $options['maximum_amount'] : '';
	graphflow_render_help_tip( isset( $args['tip'] ) ? $args['tip'] : '' );
	?>
	<table>
		<tr>
			<td style="padding: 0px;" width="40%;">Coupon type:</td>
			<td style="padding: 0px;" width="60%">
				<select id='<?php echo $id . '[coupon_type]'; ?>'
				        style='<?php echo esc_attr( $args['css'] ); ?>'
				        name='<?php echo $id . '[coupon_type]'; ?>'>
					<?php foreach ( $args['options'] as $slug => $name) {
						echo "<option value='" . $slug . "'";
						if ( $slug == $options['coupon_type'] )
							echo "selected='selected'";
						echo ">" . $name . "</option>";
					}
					?>
				</select>
			</td>
		</tr>
		<tr>
			<td style="padding: 0px;" width="40%;">Coupon title:</td>
			<td style="padding: 0px;" width="60%">
				<textarea id='<?php echo $id . '[coupon_title]'; ?>'
				          cols='42'
				          rows='1'
				          name='<?php echo $id . '[coupon_title]'; ?>'><?php echo $coupon_title; ?>
				</textarea>
			</td>
		</tr>
		<tr>
			<td style="padding: 0px;" width="40%;">Coupon text:</td>
			<td style="padding: 0px;" width="60%">
				<textarea id='<?php echo $id . '[coupon_text]'; ?>'
				          cols='42'
				          rows='1'
				          name='<?php echo $id . '[coupon_text]'; ?>'><?php echo $coupon_text; ?>
				</textarea>
			</td>
		</tr>
		<tr>
			<td style="padding: 0px;" width="40%;">Amount:</td>
			<td style="padding: 0px;" width="60%;">
				<input id='<?php echo $id . '[coupon_amount]'; ?>'
				       type='number'
				       min='1'
				       name='<?php echo $id . '[coupon_amount]'; ?>' value='<?php echo $coupon_amount; ?>' placeholder='Amount in % or <?php echo get_woocommerce_currency_symbol();?>'>
			</td>
		</tr>
		<tr>
			<td style="padding: 0px;" width="40%;">Expires after:</td>
			<td style="padding: 0px;" width="60%;">
				<input id='<?php echo $id . '[expiry_date]'; ?>'
				       type='number'
				       min='1'
				       max='365'
				       name='<?php echo $id . '[expiry_date]'; ?>' value='<?php echo $expiry_date; ?>' placeholder='No expiry'> days
			</td>
		</tr>
		<tr>
			<td style="padding: 0px;" width="40%;">Qualifying order values:</td>
			<td style="padding: 0px;" width="60%;">    Minimum:
				<input id='<?php echo $id . '[minimum_amount]'; ?>'
				       type='number'
				       style='max-width: 75px;'
				       min='1'
				       name='<?php echo $id . '[minimum_amount]'; ?>' value='<?php echo $minimum_amount; ?>' placeholder='None'>    Maximum:
				<input id='<?php echo $id . '[maximum_amount]'; ?>'
				       type='number'
				       style='max-width: 75px;'
				       min='1'
				       name='<?php echo $id . '[maximum_amount]'; ?>' value='<?php echo $maximum_amount; ?>' placeholder='None'>
			</td>
		</tr>
	</table>
	<?php
}

function graphflow_export_button_render( $args ) {
	$id = $args['id'];

	if ( $id == 'woocommerce_graphflow_export_products' ) {
		$export = 'products';
	} else if ( $id == 'woocommerce_graphflow_export_orders' ) {
		$export = 'orders';
	} else if ( $id == 'graphflow_export_posts' ){
		$export = 'posts';
	}
	$export_url = esc_url( remove_query_arg( array( 'gf_exported', 'gf_exported_total' ), add_query_arg( array( 'gf_export' => $export ), admin_url( 'admin.php?page=graphflow-admin' ) ) ) );

	graphflow_render_help_tip( isset( $args['tip'] ) ? $args['tip'] : '' );
	?>
	<a id='<?php echo $id; ?>' href='<?php echo $export_url; ?>' class='button'>Export</a>
	<label for="<?php echo $id; ?>"></label>
	<?php
}

function graphflow_checkbox_field_render( $args ) {
	$id = $args['id'];
	$option_value = get_option( $args['id'], $args['default'] );
	graphflow_render_help_tip( isset( $args['tip'] ) ? $args['tip'] : '', 'checkbox' );
	?>
	<input type='checkbox' name='<?php echo $id; ?>' <?php checked( $option_value, 'yes' ); ?> value='yes'>
	<?php
}

function graphflow_admin_page_main() {

	?>
	<form action='options.php' method='post'>
		<div id="gf_logo_buttons">
			<a href="http://www.graphflow.com" target="_blank"><?php echo '<img src="' . plugins_url( 'assets/graphflow-logo.png' , dirname(__FILE__) ) . '" style="max-width: 180px; margin-top:20px">'; ?></a>
			<p class="submit"><a href="http://docs.woothemes.com/document/woocommerce-graphflow/" target="_blank" class="button-primary">Documentation</a> <a class="docs button-primary" href="https://app.graphflow.com/dashboard/account" target="_blank">Account</a></p>
			<p>
		</div>
		<?php
		settings_fields( 'graphflow_plugin_options' );
		do_settings_sections( 'graphflow-admin' );
		submit_button();
		?>
	</form>
	<?php
}
?>
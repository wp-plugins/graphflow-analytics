<?php

/**
 * Graphflow Emails class, handles email recommendation functionality
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'WC_Graphflow_Emails' ) ) {

	class WC_Graphflow_Emails {

		//private $overview_key;
		private $order_email_key;
		private $followup_email_key;
		private $plugin_settings_tabs;
		private $default_tab;

		/**
		 * Constructor
		 *
		 */
		public function __construct( ) {
			// set up
			//$this->overview_key = 'graphflow_plugin_options_email_overview';
			$this->order_email_key = 'graphflow_plugin_options_email_order';
			$this->followup_email_key = 'graphflow_plugin_options_email_followup';
			$this->plugin_settings_tabs = array(
				//$this->overview_key         => "Overview",
				$this->order_email_key      => "Order Emails",
				$this->followup_email_key   => "Follow Up Emails"
			);
			$this->default_tab = $this->order_email_key; //$this->overview_key;
			$this->includes();

			// admin hooks
			if ( is_admin() )  {
				add_action( 'init', array( $this, 'graphflow_admin_email_test_handler' ) );
				add_action( 'admin_menu', array( $this, 'graphflow_admin_add_menu_email' ) );
				add_action( 'admin_init', array( $this, 'graphflow_admin_settings_init_email' ) );
				add_action( 'admin_notices', array( $this, 'graphflow_admin_notices_email_send' ) );
			}

			// order email hooks
			add_action( 'woocommerce_email_' . $this->get_action(), array( $this, 'order_email_insert_recs' ), 30, 3 );

			// follow-up emails
			add_action( 'woocommerce_order_status_completed_notification', array( $this, 'schedule_followup_email' ) );
			add_action( 'woocommerce_graphflow_send_followup_email', array( $this, 'send_followup_email' ), 10, 2 );

			// custom CSS for emails
			add_filter( 'woocommerce_email_styles', array( $this, 'graphflow_email_styles' ), 99, 1 );

			// Register shortcode
			add_shortcode( 'graphflow_email_recommendations', array( $this, 'email_recommendations_shortcode' ) );

			// prevent logging of action-scheduler events
			add_filter( 'action_scheduler_logger_class', array( $this, 'graphflow_as_logger' ) );

			// === Follow Up Email Integration === //
			add_action( 'plugins_loaded', array( $this, 'setup_fue_integration' ) );

		}

		public function setup_fue_integration()  {
			if ( defined( 'FUE_VERSION' ) ) {
				// email variable replacements
				add_action( 'fue_before_variable_replacements', array( $this, 'register_variable_replacements' ), 10, 4 );
				// add to variables list
				add_action( 'fue_email_variables_list', array( $this, 'form_variables' ) );
				add_action( 'fue_email_manual_variables_list', array( $this, 'form_variables' ) );
				// add Graphflow styles
				add_filter( 'fue_email_inline_css', array( $this, 'graphflow_email_styles_fue' ) );
			}
		}

		/**
		 * graphflow_recommendations shortcode
		 * @param  array $atts
		 * @return string
		 */
		public function email_recommendations_shortcode( $atts ) {

			/**
			 * @var string $user_id
			 * @var int $rows
			 * @var int $cols
			 * @var string $title
			 * @var string $desc
			 * @var int $product_id
			 * @var int $order_id
			 * @var bool $test
			 * @var bool $is_fue
			 * @var string $fue_id
			 * @var string $fue_name
			 * @var string $fue_type
			 * @var bool $fue_is_cart
			 * @var string $fue_trigger
			 */
			extract( shortcode_atts( array(
				'user_id'           => '',
				'product_id' 	    => '',                  // TODO allow related to a single product / set of products?
				'type'              => 'recommended',       // TODO allow popular / trending / recommended
				'rows' 	            => 1,
				'cols' 	            => 3,
				'title'             => 'Recommended for you',
				'desc'              => 'Based on your recent activity, we thought you may be interested in these products.',
				'order_id'          => '',                  // TODO allow products related to an order
				'product_cat'       => '',
				'product_cat_slug'  => '',
				'product_tag'       => '',
				'product_tag_slug'  => '',
				'is_fue'            => false,
				'fue_id'            => '',
				'fue_type'          => '',
				'fue_name'          => '',
				'fue_trigger'       => '',
				'fue_is_cart'       => false,
				'test'              => false
			), $atts ) );

			// handle product category and tags filters
			if ( ! empty ( $product_cat_slug ) ) {
				$product_cat_term = get_term_by( 'slug', $product_cat_slug, 'product_cat' );
				$product_cat = $product_cat_term->term_id;
			}
			if ( ! empty ( $product_tag_slug ) ) {
				$product_tag_term = get_term_by( 'slug', $product_tag_slug, 'product_tag' );
				$product_tag = $product_tag_term->term_id;
			}
			// set up filters - used for shortcode
			$filters_arr = array();
			if ( ! empty( $product_cat ) ) {
				$filters_arr[] = 'product_cat_ids=' . $product_cat;
			}
			if ( ! empty( $product_tag ) ) {
				$filters_arr[] = 'product_tag_ids=' . $product_tag;
			}
			$filters = implode( ',', $filters_arr );

			$rec_type = $is_fue ? 'email_fue_plugin' : 'email_followup';
			$cart_products = array();
			if ( $is_fue ) {
				if ( $fue_is_cart ) {
					// set up cart if exists
					$cart = $this->get_persistent_cart( $user_id );
					foreach ( $cart as $cart_item_key => $cart_item ) {
						$cart_products[] = $cart_item['product_id'];
					}
				}
				$rec_vars = array(
					'fue_id'        => $fue_id,
					'fue_name'      => $fue_name,
					'fue_type'      => $fue_type,
					'fue_trigger'   => $fue_trigger
				);
			} else {
				$rec_vars = array();
			}
			// get coupon args
			$coupon_args = array();  // TODO coupons not supported in shortcode yet

			$email_section = $this->generate_email_rec_section( $user_id, $title, $desc, $rows, $cols, $filters, $rec_type, $test, $coupon_args, $rec_vars, $cart_products );
			return $email_section;
		}

		public function form_variables() {
			echo '<li class="var hideable var_coupon class_coupon"><strong>{graphflow}</strong> <img class="help_tip" title="'. __('Insert Graphflow recommendations for the customer', 'follow_up_emails') .'" src="'. WC()->plugin_url() .'/assets/images/help.png" width="16" height="16" /></li>';
		}

		/**
		 * Return the contents of a customer's persistent cart
		 *
		 * @param int $user_id
		 * @return array
		 */
		public function get_persistent_cart( $user_id ) {
			$cart = get_user_meta( $user_id, '_woocommerce_persistent_cart', true );
			if (! $cart ) {
				$cart['cart'] = array();
			}
			return $cart['cart'];
		}

		private function init_wc_cart( $user_id ) {
			if ( isset( WC()->cart ) && is_a( WC()->cart, 'WC_Cart' ) ) {
				return;
			}
			include_once( WC()->plugin_path() .'/includes/abstracts/abstract-wc-session.php' );
			include_once( WC()->plugin_path() .'/includes/class-wc-session-handler.php' );
			WC()->cart = new WC_Cart();
			WC()->session = new WC_Session_Handler();
			$cart = $this->get_persistent_cart( $user_id );
			WC()->session->cart = $cart;
			WC()->cart->init();
		}

		public function register_variable_replacements( $var, $email_data, $email, $queue_item ) {
			preg_match('|\{graphflow ([^}]+)\}|', $email_data['message'], $matches );
			if ( !empty( $matches[1] ) ) {
				$gf_var = $matches[0];
				$gf_var = str_replace( '}', '', str_replace( '{', '', $gf_var ) );
				$gf_shortcode = str_replace( '[', '[graphflow_email_recommendations ', $matches[1] );
			} else {
				return;
			}
			$user_id = $email_data['user_id'];
			$user_email = $email_data['email_to'];
			if ( $user_id == 0 ) {
				$user_id = $user_email;
			}
			$fue_id = $email->id;
			$fue_name = $email->name;
			$fue_type = $email->type;
			$fue_trigger = $email->trigger;
			$fue_vars = "fue_id='" . $fue_id . "' fue_name='" . $fue_name . "' fue_type='" . $fue_type  . "' fue_trigger='" . $fue_trigger . "' fue_is_cart='" . $queue_item->is_cart . "' is_fue=1]";

			// insert user id and handle FUE plugin links
			$gf_shortcode = preg_replace( '|\]|', ' user_id=' . $user_id . ' ' . $fue_vars, $gf_shortcode );
			// use test data if the test flag is set
			if ( isset( $email_data['test'] ) && $email_data['test'] ) {
				$gf_shortcode = preg_replace( '|\]|', ' test=1 ' . $fue_vars, $gf_shortcode );
			}

			$recs = do_shortcode( $gf_shortcode );
			// handle FUE link variable HTML entity encoding
			$recs = str_replace( '%7B', '{', $recs );
			$recs = str_replace( '%7D', '}', $recs );
			$variables[$gf_var] = $recs;
			$var->register( $variables );
		}


		public function graphflow_as_logger() {
			return "WC_Graphflow_ActionScheduler_Logger";
		}

		private function get_action() {
			$action = get_option( 'woocommerce_graphflow_email_location', 'customer_details' );
			if ( defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, '2.3', '<' ) && $action == 'customer_details' ) {
				$action = 'order_meta';
			}
			return $action;
		}

		private function includes() {
			// only include ActionScheduler classes if follow-up emails are active
			if ( get_option( 'woocommerce_graphflow_email_followup_active', 'no' ) !== 'yes' ) {
				return;
			}
			if ( ! function_exists( 'wc_schedule_single_action' ) ) {
				require 'action-scheduler/action-scheduler.php';
			}
			include 'class-wc-graphflow-as-logger.php';
		}

		// == Email Admin Page Functions == //

		public function graphflow_admin_add_menu_email() {
			add_submenu_page( 'graphflow-admin', 'Graphflow Email Settings', 'Emails', 'manage_options', 'graphflow-admin-email', array( $this, 'graphflow_admin_page_email' ) );
		}

		public function graphflow_register_email_settings() {
			// order email settings
			register_setting( $this->order_email_key, 'woocommerce_graphflow_email_active' );
			register_setting( $this->order_email_key, 'woocommerce_graphflow_email_location' );
			register_setting( $this->order_email_key, 'woocommerce_graphflow_email_types' );
			register_setting( $this->order_email_key, 'woocommerce_graphflow_email_rows' );
			register_setting( $this->order_email_key, 'woocommerce_graphflow_email_cols' );
			register_setting( $this->order_email_key, 'woocommerce_graphflow_email_title' );
			register_setting( $this->order_email_key, 'woocommerce_graphflow_email_desc' );
			register_setting( $this->order_email_key, 'woocommerce_graphflow_email_coupon' );
			register_setting( $this->order_email_key, 'woocommerce_graphflow_email_css' );

			// follow up email settings
			register_setting( $this->followup_email_key, 'woocommerce_graphflow_email_followup_active' );
			register_setting( $this->followup_email_key, 'woocommerce_graphflow_email_followup_unsub_link' );
			register_setting( $this->followup_email_key, 'woocommerce_graphflow_email_followup_types' );
			register_setting( $this->followup_email_key, 'woocommerce_graphflow_email_followup_schedule' );
			register_setting( $this->followup_email_key, 'woocommerce_graphflow_email_followup_rows' );
			register_setting( $this->followup_email_key, 'woocommerce_graphflow_email_followup_cols' );
			register_setting( $this->followup_email_key, 'woocommerce_graphflow_email_followup_subject' );
			register_setting( $this->followup_email_key, 'woocommerce_graphflow_email_followup_title' );
			register_setting( $this->followup_email_key, 'woocommerce_graphflow_email_followup_desc' );
			register_setting( $this->followup_email_key, 'woocommerce_graphflow_email_followup_coupon' );
			register_setting( $this->followup_email_key, 'woocommerce_graphflow_email_followup_css' );
		}

		/**
		 * Order Email Settings Section
		 */
		public function graphflow_order_email_settings() {

			add_settings_section(
				'graphflow_email_options',
				__( 'Order Email Settings', 'wp-graphflow' ),
				array( $this, 'graphflow_email_options_callback' ),
				$this->order_email_key
			);

			// Emails active
			add_settings_field(
				'woocommerce_graphflow_email_active',
				__( 'Enabled', 'wp-graphflow' ),
				'graphflow_checkbox_field_render',
				$this->order_email_key,
				'graphflow_email_options',
				array(
					'id'        => 'woocommerce_graphflow_email_active',
					'label_for' => 'woocommerce_graphflow_email_active',
					'default'   => 'no'
				)
			);

			// Email recommendation location
			add_settings_field(
				'woocommerce_graphflow_email_location',
				__( 'Location', 'wp-graphflow' ),
				'graphflow_select_field_render',
				$this->order_email_key,
				'graphflow_email_options',
				array(
					'id'        => 'woocommerce_graphflow_email_location',
					'label_for' => 'woocommerce_graphflow_email_location',
					'default'   => 'customer_details',
					'css'       => 'max-width: 400px',
					'options'   => array(
						'order_meta'       => 'After Order Data',
						'customer_details' => 'After Customer Details'
					),
					'tip'       => __( 'Select the location within customer emails where the recommendations section will be displayed', 'wp-graphflow' )
				)
			);

			// Order status for which to insert email recs
			add_settings_field(
				'woocommerce_graphflow_email_types',
				__( 'Order Statuses', 'wp-graphflow' ),
				'graphflow_multiselect_field_render',
				$this->order_email_key,
				'graphflow_email_options',
				array(
					'id'        => 'woocommerce_graphflow_email_types',
					'label_for' => 'woocommerce_graphflow_email_types',
					'default'   => array( 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed' ),
					'options'   => wc_get_order_statuses(),
					'tip'       => __( 'Recommendations will be inserted into order emails for the selected order statuses', 'wp-graphflow' ),
				)
			);

			// Recs rows
			add_settings_field(
				'woocommerce_graphflow_email_rows',
				__( 'Rows', 'wp-graphflow' ),
				'graphflow_select_field_render',
				$this->order_email_key,
				'graphflow_email_options',
				array(
					'id'        => 'woocommerce_graphflow_email_rows',
					'label_for' => 'woocommerce_graphflow_email_rows',
					'options'   => array( 1 => 1, 2 => 2, 3 => 3),
					'default'   => 1,
					'css' 		=> 'max-width:50px;',
					'tip'       => __( 'Number of rows of recommended products in emails', 'wp-graphflow' )
				)
			);

			// Recs cols
			add_settings_field(
				'woocommerce_graphflow_email_cols',
				__( 'Columns', 'wp-graphflow' ),
				'graphflow_select_field_render',
				$this->order_email_key,
				'graphflow_email_options',
				array(
					'id'        => 'woocommerce_graphflow_email_cols',
					'label_for' => 'woocommerce_graphflow_email_cols',
					'options'   => array( 1 => 1, 2 => 2, 3 => 3),
					'default'   => 3,
					'css' 		=> 'max-width:50px;',
					'tip'       => __( 'Number of columns of recommended products in emails', 'wp-graphflow' )
				)
			);

			// Email title
			add_settings_field(
				'woocommerce_graphflow_email_title',
				__( 'Title', 'wp-graphflow' ),
				'graphflow_text_field_render',
				$this->order_email_key,
				'graphflow_email_options',
				array(
					'id'        => 'woocommerce_graphflow_email_title',
					'label_for' => 'woocommerce_graphflow_email_title',
					'default'   => 'Recommended for you',
					'css' 		=> 'min-width:400px;',
					'tip'       => __( 'Title text to display for email recommendations', 'wp-graphflow' ),
				)
			);

			// Email desc
			add_settings_field(
				'woocommerce_graphflow_email_desc',
				__( 'Description', 'wp-graphflow' ),
				'graphflow_textarea_field_render',
				$this->order_email_key,
				'graphflow_email_options',
				array(
					'id'        => 'woocommerce_graphflow_email_desc',
					'label_for' => 'woocommerce_graphflow_email_desc',
					'default'   => 'Based on your recent activity, we thought you may be interested in these products.',
					'css' 		=> 'min-width:400px;',
					'cols'      => 78,
					'rows'      => 3,
					'tip'       => __( 'Descriptive text to display for email recommendations', 'wp-graphflow' ),
				)
			);

			// Email coupon
			add_settings_field(
				'woocommerce_graphflow_email_coupon',
				__( 'Coupon', 'wp-graphflow' ),
				'graphflow_coupon_field_render',
				$this->order_email_key,
				'graphflow_email_options',
				array(
					'id'        => 'woocommerce_graphflow_email_coupon',
					'label_for' => 'woocommerce_graphflow_email_coupon',
					'default'   => $this->coupon_defaults(),
					'options'   => array(
						'none'       => 'No Coupon',
						'fixed_cart' => 'Fixed Value for Cart',
						'percent'    => 'Percentage Discount for Cart'
					),
					'css' 		=> '',
					'tip'       => __( 'Select a type of coupon here to include a unique coupon code in each email recommendation section', 'wp-graphflow' ),
				)
			);

			// Email Custom CSS
			add_settings_field(
				'woocommerce_graphflow_email_css',
				__( 'Email Custom CSS', 'wp-graphflow' ),
				'graphflow_textarea_field_render',
				$this->order_email_key,
				'graphflow_email_options',
				array(
					'id'        => 'woocommerce_graphflow_email_css',
					'label_for' => 'woocommerce_graphflow_email_css',
					'default'   => $this->graphflow_email_styles_template(),
					'cols'      => 78,
					'rows'      => 11,
					'tip'       => __( 'Custom CSS attributes to control styling of email recommendations', 'wp-graphflow' ),
				)
			);
		}

		/**
		 * Follow Up Email Settings Section
		 */
		public function graphflow_followup_email_settings() {

			add_settings_section(
				'graphflow_email_followup_options',
				__( 'Follow Up Email Settings', 'wp-graphflow' ),
				array( $this, 'graphflow_email_options_followup_callback' ),
				$this->followup_email_key
			);

			// Emails active
			add_settings_field(
				'woocommerce_graphflow_email_followup_active',
				__( 'Enabled', 'wp-graphflow' ),
				'graphflow_checkbox_field_render',
				$this->followup_email_key,
				'graphflow_email_followup_options',
				array(
					'id'        => 'woocommerce_graphflow_email_followup_active',
					'label_for' => 'woocommerce_graphflow_email_followup_active',
					'default'   => 'no'
				)
			);

			// Include Unsubscribe link
			add_settings_field(
				'woocommerce_graphflow_email_followup_unsub_link',
				__( 'Allow Unsubscribe', 'wp-graphflow' ),
				'graphflow_checkbox_field_render',
				$this->followup_email_key,
				'graphflow_email_followup_options',
				array(
					'id'        => 'woocommerce_graphflow_email_followup_unsub_link',
					'label_for' => 'woocommerce_graphflow_email_followup_unsub_link',
					'default'   => 'yes',
					'tip'       => __( 'Select this option to include an unsubscribe link in Follow Up Emails', 'wp-graphflow' )
				)
			);

			// When to schedule follow up
			add_settings_field(
				'woocommerce_graphflow_email_followup_types',
				__( 'Trigger', 'wp-graphflow' ),
				'graphflow_select_field_render',
				$this->followup_email_key,
				'graphflow_email_followup_options',
				array(
					'id'        => 'woocommerce_graphflow_email_followup_types',
					'label_for' => 'woocommerce_graphflow_email_followup_types',
					'default'   => 'customer_details',
					'css'       => 'max-width: 400px',
					'options'   => array(
						'completed'     => 'Schedule after a completed order',
						//'processing'    => 'Schedule after a processing order'
					),
					'tip'       => __( 'Select the trigger for scheduling Follow Up Emails', 'wp-graphflow' )
				)
			);

			// Schedule
			add_settings_field(
				'woocommerce_graphflow_email_followup_schedule',
				__( 'Schedule', 'wp-graphflow' ),
				'graphflow_number_field_render',
				$this->followup_email_key,
				'graphflow_email_followup_options',
				array(
					'id'        => 'woocommerce_graphflow_email_followup_schedule',
					'label_for' => 'woocommerce_graphflow_email_followup_schedule',
					'min'       => 1,
					'max'       => 90,
					'default'   => 14,
					'css' 		=> 'max-width:50px;',
					'after'     => 'days',
					'tip'       => __( 'Select the interval (in days) after the trigger when the email will be sent', 'wp-graphflow' )
				)
			);

			// Recs rows
			add_settings_field(
				'woocommerce_graphflow_email_followup_rows',
				__( 'Rows', 'wp-graphflow' ),
				'graphflow_select_field_render',
				$this->followup_email_key,
				'graphflow_email_followup_options',
				array(
					'id'        => 'woocommerce_graphflow_email_followup_rows',
					'label_for' => 'woocommerce_graphflow_email_followup_rows',
					'options'   => array( 1 => 1, 2 => 2, 3 => 3),
					'default'   => 1,
					'css' 		=> 'max-width:50px;',
					'tip'       => __( 'Number of rows of recommended products in emails', 'wp-graphflow' )
				)
			);

			// Recs cols
			add_settings_field(
				'woocommerce_graphflow_email_followup_cols',
				__( 'Columns', 'wp-graphflow' ),
				'graphflow_select_field_render',
				$this->followup_email_key,
				'graphflow_email_followup_options',
				array(
					'id'        => 'woocommerce_graphflow_email_followup_cols',
					'label_for' => 'woocommerce_graphflow_email_followup_cols',
					'options'   => array( 1 => 1, 2 => 2, 3 => 3),
					'default'   => 3,
					'css' 		=> 'max-width:50px;',
					'tip'       => __( 'Number of columns of recommended products in emails', 'wp-graphflow' )
				)
			);

			// Email subject
			add_settings_field(
				'woocommerce_graphflow_email_followup_subject',
				__( 'Subject', 'wp-graphflow' ),
				'graphflow_text_field_render',
				$this->followup_email_key,
				'graphflow_email_followup_options',
				array(
					'id'        => 'woocommerce_graphflow_email_followup_subject',
					'label_for' => 'woocommerce_graphflow_email_followup_subject',
					'default'   => 'Your recommendations from {site_title}',
					'css' 		=> 'min-width:400px;',
					'tip'       => __( 'Subject for follow up emails', 'wp-graphflow' ),
				)
			);

			// Email title
			add_settings_field(
				'woocommerce_graphflow_email_followup_title',
				__( 'Header', 'wp-graphflow' ),
				'graphflow_text_field_render',
				$this->followup_email_key,
				'graphflow_email_followup_options',
				array(
					'id'        => 'woocommerce_graphflow_email_followup_title',
					'label_for' => 'woocommerce_graphflow_email_followup_title',
					'default'   => 'Recommended for you',
					'css' 		=> 'min-width:400px;',
					'tip'       => __( 'Header to display for follow up emails', 'wp-graphflow' ),
				)
			);

			// Email desc
			add_settings_field(
				'woocommerce_graphflow_email_followup_desc',
				__( 'Description', 'wp-graphflow' ),
				'graphflow_textarea_field_render',
				$this->followup_email_key,
				'graphflow_email_followup_options',
				array(
					'id'        => 'woocommerce_graphflow_email_followup_desc',
					'label_for' => 'woocommerce_graphflow_email_followup_desc',
					'default'   => 'Based on your recent activity, we thought you may be interested in these products.',
					'css' 		=> 'min-width:400px;',
					'cols'      => 78,
					'rows'      => 3,
					'tip'       => __( 'Descriptive text to display for follow up emails', 'wp-graphflow' ),
				)
			);

			// Email coupon
			add_settings_field(
				'woocommerce_graphflow_email_followup_coupon',
				__( 'Coupon', 'wp-graphflow' ),
				'graphflow_coupon_field_render',
				$this->followup_email_key,
				'graphflow_email_followup_options',
				array(
					'id'        => 'woocommerce_graphflow_email_followup_coupon',
					'label_for' => 'woocommerce_graphflow_email_followup_coupon',
					'default'   => $this->coupon_defaults(),
					'options'   => array(
						'none'       => 'No Coupon',
						'fixed_cart' => 'Fixed Value for Cart',
						'percent'    => 'Percentage Discount for Cart'
					),
					'css' 		=> '',
					'tip'       => __( 'Select a type of coupon here to include a unique coupon code in each email recommendation section', 'wp-graphflow' ),
				)
			);

			// Email Custom CSS
			add_settings_field(
				'woocommerce_graphflow_email_followup_css',
				__( 'Custom CSS', 'wp-graphflow' ),
				'graphflow_textarea_field_render',
				$this->followup_email_key,
				'graphflow_email_followup_options',
				array(
					'id'        => 'woocommerce_graphflow_email_followup_css',
					'label_for' => 'woocommerce_graphflow_email_followup_css',
					'default'   => $this->graphflow_email_styles_followup_template(),
					'cols'      => 78,
					'rows'      => 11,
					'tip'       => __( 'Custom CSS attributes to control styling of follow up email recommendations', 'wp-graphflow' ),
				)
			);
		}

		public function graphflow_admin_settings_init_email() {
			$this->graphflow_register_email_settings();
			$this->graphflow_order_email_settings();
			$this->graphflow_followup_email_settings();
		}

		public function graphflow_email_options_callback() {
			echo __( 'Graphflow recommendations can be inserted into order emails sent to customers.<br>' .
			         'These settings control when emails are sent, as well as the styling of the recommendations.', 'wp-graphflow' );
		}

		public function graphflow_email_options_followup_callback() {
			echo __( 'Graphflow recommendations can be inserted into customer follow up emails, sent at a specified interval after a customer transaction.<br> ' .
			         'These settings control when emails are sent, as well as the styling of the recommendations.', 'wp-graphflow' );
		}

		public function graphflow_admin_page_email_tabs() {
			$current_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : $this->default_tab;

			echo '<h2 class="nav-tab-wrapper">';
			foreach ( $this->plugin_settings_tabs as $tab_key => $tab_caption ) {
				$active = $current_tab == $tab_key ? 'nav-tab-active' : '';
				echo '<a class="nav-tab ' . $active . '" href="?page=graphflow-admin-email' . '&tab=' . $tab_key . '">' . $tab_caption . '</a>';
			}
			echo '</h2>';
			/*
			if ( $current_tab == $this->overview_key ) {
				?>
				<div id="gf_email_logo">
					<a href="http://www.graphflow.com" target="_blank"><?php echo '<img src="' . plugins_url( 'assets/graphflow-emailrecs.png' , dirname(__FILE__) ) . '" style="align: center; max-width: 600px; margin-top:20px">'; ?></a>
				</div>
				<?php
			} else {
			*/
				?>
				<form action='options.php' method='post'>
					<?php wp_nonce_field( 'update-options' ); ?>
					<?php settings_fields( $current_tab ); ?>
					<?php do_settings_sections( $current_tab ); ?>
					<?php submit_button(); ?>
				</form>
				<?php
			//}
		}

		public function do_email_preview( $tab ) {
			//if ( $tab == $this->overview_key ) return;
			$current_user = wp_get_current_user();
			$default_user_email = $current_user->user_email;
			$email_type = rtrim( $this->plugin_settings_tabs[$tab], 's' );
			?>
			<div class="graphflow_email_container">
				<h3>Email Preview</h3>
				<p>Below you can view a preview of your <?php echo $email_type; ?>, and send a test email to the selected email address.
					<strong>Note that products in the preview are chosen from your newest products.</strong>
				</p>
				<form action="admin.php?page=graphflow-admin-email&tab=<?php echo $tab; ?>" method="post">
					<input type="text" name="gf_send_test_email_address" value="<?php echo $default_user_email; ?>">
					<input name="gf_send_test_email" id="gf_send_test_email" class="button" value="Send Test Email" type="submit">
					<a href="#" class="button toggle_editor" style="text-align: right"></a>
				</form>
				<p></p>
				<div class="graphflow_email_recommendations_preview" style="display: none; background-color: white; max-width: 900px">
					<?php echo $this->graphflow_generate_preview_email( $tab ); ?>
				</div>
			</div>
			<?php
		}

		public function graphflow_admin_page_email() {
			$tab = isset( $_GET['tab'] ) ? $_GET['tab'] : $this->default_tab;
			?>
			<div class="wrap"></div>
			<?php $this->graphflow_admin_page_email_tabs(); ?>
			<?php $this->do_email_preview( $tab ); ?>
			<?php

		}

		public function graphflow_generate_preview_email( $type ) {
			$mailer = WC()->mailer();
			if ( $type == $this->followup_email_key ) {
				$gf_title = get_option( 'woocommerce_graphflow_email_followup_title', 'Recommended for you' );
				$gf_desc = get_option( 'woocommerce_graphflow_email_followup_desc', 'Based on your recent activity, we thought you may be interested in these products.' );
				$gf_rows = get_option( 'woocommerce_graphflow_email_followup_rows', 1 );
				$gf_columns = get_option( 'woocommerce_graphflow_email_followup_cols', 3 );
				// get coupon args
				$coupon_args = get_option( 'woocommerce_graphflow_email_followup_coupon', $this->coupon_defaults() );
				$email_section = $this->generate_email_rec_section( '', '', $gf_desc, $gf_rows, $gf_columns, '', 'email_followup', true, $coupon_args );
				$email_heading = __( $gf_title, 'wp-graphflow' );

				// wrap message manually
				$message = $mailer->wrap_message( $email_heading, $email_section );
				$email = new WC_Email();
			} else if ( $type == $this->order_email_key ) {
				$order_id = $this->get_latest_order();
				remove_action( 'woocommerce_email_' . $this->get_action(), array( $this, 'order_email_insert_recs' ), 30 );
				add_action( 'woocommerce_email_' . $this->get_action(), array( $this, 'order_email_preview_insert_recs' ), 30, 3 );
				$email = new WC_Email_Customer_Completed_Order();
				if ( $order_id ) {
					$email->object = wc_get_order( $order_id );

					$email->find['order-date']      = '{order_date}';
					$email->find['order-number']    = '{order_number}';

					$email->replace['order-date']   = date_i18n( wc_date_format(), strtotime( $email->object->order_date ) );
					$email->replace['order-number'] = $email->object->get_order_number();
					$message = $email->get_content_html();
				} else {
					$gf_title = get_option( 'woocommerce_graphflow_email_title', 'Recommended for you' );
					$gf_desc = get_option( 'woocommerce_graphflow_email_desc', 'Based on your recent activity, we thought you may be interested in these products.' );
					$gf_rows = get_option( 'woocommerce_graphflow_email_rows', 1 );
					$gf_columns = get_option( 'woocommerce_graphflow_email_cols', 3 );
					// get coupon args
					$coupon_args = get_option( 'woocommerce_graphflow_email_coupon', $this->coupon_defaults() );
					$email_section = $this->generate_email_rec_section( '', $gf_title, $gf_desc, $gf_rows, $gf_columns, '', 'email_order', true, $coupon_args );
					$email_heading = __( 'No orders available, previewing recommendation section only', 'wp-graphflow' );
					// wrap message manually
					$message = $mailer->wrap_message( $email_heading, $email_section );
				}
			} else {
				return "Error: invalid email type: " . $type;
			}
			$email = $email->style_inline( $message );
			return $email;
		}

		public function get_most_recent_products( $num ) {
			$query_args = array(
				'posts_per_page' => $num,
				'post_status' 	 => 'publish',
				'post_type' 	 => 'product',
				'order_by'       => 'date',
				'order'          => 'DESC'
			);
			$query = new WP_Query( $query_args );
			$products = array();
			while ( $query->have_posts() ) {
				$query->the_post();
				$products[] = get_the_ID();
			}
			return $products;
		}

		public function get_latest_order() {
			$query_args = array(
				'posts_per_page' => 1,
				'post_status' 	 => array( 'wc-completed' ),
				'post_type' 	 => 'shop_order',
				'orderby'        => 'post_date',
				'order'          => 'DESC',
			);
			$query = new WP_Query( $query_args );
			while ( $query->have_posts() ) {
				$query->the_post();
				return get_the_ID();
			}
			return false;
		}


		public function graphflow_admin_email_test_handler() {
			if ( isset( $_POST['gf_send_test_email_address'] ) && isset( $_POST['gf_send_test_email'] ) ) {
				$email_address = sanitize_text_field( $_POST['gf_send_test_email_address'] );
				$email_type = sanitize_text_field( $_REQUEST['tab'] );
				$this->graphflow_send_test_email( $email_address, $email_type );
			}
		}

		public function graphflow_send_test_email( $email_address, $email_type ) {
			$message = $this->graphflow_generate_preview_email( $email_type );
			$type_text = rtrim( $this->plugin_settings_tabs[$email_type], 's' );
			$subject = __( 'Graphflow ' . $type_text . ' Recommendation Preview', 'wp-graphflow' );
			$headers = array();
			$headers[] = 'Content-Type: text/html' . PHP_EOL;
			if ( wp_mail( $email_address, $subject, $message, $headers ) ) {
				wp_redirect( add_query_arg( array( 'page' => 'graphflow-admin-email', 'gf_sent_email_test' => true, 'tab' => $email_type ), admin_url( 'admin.php' ) ) );
			} else {
				wp_redirect( add_query_arg( array( 'page' => 'graphflow-admin-email', 'gf_sent_email_test_error' => true, 'tab' => $email_type ), admin_url( 'admin.php' ) ) );
			}
		}

		public function graphflow_admin_notices_email_send() {
			if ( isset( $_REQUEST['gf_sent_email_test'] ) ){
				echo '<div id="message" class="updated fade notice notice-success is-dismissible below-h2"><p><strong>' .__( 'Sent email recommendation preview', 'wp-graphflow' ) . '</strong></p></div>';
			} else if ( isset( $_REQUEST['gf_sent_email_test_error'] ) ) {
				echo '<div id="message" class="error fade notice notice-error is-dismissible below-h2"><p><strong>' .__( 'Failed to send email recommendation preview', 'wp-graphflow' ) . '</strong></p></div>';
			}
		}

		// == Follow Up Email Functions == //

		public function schedule_followup_email( $order_id ) {
			// bail if no order ID is present
			if ( ! $order_id )
				return;

			// setup order object
			$order = wc_get_order( $order_id );
			// populate user id
			$order_user = $order->get_user_id() == 0 ? $order->billing_email : $order->get_user_id();
			$order_email = $order->billing_email;

			if ( get_option( 'woocommerce_graphflow_email_followup_active', 'no' ) !== 'yes' )
				return;

			$schedule = absint( get_option( 'woocommerce_graphflow_email_followup_schedule', 14 ) );
			$schedule = min( 90, max( 1, $schedule ) );             // 1 - 90 days
			$rand = rand( 10, 60 );                                 // add a random 10-60 minutes to the scheduled time
			$date = strtotime("+" . $schedule . " days, +" . $rand . " minutes", strtotime( 'now' ) );
			//$date = strtotime("+1 minutes", strtotime( 'now' ) );   // FOR DEBUGGING

			$GLOBALS['wc_graphflow']->get_api()->log->add(
				"graphflow",
				"Scheduling follow up email for order_id: " . $order_id . '; customer: ' . $order_user . "; email: " . $order_email . "; scheduled for: " . date( "Y-m-d H:i:s", $date ) );

			wc_schedule_single_action( $date, 'woocommerce_graphflow_send_followup_email', array( $order_user, $order_email ), 'graphflow_email');
		}

		public function unsubscribe_link() {
			if ( get_option( 'woocommerce_graphflow_email_followup_unsub_link', 'yes' ) !== 'yes' ) {
				return;
			}
			if ( isset( $_REQUEST['gf_unsub_link'] ) ) {
				$unsub = $_REQUEST['gf_unsub_link'];
				$unsub_text = apply_filters( 'graphflow_recommended_products_email_unsub_text', 'Unsubscribe' );
				?>
				<!-- Graphflow Unsubscribe  -->
				<div class="graphflow-unsub">
					<table border="0" cellpadding="0" cellspacing="0" width="100%">
						<tr>
							<td valign="top">
								<table border="0" cellpadding="10" cellspacing="0" width="100%">
									<tr>
										<td colspan="2" valign="middle" align="center">
											<a class="graphflow-unsub-link" href="<?php echo $unsub; ?>"
											   style="text-align: center"><?php echo $unsub_text; ?></a>
										</td>
									</tr>
								</table>
							</td>
						</tr>
					</table>
				</div>
				<!-- End Unsubscribe Link -->
				<?php
			} else {
				echo '';
			}
		}

		/**
		 * @param $user_id
		 * @param $user_email
		 * @internal param bool $is_preview
		 */
		public function send_followup_email( $user_id, $user_email ) {
			// populate user id
			$gf_userid = $user_id;
			if ( $gf_userid == 0 ) {
				$gf_userid = $user_email;
			}

			// set up filter
			$filters = '';
			// set up other parameters
			$gf_subject = get_option( 'woocommerce_graphflow_email_followup_subject', 'Your recommendations from {site_title}' );
			$gf_title = get_option( 'woocommerce_graphflow_email_followup_title', 'Recommended for you' );
			$gf_desc = get_option( 'woocommerce_graphflow_email_followup_desc', 'Based on your recent activity, we thought you may be interested in these products.' );
			$gf_rows = get_option( 'woocommerce_graphflow_email_followup_rows', 1 );
			$gf_columns = get_option( 'woocommerce_graphflow_email_followup_cols', 3 );
			// get coupon args
			$coupon_args = get_option( 'woocommerce_graphflow_email_followup_coupon', $this->coupon_defaults() );
			$coupon_args['customer_email'] = $user_email;
			$email_section = $this->generate_email_rec_section( $gf_userid, '', $gf_desc, $gf_rows, $gf_columns, $filters, 'email_followup', false, $coupon_args );
			if ( empty( $email_section ) ) return;

			$mailer = WC()->mailer();
			add_action( 'woocommerce_email_footer', array( $this, 'unsubscribe_link' ), 99 );

			$message = $mailer->wrap_message( $gf_title, $email_section );
			$wc_email = new WC_Email();
			$wc_email->subject = $gf_subject;
			$result = $wc_email->send( $user_email, $wc_email->get_subject(), $message, 'text/html', array() );

			remove_action( 'woocommerce_email_footer', array( $this, 'unsubscribe_link' ) );

			if ( $result ) {
				$GLOBALS['wc_graphflow']->get_api()->log->add(
					"graphflow",
					"Successfully sent follow-up email for customer (id: " . $user_id . "; email: " . $user_email . ")" );
			} else {
				$GLOBALS['wc_graphflow']->get_api()->log->add(
					"graphflow",
					"Failed to send follow-up email for customer (id: " . $user_id . "; email: " . $user_email . ")" );
			}
		}

		public function coupon_defaults() {
			return array(
				'coupon_type'   => 'none',
				'expiry_date'   => '14',
				'coupon_title'  => 'Get {discount} off your next order!',
				'coupon_text'   => 'Use the coupon code below to receive a {discount} discount on your cart when you next check out:');
		}

		public function coupon_discount_text( $type, $amount ) {
			if ( $type == 'percent' ) {
				return $amount . '%';
			} else if ( $type == 'fixed_cart' ) {
				return get_woocommerce_currency_symbol() . $amount;
			}
		}

		public function coupon_limit_text( $min, $max, $email ) {
			$symbol = get_woocommerce_currency_symbol();
			if ( $min && $max ) {
				$text = 'Subject to a minimum order value of ' . $symbol . $min . ' and a maximum order value of ' . $symbol . $max . '.';
			} else if ( $min ) {
				$text = 'Subject to a minimum order value of ' . $symbol . $min . '.';
			} else if ( $max ) {
				$text = 'Subject to a maximum order value of ' . $symbol . $max . '.';
			} else {
				$text = '';
			}
			if ( !empty( $email ) ) {
				$text .= empty( $text ) ? '' : ' ';
				$text .= 'Coupon must be used with this email address: ' . $email[0];
			}
			return $text;
		}

		public function create_coupon( $args, $preview = false ) {
			$discount_type = $args['coupon_type'];         // Type: fixed_cart, percent, fixed_product, percent_product
			if ( $discount_type === 'none' || !isset( $args['coupon_code'] ) || empty( $args['coupon_code'] ) )
				return array();
			$coupon_code = $args['coupon_code'];           // Coupon code
			$coupon_amount = $args['coupon_amount'];       // Amount
			// expiry date
			$expiry_date = $args['expiry_date'];
			$schedule = absint( $expiry_date );
			$schedule = min( 365, max( 0, $schedule ) );             // 0 - 365 days; 0 days => same date of email sending
			$expiry = strftime( '%Y-%m-%d', strtotime("+" . $schedule . " days", strtotime( 'now' ) ) );
			$expiry_pretty = strftime( '%B %e, %Y', strtotime( $expiry ) );

			$customer_email = isset( $args['customer_email'] ) ? array( $args['customer_email'] ) : array();
			$minimum_amount = isset( $args['minimum_amount'] ) ? $args['minimum_amount'] : '';
			$maximum_amount = isset( $args['maximum_amount'] ) ? $args['maximum_amount'] : '';

			if ( !$preview ) {
				$coupon = array(
					'post_title'    => $coupon_code,
					'post_content'  => '',
					'post_excerpt'  => 'Auto-generated by Graphflow',
					'post_status'   => 'publish',
					'post_author'   => 1,
					'post_type'		=> 'shop_coupon'
				);

				$new_coupon_id = wp_insert_post( $coupon );

				// coupon Add meta
				update_post_meta( $new_coupon_id, 'discount_type', $discount_type );
				update_post_meta( $new_coupon_id, 'coupon_amount', $coupon_amount );
				update_post_meta( $new_coupon_id, 'individual_use', 'yes' );
				update_post_meta( $new_coupon_id, 'usage_limit', '1' );
				update_post_meta( $new_coupon_id, 'usage_limit_per_user', '1' );
				update_post_meta( $new_coupon_id, 'minimum_amount', $minimum_amount );
				update_post_meta( $new_coupon_id, 'maximum_amount', $maximum_amount );
				update_post_meta( $new_coupon_id, 'product_ids', '' );
				update_post_meta( $new_coupon_id, 'exclude_product_ids', '' );
				update_post_meta( $new_coupon_id, 'expiry_date', $expiry );
				update_post_meta( $new_coupon_id, 'apply_before_tax', 'yes' );
				update_post_meta( $new_coupon_id, 'free_shipping', 'no' );
				update_post_meta( $new_coupon_id, 'customer_email', $customer_email );
				update_post_meta( $new_coupon_id, '_graphflow', 'yes');
			}

			// extract text
			$coupon_title = str_replace( '{discount}', $this->coupon_discount_text( $discount_type, $coupon_amount ), $args['coupon_title'] );
			$coupon_text = str_replace( '{discount}', $this->coupon_discount_text( $discount_type, $coupon_amount ), $args['coupon_text'] );
			$coupon_limit = $this->coupon_limit_text( $minimum_amount, $maximum_amount, $customer_email );

			return array(
				'code'      => $coupon_code,
				'title'     => $coupon_title,
				'text'      => $coupon_text,
				'expiry'    => $expiry_pretty,
				'limit'     => $coupon_limit);
		}

		// == Order Email Functions == //

		/**
		 * @param $gf_userid
		 * @param $gf_title
		 * @param $gf_desc
		 * @param $gf_rows
		 * @param $gf_columns
		 * @param $filters
		 * @param string $rec_type
		 * @param bool|false $is_preview
		 * @param array $coupon_args
		 * @param array $rec_vars
		 * @param array $products
		 * @return string
		 * @internal param string $email_type
		 */
		public function generate_email_rec_section(
			$gf_userid,
			$gf_title,
			$gf_desc,
			$gf_rows,
			$gf_columns,
			$filters,
			$rec_type = "email_order",
			$is_preview = false,
			$coupon_args = array(),
			$rec_vars = array(),
			$products = array()) {

			// set up email type
			$is_fue = false;
			switch ( $rec_type ) {
				case 'email_order':
					$email_class = 'graphflow_recommendations_email';
					break;
				case 'email_followup':
					$email_class = 'graphflow_recommendations_email_followup';
					break;
				case 'email_fue_plugin':
					$email_class = 'graphflow_recommendations_email_followup';
					$is_fue = true;
					break;
			}

			// generate products
			$gf_num = absint( $gf_rows * $gf_columns );
			if ( $is_preview ) {
				$gf_products = $this->get_most_recent_products( $gf_num );
				$gf_recId = '';
				// create coupon from args
				$coupon_args['coupon_code'] = 'gf_discount_coupon_code';
				$coupon = $this->create_coupon( $coupon_args, true );
			} else {
				if ( !empty( $products ) ) {
					$gf_result = get_cart_recommendations_email( $products, $gf_userid, $gf_num, $filters, $rec_type, $rec_vars );
				} else {
					$gf_result = get_user_recommendations( $gf_userid, $gf_num, $filters, $rec_type, $rec_vars );
				}
				$gf_recId = $gf_result[0];
				$gf_products = $gf_result[1];
				// unique set of products
				$gf_products = array_unique( $gf_products );
				if ( sizeof( $gf_products ) == 0 ) {
					$GLOBALS['wc_graphflow']->get_api()->log->add(
						"graphflow",
						"No email recommendations for user: " . $gf_userid . " available; request: " . $_SERVER['REQUEST_URI'] );
					return "";
				}
				// create coupon from args
				$coupon_args['coupon_code'] = $gf_recId;
				if ( $is_fue ) {
					$coupon = array();
				} else {
					$coupon = $this->create_coupon( $coupon_args, false );
				}
				// log that a coupon was sent with this email recommendation
				if ( !empty( $coupon ) ) {
					$wc_coupon = new WC_Coupon( $coupon['code'] );
					if ( $wc_coupon->exists ) {
						woocommerce_graphflow_post_event( $gf_userid, array( array (
							'gf_action'           => 'coupon_sent',
							'gf_rec_id'           => $wc_coupon->code,
							'gf_product_id'       => '',
							'gf_interaction_data' => $GLOBALS['wc_graphflow']->extract_coupon_data( $wc_coupon )
						) ) );
					} else {
						$GLOBALS['wc_graphflow']->get_api()->log->add(
							"graphflow",
							"Error in coupon generation for code: " . $gf_recId. "; request: " . $_SERVER['REQUEST_URI'] );
					}
				}
			}

			ob_start();
			wc_get_template( 'email/graphflow-recommended-products.php', array(
				'gf_products'	 => $gf_products,
				'gf_userid'      => $gf_userid,
				'gf_rows'		 => $gf_rows,
				'gf_columns'     => $gf_columns,
				'gf_title'		 => $gf_title,
				'gf_desc'        => $gf_desc,
				'gf_recId'		 => $gf_recId,
				'email_class'    => $email_class,
				'coupon'         => $coupon,
				'is_fue'         => $is_fue
			), '', untrailingslashit( plugin_dir_path( WC_Graphflow::$file ) ) . '/templates/'
			);
			return ob_get_clean();
		}

		public function order_email_preview_insert_recs( $order, $sent_to_admin = false, $plain_text = false ) {
			// set up other parameters
			$gf_title = get_option( 'woocommerce_graphflow_email_title', 'Recommended for you' );
			$gf_desc = get_option( 'woocommerce_graphflow_email_desc', 'Based on your recent activity, we thought you may be interested in these products.' );
			$gf_rows = get_option( 'woocommerce_graphflow_email_rows', 1 );
			$gf_columns = get_option( 'woocommerce_graphflow_email_cols', 3 );
			$coupon_args = get_option( 'woocommerce_graphflow_email_coupon', $this->coupon_defaults() );
			// generate email section
			$email_section = $this->generate_email_rec_section( '', $gf_title, $gf_desc, $gf_rows, $gf_columns, '', 'email_order', true, $coupon_args );
			echo $email_section;
		}

		/**
		 * @param $order WC_Order
		 * @param bool $sent_to_admin
		 * @param bool $plain_text
		 */
		public function order_email_insert_recs( $order, $sent_to_admin = false, $plain_text = false ) {
			// don't generate product recommendations in admin or plain text emails
			if ( !$order || $sent_to_admin || $plain_text ) {
				return;
			}
			// bail out if turned off
			if ( get_option( 'woocommerce_graphflow_email_active', 'no' ) != 'yes' ) {
				return;
			}
			// check order status to see if the email should be sent
			$status_options = get_option( 'woocommerce_graphflow_email_types', array( 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed' ) );
			$order_status = 'wc-' . $order->get_status();
			if ( !in_array( $order_status, $status_options ) ) {
				return;
			}
			// populate user id
			$gf_userid = $order->get_user_id();
			if ( $gf_userid == 0 ) {
				$gf_userid = $order->billing_email;
			}
			// check order items
			$order_items = array();
			foreach ( $order->get_items() as $order_item_id => $order_item ) {
				// check if we can get the product, log an error message if not.
				$product = $order->get_product_from_item( $order_item );
				if ( ! $product ) {
					$GLOBALS['wc_graphflow']->get_api()->log->add(
						"graphflow",
						"Failed to get_product_from_item for id: " . $order_item['product_id'] . " during email recommendations for order_id: " . $order->id . "; request: " . $_SERVER['REQUEST_URI'] );
					continue;
				}
				$order_items[] = $order_item['product_id'];
			}
			// set up filter
			$filters = !empty( $order_items ) ? '_id=NOT(' . implode( " ", $order_items ) . ')' : '';
			// set up other parameters
			$gf_title = get_option( 'woocommerce_graphflow_email_title', 'Recommended for you' );
			$gf_desc = get_option( 'woocommerce_graphflow_email_desc', 'Based on your recent activity, we thought you may be interested in these products.' );
			$gf_rows = get_option( 'woocommerce_graphflow_email_rows', 1 );
			$gf_columns = get_option( 'woocommerce_graphflow_email_cols', 3 );
			// coupon
			$coupon_args = get_option( 'woocommerce_graphflow_email_coupon', $this->coupon_defaults() );
			$coupon_args['customer_email'] = $order->billing_email;

			$email_section = $this->generate_email_rec_section( $gf_userid, $gf_title, $gf_desc, $gf_rows, $gf_columns, $filters, 'email_order', false, $coupon_args );
			echo $email_section;

		}

		// ==== Email Styles ==== //

		public function graphflow_email_styles_template() {
			ob_start();
			wc_get_template( 'email/graphflow-email-styles.php', array( ), '', untrailingslashit( plugin_dir_path( WC_Graphflow::$file ) ) . '/templates/' );
			return ob_get_clean();
		}

		public function graphflow_email_styles_followup_template() {
			ob_start();
			wc_get_template( 'email/graphflow-email-styles-followup.php', array( ), '', untrailingslashit( plugin_dir_path( WC_Graphflow::$file ) ) . '/templates/' );
			return ob_get_clean();
		}

		/**
		 * @param $styles
		 * @return string
		 */
		public function graphflow_email_styles( $styles ) {
			$graphflow_styles = get_option( 'woocommerce_graphflow_email_css', $this->graphflow_email_styles_template() );
			$graphflow_styles_followup = get_option( 'woocommerce_graphflow_email_followup_css', $this->graphflow_email_styles_followup_template() );

			$styles .= PHP_EOL;
			$styles .= $graphflow_styles;
			$styles .= PHP_EOL;
			$styles .= $graphflow_styles_followup;

			return $styles;
		}

		public function graphflow_email_styles_fue( $styles ) {
			$graphflow_styles_followup = get_option( 'woocommerce_graphflow_email_followup_css', $this->graphflow_email_styles_followup_template() );
			$styles .= $graphflow_styles_followup;
			return $styles;
		}
	}
}
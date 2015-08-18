<?php

/**
 * Main GraphFlow class, handles all the hooks to integrate with GraphFlow
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'WC_Graphflow' ) ) {
	class WC_Graphflow {
		protected $api;

		protected $emails;

		public static $file;

		/**
		 * Constructor
		 *
		 * @param string $file
		 */
		public function __construct( $file ) {
			self::$file = $file;
			$this->includes();
			$this->get_emails();

			// Admin specific actions
			if ( is_admin() ) {
				// Export product on save
				add_action( 'save_post', array( $this, 'capture_product_save' ), 20, 2 );

				// Export product on quick edit save
				add_action( 'woocommerce_product_quick_edit_save', array( $this, 'capture_product_bulk_or_quick_edit_save' ), 20, 1 );

				// Export product on bulk edit save
				add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'capture_product_bulk_or_quick_edit_save' ), 20, 1 );

				// Delete product on delete
				add_action( 'delete_post', array( $this, 'capture_product_delete' ), 10, 1 );
			}

			add_filter( 'woocommerce_product_add_to_cart_url', array( $this, 'add_to_cart_url_filter' ), 10, 2 );
			
			// Add AJAX recommendation actions
			add_action( 'wp_ajax_nopriv_graphflow_get_recs',  array( $this, 'woocommerce_graphflow_recommendation_display_ajax' ) );
			add_action( 'wp_ajax_graphflow_get_recs',  array( $this, 'woocommerce_graphflow_recommendation_display_ajax' ) );

			// Add product to API on page view event in case it hasn't been exported already
			add_action( 'shutdown', array( $this, 'capture_product_on_view_event' ) );

			// Capture cart events
			add_action( 'woocommerce_add_to_cart', array( $this, 'capture_add_to_cart' ), 10, 6 );
			// Remove from cart events
			add_action( 'woocommerce_before_cart_item_quantity_zero', array( $this, 'capture_before_cart_item_zero' ), 10, 1 );
			add_action( 'woocommerce_cart_item_removed', array( $this, 'capture_remove_from_cart_event' ), 10, 2 );
			// Restore to cart
			add_action( 'woocommerce_cart_item_restored', array( $this, 'capture_restore_to_cart_event' ), 10, 2 );
			// Cart quanity update events
			add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'capture_cart_item_quantity_update' ), 10, 3 );
			// Capture empty cart
			add_action( 'woocommerce_cart_emptied', array( $this, 'clear_cart_session_id' ) );

			// Track checkout actions
			add_action( 'woocommerce_thankyou', array( $this, 'capture_live_order' ), 10, 1 );

			// Track rec ids in orders
			add_action( 'woocommerce_add_order_item_meta', array( $this, 'convert_item_session_to_order_meta' ), 10, 3 );

			// Load Textdomain
			add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

			// Export customer when they register
			add_action( 'woocommerce_created_customer', array( $this, 'capture_customer' ), 10, 3 );

			// Export customers that are not already exported on page loads
			add_action( 'shutdown', array( $this, 'maybe_capture_customer' ) );

			// Enqueue Scripts in Header
			add_action( 'wp_enqueue_scripts', array( $this, 'register_header_scripts' ), 1 );

			// Enqueue Script in Footer
			add_action( 'wp_enqueue_scripts', array( $this, 'register_footer_scripts' ), 10 );

			// Register user alias when logging in.
			add_action( 'wp_login', array( $this, 'register_user_alias' ), 10, 2 );

			// Add widgets
			add_action( 'widgets_init', array( $this, 'include_widgets' ), 11 );

			// Register hooks for displaying on product/cart pages
			add_action( 'plugins_loaded', array( $this, 'display_recommendations_based_on_settings' ) );

			// Update product availability based on stock status
			add_action( 'woocommerce_product_set_stock_status', array( $this, 'update_product_based_on_stock_status' ), 10, 2 );

			// Register shortcode
			add_shortcode( 'graphflow_recommendations', array( $this, 'recommendations_shortcode' ) );

			// add rec id to post permalinks
			add_filter( 'post_type_link', array( $this, 'add_rec_id_to_url' ), 99, 4 );

			// add rec id to add-to-cart links
			add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'add_rec_id_to_add_to_cart_url' ), 10, 2 );
		}

		//== GENERAL FUNCTIONS ==//

		public function get_emails() {
			if ( is_object( $this->emails ) ) {
				return $this->emails;
			}
			// Add email recommendations class
			require 'class-wc-graphflow-emails.php';
			return $this->emails = new WC_Graphflow_Emails();
		}

		/**
		 * Return the API object
		 *
		 * @return object WC_GraphFlow_API object
		 */
		public function get_api() {
			if ( is_object( $this->api ) ) {
				return $this->api;
			}

			require 'class-wc-graphflow-api.php';
			$client_key = get_option( 'woocommerce_graphflow_client_key' );
			$api_key = get_option( 'woocommerce_graphflow_api_key' );

			return $this->api = new WC_GraphFlow_API( $client_key, $api_key );
		}

		/**
		 * Include files
		 * @return void
		 */
		public function includes() {
			include 'graphflow-admin-functions.php';
			include 'graphflow-template-functions.php';
		}

		/**
		 * Load the textdomain for translation
		 * @return void
		 */
		public function load_textdomain() {
			load_plugin_textdomain( 'wp-graphflow', false, dirname( plugin_basename( self::$file ) ) . '/languages/' );
		}

		/**
		 * @param string $time
		 * @return float
		 */
		private function get_timestamp_utc( $time = 'now' ) {
			if ( $time == 'now' ) {
				$timestamp_utc = time();
			} else {
				$date_time = new DateTime( $time, new DateTimeZone('UTC') );
				$timestamp_utc = $date_time->getTimestamp();
			}
			// convert the timestamp in sec to ms (using float cast avoid any issues with int on 32-bit systems)
			$timestamp_utc_ms = (float) $timestamp_utc  * 1000;
			return $timestamp_utc_ms;
		}

		/**
		 * @param $post_link
		 * @param WP_Post $post
		 * @param $leavename
		 * @param $sample
		 * @return string
		 */
		public function add_rec_id_to_url( $post_link, $post, $leavename, $sample ) {
			if ( $sample ) return $post_link;
			return $this->maybe_add_rec_id( $post_link );
		}

		/**
		 * Add rec id to cart URL
		 * @param $html
		 * @param $product
		 * @return mixed
		 */
		public function add_rec_id_to_add_to_cart_url( $html, $product ) {
			if ( isset( $_REQUEST['gf_set_rec_id'] ) ) {
				$count = 1;
				$html =  str_replace( 'data-product_id=', 'data-gf_rec_id="' . $_REQUEST['gf_set_rec_id']  . '" data-product_id=', $html, $count );
			}
			return $html;
		}

		/**
		 * Add rec id to recommended product and add-to-cart links
		 * @param $url
		 * @return string
		 */
		public function maybe_add_rec_id( $url ) {
			// add the rec id if it exists
			if ( isset( $_REQUEST['gf_set_rec_id'] ) ) {
				$url = add_query_arg( array( 'gf_rec_id' => $_REQUEST['gf_set_rec_id'] ), $url );
			}
			return esc_url( $url );
		}

		// ==== ADD-TO-CART FUNCTIONS ==== //

		/**
		 * If AJAX add-to-cart buttons are disabled and AJAX is being used, construct the add-to-cart URL for the product
		 * based on the referring page
		 * @return string
		 */
		public function add_to_cart_url_filter( $url, $product ) {
			if ( defined( 'DOING_AJAX' ) &&
				get_option( 'woocommerce_graphflow_use_ajax', 'yes' ) == 'yes' && 
				get_option( 'woocommerce_enable_ajax_add_to_cart' ) == 'no' && 
				$_POST['action'] == 'graphflow_get_recs' &&
				$product instanceof WC_Product_Simple ) {
				$url = $_SERVER['HTTP_REFERER'];
				$url = $product->is_purchasable() && $product->is_in_stock() ? remove_query_arg( 'added-to-cart', add_query_arg( 'add-to-cart', $product->id, $url ) ) : get_permalink( $product->id );
			}
			return $this->maybe_add_rec_id( $url );
		}

		/**
		 * @param $cart_item_key
		 * @param $product_id
		 * @param $quantity
		 * @param $variation_id
		 * @param $variation
		 * @param $cart_item_data
		 */
		public function capture_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
			$this->capture_event_in_session_data( $product_id, $quantity, 'addtobasket', $cart_item_key );
		}

		/**
		 * @return string
		 */
		public function generate_cart_session_id() {
			require_once( ABSPATH . 'wp-includes/class-phpass.php');
			$hasher = new PasswordHash( 8, false );
			return md5( $hasher->get_random_bytes( 32 ) );
		}

		/**
		 * @return mixed|string
		 */
		public function get_cart_session_id() {
			// check for existing Graphflow cart id
			$cart_id = WC()->session->get( 'graphflow_addtocart_session_id' );
			if ( !isset( $cart_id) || empty( $cart_id ) ) {
				$cart_id = $this->generate_cart_session_id();
				WC()->session->set( 'graphflow_addtocart_session_id', $cart_id );
			}
			return $cart_id;
		}

		/**
		 *
		 */
		public function clear_cart_session_id() {
			WC()->session->set( 'graphflow_addtocart_session_id', '' );
		}

		/**
		 * @param $cart_item_key
		 * @param null $key
		 * @param null $default
		 * @return null
		 */
		public function get_cart_item_data( $cart_item_key, $key = null, $default = null ) {
			$data = (array) WC()->session->get( '_graphflow_addtocart_session_order_data' );
			if ( empty($data[$cart_item_key]) ) $data[$cart_item_key] = array();

			// If no key specified, return an array of all results.
			if ( $key == null ) return $data[$cart_item_key] ? $data[$cart_item_key] : $default;
			else return empty($data[$cart_item_key][$key]) ? $default : $data[$cart_item_key][$key];
		}

		/**
		 * @param $cart_item_key
		 * @param $key
		 * @param $value
		 */
		public function set_cart_item_data( $cart_item_key, $key, $value ) {
			$data = (array) WC()->session->get( '_graphflow_addtocart_session_order_data' );
			if ( empty($data[$cart_item_key]) ) $data[$cart_item_key] = array();

			$data[$cart_item_key][$key] = $value;

			WC()->session->set( '_graphflow_addtocart_session_order_data', $data );
		}

		/**
		 * @param $item_id
		 * @param $values
		 * @param $cart_item_key
		 */
		public function convert_item_session_to_order_meta( $item_id, $values, $cart_item_key ) {
			// Occurs during checkout, item data is automatically converted to order item metadata, stored under the "_graphflow_addtocart_session_order_data"
			$order_product_data = array();

			$cart_item_data = $this->get_cart_item_data( $cart_item_key );

			// Add the array of all meta data to "_graphflow_addtocart_session_order_data". These are hidden, and cannot be seen or changed in the admin.
			if ( !empty($cart_item_data) ) wc_add_order_item_meta( $item_id, '_graphflow_rec_id', $cart_item_data );
		}

		/**
		 * @param $product_id
		 * @param $quantity
		 * @param $action
		 * @param string $cart_item_key
		 * @param array $event_data
		 * @param string $rec_id
		 */
		public function capture_event_in_session_data(
			$product_id,
			$quantity,
			$action,
			$cart_item_key = '',
			$event_data = array( ),
			$rec_id = '' ) {

			$product = get_product ( $product_id );
			if ( ! $product ) {
				$this->get_api()->log->add("graphflow", "Failed to get_product for product id: " . $product_id . " for add-to-cart request: " . $_SERVER['REQUEST_URI'] );
				return;
			}

			// check for existing data
			$data = WC()->session->get( 'graphflow_addtocart_session_data' );
			if ( empty( $data ) || !is_array( $data ) ) {
				$data = array();
			}
			if ( $quantity == 0 )  {
				$this->get_api()->log->add("graphflow", "Zero quantity for product id: " . $product_id . " for add-to-cart or purchase request: " . $_SERVER['REQUEST_URI'] );
				return;
			}

			// maybe add rec id
			$rec_id_array = array();
			if ( isset( $_POST['gf_rec_id'] ) ) {
				$rec_id = $_POST['gf_rec_id'];
				$rec_id_array = array( 'gf_rec_id' => $rec_id);
			} else if ( isset( $_REQUEST['gf_rec_id'] ) ) {
				$rec_id = $_REQUEST['gf_rec_id'];
				$rec_id_array = array( 'gf_rec_id' => $rec_id);
			}
			// extract new event data
			$new_data = array(
				'gf_action'             => $action,
				'gf_product_id'         => (string) $product_id,
				'gf_qty'                => (string) $quantity,
				'gf_product_price'      => (string) $product->get_price(),
				'gf_timestamp'          => $this->get_timestamp_utc(),
				'gf_interaction_data'   => array_merge(
					array( 'wc_cart_id' => $this->get_cart_session_id() ),
					$rec_id_array,
					$event_data
				)
			);

			// set recid and cart id in cart metadata
			if ( !empty( $cart_item_key ) ) {
				$this->set_cart_item_data( $cart_item_key, 'wc_cart_id', $this->get_cart_session_id() );
				if ( !empty( $rec_id ) ) {
					$new_data['gf_rec_id'] = $rec_id;
					$this->set_cart_item_data( $cart_item_key, 'gf_rec_id', $rec_id );
				}
			}
			// append new data
			$data[] = $new_data;

			WC()->session->set( 'graphflow_addtocart_session_data', $data );
		}

		/**
		 * @param $cart_item_key
		 */
		public function capture_before_cart_item_zero( $cart_item_key ) {
			$cart = WC()->cart->cart_contents;
			$cart_item = $cart[$cart_item_key];
			$product = get_product( $cart_item['product_id'] );
			$old_quantity = (int) $cart_item['quantity'];
			$this->capture_event_in_session_data( $product->id, -$old_quantity, 'removefrombasket', $cart_item_key );
		}

		/**
		 * @param $cart_item_key
		 * @param WC_Cart $cart
		 */
		public function capture_remove_from_cart_event( $cart_item_key, $cart ) {
			$cart_item = $cart->removed_cart_contents[$cart_item_key];
			$product = get_product( $cart_item['product_id'] );
			$old_quantity = (int) $cart_item['quantity'];
			$this->capture_event_in_session_data( $product->id, -$old_quantity, 'removefrombasket', $cart_item_key );
		}

		/**
		 * @param $cart_item_key
		 * @param WC_Cart $cart
		 */
		public function capture_restore_to_cart_event( $cart_item_key, $cart ) {
			$cart_item = $cart->cart_contents[$cart_item_key];
			$product = get_product( $cart_item['product_id'] );
			$quantity = (int) $cart_item['quantity'];
			$this->capture_event_in_session_data( $product->id, $quantity, 'addtobasket', $cart_item_key );
		}

		/**
		 * @param $cart_item_key
		 * @param $quantity
		 * @param $old_quantity
		 */
		public function capture_cart_item_quantity_update( $cart_item_key, $quantity, $old_quantity ) {
			$cart = WC()->cart->cart_contents;
			$cart_item = $cart[$cart_item_key];
			$product = get_product( $cart_item['product_id'] );
			$diff_quantity = $quantity - $old_quantity;
			$action = $diff_quantity < 0 ? 'removefrombasket' : 'addtobasket';
			$this->capture_event_in_session_data( $product->id, $diff_quantity, $action, $cart_item_key );
		}

		//== PRODUCT DATA EXPORT ==//

		/**
		 * Capture product details on view event, if not already captured
		 * @return void
		 */
		public function capture_product_on_view_event() {
			if ( is_product() ) {
				// ignore RSS feed requests
				if ( is_feed() ) {
					return;
				}
				global $post;
				if ( ! $post ) {
					$this->get_api()->log->add("graphflow", "Failed to get post global for product page view request: " . $_SERVER['REQUEST_URI'] );
					return;
				}
				$this->maybe_capture_product( $post->ID );
			}
		}

		/**
		 * Add product to Graphflow API if not aleady captured
		 * @param $product_id
		 * @internal param Product $product
		 */
		public function maybe_capture_product( $product_id ) {
			$product = get_product( $product_id );
			if ( ! $product ) {
				$this->get_api()->log->add("graphflow", "Failed to capture product id: " . $product_id . " for request: " . $_SERVER['REQUEST_URI'] );
				return;
			}
			// Capture product if not already captured.
			if ( 'yes' !== get_post_meta( $product->id, '_wc_graphflow_exported', true ) ) {
				$this->capture_product( $product );
			}
		}

		/**
		 * Extract product details and export to Graphflow API
		 * @param  WC_Product $product
		 * @return void
		 */
		public function capture_product( $product ) {
			// don't capture anything except published products
			if ( $product->post->post_status !== 'publish' ) {
				return;
			}
			$product_data = $this->extract_product_data( $product );
			$this->capture_products( array ( $product_data ) );
		}

		/**
		 * Bulk export products to Graphflow API
		 * @param array $products
		 * @internal param Product $product
		 */
		public function capture_products( $products ) {
			$this->get_api()->update_items( apply_filters( 'woocommerce_graphflow_product_data', $products ) );
			foreach ( $products as $product ) {
				update_post_meta( $product['itemId'], '_wc_graphflow_exported', 'yes' );
			}
		}

		/**
		 * @param WC_Product $product
		 * @return array
		 */
		public function extract_product_data( $product ) {
			$product_data = array(
				'name'				=> $product->get_title(),
				'regular_price' 	=> floatval( $product->regular_price ),
				'sale_price'		=> floatval( $product->sale_price ),
				'sku'				=> $product->get_sku(),
				'url'				=> $product->get_permalink(),
				'product_cat'		=> '[' . implode( '|', wp_get_object_terms( $product->id, 'product_cat', array( 'fields' => 'names' ) ) ) . ']',
				'product_cat_ids'	=> '[' . implode( '|', wp_get_object_terms( $product->id, 'product_cat', array( 'fields' => 'ids' ) ) ) . ']',
				'product_tag'		=> '[' . implode( '|', wp_get_object_terms( $product->id, 'product_tag', array( 'fields' => 'names' ) ) ) . ']',
				'product_tag_ids'	=> '[' . implode( '|', wp_get_object_terms( $product->id, 'product_tag', array( 'fields' => 'ids' ) ) ) . ']',
				'image_url'			=> $this->get_product_images( $product ),
				'description'		=> $product->post->post_content,
				'excerpt'           => $product->post->post_excerpt,
				'post_type'         => get_post_type( $product->id ),
				'is_on_sale'        => $product->is_on_sale() ? 'yes' : 'no'
			);
			$instock = $product->is_in_stock();
			// if the product is not visible or not published (ie private etc), mark it as 'inactive'
			if ( !$product->is_visible() || $product->post->post_status !== 'publish' ) {
				$instock = false;
			}
			$item = array(
				'itemId' 	=> (string) $product->id,
				'itemData'  => $product_data,
				'active' 	=> $instock,
			);
			return $item;
		}

		/**
		 * Get a CSV list of product image urls
		 * @param  object $product
		 * @return string
		 */
		private function get_product_images( $product ) {
			$img_urls = array();
			if ( has_post_thumbnail( $product->id ) ) {
				$img_urls[] = wp_get_attachment_url( get_post_thumbnail_id( $product->id ) );
			}

			$attachment_ids = $product->get_gallery_attachment_ids();
			if ( $attachment_ids ) {
				foreach ( $attachment_ids as $attachment_id ) {
					$img_urls[] = wp_get_attachment_url( $attachment_id );
				}
			}

			return implode( ',', $img_urls );
		}

		/**
		 * Update stock status on graphflow when product stock changes.
		 * @param  int $product_id
		 * @param  string $status
		 * @return void
		 */
		public function update_product_based_on_stock_status( $product_id, $status ) {
			switch ( $status ) {
				case 'outofstock':
					$this->get_api()->toggle_item( $product_id, false );
					break;
				case 'instock':
					$this->get_api()->toggle_item( $product_id, true );
					break;
			}
		}

		public function capture_product_bulk_or_quick_edit_save( $product ) {
			if ( ! $product ) {
				$this->get_api()->log->add( "graphflow", "Null product during capture_product_bulk_edit_save" );
				return;
			}
			$this->capture_product( $product );
		}

		/**
		 * Capture and log when a product is updated or added
		 *
		 * @param  int $post_id
		 * @param  object $post
		 * @return void
		 */
		public function capture_product_save( $post_id, $post ) {
			if ( ! $_POST || is_int( wp_is_post_revision( $post_id ) ) || is_int( wp_is_post_autosave( $post_id ) ) ) return $post_id;
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return $post_id;
			if ( $post->post_type != 'product' ) return $post_id;

			$product = get_product( $post_id );

			if ( ! $product ) {
				$this->get_api()->log->add( "graphflow", "Failed to get_product for id: " . $post_id . " during capture_product_save" );
				return $post_id;
			}

			$this->capture_product( $product );
		}

		/**
		 * Capture and log when a product is deleted
		 *
		 * @param  int $post_id
		 * @return void
		 */
		public function capture_product_delete( $post_id ) {
			global $post_type;
			if ( $post_type != 'product' ) return;
			if ( ! current_user_can( 'delete_posts', $post_id ) ) return;

			$this->get_api()->delete_item( $post_id );
		}

		/**
		 * Send all products in your store to Graphflow
		 *
		 * @return int
		 */
		public function capture_all_products() {
			// set 3 minute time limit for export
			@set_time_limit(180);
			// Get all products, paged for memory efficiency
			$current_page = 1;
			$finished = false;
			$total_products_exported = 0;
			while ( !$finished ) {
				$query_args = array(
					'posts_per_page' => 50,
					//'post_status' 	 => 'publish',
					'post_type' 	 => 'product',
					'paged'			 => $current_page,
				);
				$query = new WP_Query( $query_args );
				if ( $current_page >= $query->max_num_pages ) {
					$finished = true;
				}
				$products = array();
				while ( $query->have_posts() ) {
					$query->the_post();
					$product = get_product( get_the_ID() );
					if ( ! $product ) {
						$this->get_api()->log->add(
							"graphflow",
							"Failed to get_product for id: " . get_the_ID() . " during product export" );
						continue;
					}
					if ( 'yes' === get_post_meta( $product->id, '_wc_graphflow_exported', true ) && $product->post->post_status !== 'publish' ) {
						// for old plugin versions, if we previously exported non-published products, mark them as inactive here.
						$this->get_api()->toggle_item( $product->id, false );
					} else {
						// otherwise export data
						$product_data = $this->extract_product_data( $product );
						$products[] = $product_data;
					}
				}
				$this->capture_products( $products );
				$current_page = $current_page + 1;
				$total_products_exported = $total_products_exported + count( $products );
			}
			return absint ( $total_products_exported );
		}

		// ==== ORDER DATA EXPORT ==== //

		/**
		 * Extract order user
		 * @param WC_Order $order
		 * @return mixed
		 */
		public function extract_order_user( $order ) {
			// extract the user id for the order
			$order_user = $order->customer_user;
			if ( $order_user == 0 ) {
				$order_user = $order->billing_email;
			}
			return $order_user;
		}

		/**
		 * Extract order status depending on WC version
		 * @param $order
		 * @return mixed
		 */
		public function extract_order_status( $order ) {
			// extract order status, different for WC 2.1 vs 2.2
			if ( defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, '2.2', '>=' ) ) {
				$order_status = $order->get_status();
			} else {
				$order_status = $order->status;
			}
			return $order_status;
		}

		/**
		 * @param $order_id
		 * @return WC_Order
		 */
		public function get_order( $order_id ) {
			// extract order status, different for WC 2.1 vs 2.2
			if ( defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, '2.2', '>=' ) ) {
				$order = wc_get_order( $order_id );
			} else {
				$order = new WC_Order( $order_id );
			}
			return $order;

		}

		/**
		 * @param WC_Order $order
		 * @return array
		 */
		public function extract_order_data( $order ) {
			// set up order data
			$order_data = array();

			// check for Graphflow coupons
			$gf_coupon_code = '';
			$gf_coupon_data = $this->check_for_coupon( $order );
			if ( !empty( $gf_coupon_data ) ) {
				$gf_coupon_code = $gf_coupon_data['code'];
			}

			// extract order attributes
			$order_currency = $order->get_order_currency();
			$order_id = $order->id;
			$customer_ip_address = $order->customer_ip_address;
			$customer_user_agent = $order->customer_user_agent;
			$order_status = $this->extract_order_status( $order );
			$order_user = $this->extract_order_user( $order );

			// extract purchase event details for each order item
			foreach ( $order->get_items() as $order_item_id => $order_item ) {
				// check if we can get the product, log an error message if not.
				$product = $order->get_product_from_item( $order_item );
				if ( ! $product ) {
					$this->get_api()->log->add(
						"graphflow",
						"Failed to get_product_from_item for id: " . $order_item['product_id'] . " during order export for order_id: " . $order->id . "; request: " . $_SERVER['REQUEST_URI'] );
					continue;
				}

				// only capture the base product (not variation if it is a product variation)
				$this->maybe_capture_product ( $order_item['product_id'] );

				// set order timestamp in UTC
				$timestamp_utc_ms = $this->get_timestamp_utc( $order->post->post_date_gmt );

				// get totals
				$line_total = $order->get_line_total( $order_item );		// line item total after discount
				$line_subtotal = $order->get_line_subtotal( $order_item );  // line item total before discount
				$item_total = $order->get_item_total( $order_item );        // product total after discount
				$item_subtotal = $order->get_item_subtotal( $order_item );  // product total before discount
				$product_price = $product->get_price();						// product (or variation) price, excl add-ons, excl discount, excl taxes

				$event_data = array(
					'order_currency'    => $order_currency,
					'transactionId'     => $order_id,
					'remoteAddr'        => $customer_ip_address,
					'uaRaw'             => $customer_user_agent,
					'order_status'      => $order_status,
					'item_total'        => $item_total,
					'item_subtotal'     => $item_subtotal,
					'line_total'        => $line_total,
					'line_subtotal'     => $line_subtotal,
					'product_price'     => $product_price
				);
				// check for custom data - rec id and cart id
				$custom_data = (array) wc_get_order_item_meta( $order_item_id, '_graphflow_rec_id' );
				// if a cart id is present add it
				if ( !empty( $custom_data) && !empty( $custom_data['wc_cart_id'] ) ) {
					$event_data['wc_cart_id'] = $custom_data['wc_cart_id'];
				}
				// if the purchase came directly from a recId add it
				if ( !empty( $custom_data ) && !empty( $custom_data['gf_rec_id'] ) ) {
					$event_data['gf_rec_id'] = $custom_data['gf_rec_id'];
				}
				// if a Graphflow coupon was used, allocate the purchase event to that recId - override any previous recId
				if ( !empty( $gf_coupon_code ) ) {
					$event_data['gf_rec_id'] = $gf_coupon_code;
				}
				$graphflow_order_item = array(
					'fromId'            => (string) $order_user,
					'toId'              => (string) $order_item['product_id'],
					'interactionType'   => 'purchase',
					'price'             => (string) $item_total,			// use item total to get overall actual price for this product per 1 quantity
					'quantity'          => (string) $order_item['qty'],
					'timestamp'         => $timestamp_utc_ms,
					'interactionData'   => $event_data
				);
				// allocate the recId in top-level also for tracking purposes
				if ( !empty( $event_data['gf_rec_id'] ) ) {
					$graphflow_order_item['gf_rec_id'] = $event_data['gf_rec_id'];
				}
				$order_data[] = $graphflow_order_item;
			}
			return $order_data;
		}

		public function capture_customer_from_live_order( $order ) {
			// capture customer data
			$customer_data = $this->extract_customer_data_from_order( $order );
			$this->capture_customers( array( $customer_data ) );
			// Set alias for live orders
			$temp_id = $this->get_temp_user_id();
			if ( empty( $temp_id ) ) {
				$this->get_api()->log->add(
					"graphflow",
					"gf_anon temp id empty for alias call. Request: " . $_SERVER['REQUEST_URI']
				);
			} else {
				$order_user = $this->extract_order_user( $order );
				$this->get_api()->add_user_alias( $order_user, $temp_id );
				if ( is_user_logged_in() ) {
					$this->get_api()->add_user_alias( $order_user, $order->billing_email );
				}
			}
		}

		/**
		 * @param WC_coupon $wc_coupon
		 * @return array
		 */
		public function extract_coupon_data( $wc_coupon ) {
			$expiry_day = gmstrftime( '%Y-%m-%d', (int) $wc_coupon->expiry_date );
			return array (
				'wc_coupon_code'        => $wc_coupon->code,
				'wc_coupon_type'        => $wc_coupon->discount_type,
				'wc_coupon_amount'      => $wc_coupon->coupon_amount,
				'wc_coupon_max_amount'  => $wc_coupon->maximum_amount,
				'wc_coupon_min_amount'  => $wc_coupon->minimum_amount,
				'wc_coupon_expiry'      => $expiry_day,
				'wc_coupon_email'       => $wc_coupon->customer_email != null ? join( '|',  $wc_coupon->customer_email ) : ''
			);
		}

		/**
		 * Check for a Graphflow coupon in the order. Auto-generated coupons are assumed single use
		 * @param WC_Order $order
		 * @return array
		 */
		public function check_for_coupon( $order ) {
			$coupons = $order->get_used_coupons();
			foreach ( $coupons as $coupon ) {
				$wc_coupon = new WC_Coupon( $coupon );
				// if a Graphflow coupon exists, return teh event data for coupon used, and the coupon code
				if ( get_post_meta( $wc_coupon->id, '_graphflow', true ) == 'yes' ) {
					$data = array(
						'gf_action'             => 'coupon_used',
						'gf_timestamp'          => $this->get_timestamp_utc( $order->post->post_date_gmt ),
						'gf_rec_id'             => $coupon,
						'gf_product_id'         => '',
						'gf_interaction_data'   => $this->extract_coupon_data( $wc_coupon )
					);
					return array( 'code' => $wc_coupon->code, 'data' => $data );
				}
			}
			return array();
		}

		/**
		 * Export order to Graphflow when customer completes checkout
		 * @param $order_id
		 */
		public function capture_live_order( $order_id ) {
			$order = $this->get_order( $order_id );
			if ( ! $order ) {
				$this->get_api()->log->add("graphflow", "Failed to get order for order_id: " . $order_id . "; request: " . $_SERVER['REQUEST_URI'] );
				return;
			}
			if ( 'yes' === get_post_meta( $order_id, '_wc_graphflow_exported', true ) ) {
				return;
			}
			$order_user = $this->extract_order_user( $order );
			$order_data = $this->extract_order_data( $order );
			$order_events = array();

			// check for coupon used event
			$gf_coupon_data = $this->check_for_coupon( $order );
			if ( !empty( $gf_coupon_data ) ) {
				$gf_coupon_event = $gf_coupon_data['data'];
				$order_events[] = $gf_coupon_event;
			}

			// extract data into gf_events
			foreach( $order_data as $order_event ) {
				$order_events[] = array(
					'gf_product_id'         => $order_event['toId'],
					'gf_qty'                => $order_event['quantity'] ,
					'gf_product_price'      => $order_event['price'],
					'gf_action'             => $order_event['interactionType'],
					'gf_timestamp'          => $order_event['timestamp'],
					'gf_rec_id'             => isset( $order_event['gf_rec_id'] ) ? $order_event['gf_rec_id'] : '',
					'gf_interaction_data'   => $order_event['interactionData']
				);
			}
			wc_enqueue_js('gf_params.gf_userid = "' . $order_user . '";');
			wc_enqueue_js('graphflow.userid(gf_params.gf_userid);');
			wc_enqueue_js("gf_params.gf_events = " . json_encode( $order_events ) . ";");
			wc_enqueue_js("graphflow.track('events', gf_params);");
			// capture customer
			$this->capture_customer_from_live_order( $order );
		}

		/**
		 * Export historical orders to Graphflow
		 *
		 * @param $order_ids
		 */
		public function capture_historic_orders( $order_ids ) {
			$order_data = array();
			$order_users = array();
			foreach ( $order_ids as $order_id ) {
				$exported = get_post_meta( $order_id, '_wc_graphflow_exported', true );
				if ( 'yes' !== $exported || get_option( 'woocommerce_graphflow_force_order_export', 'no' ) == 'yes' ) {
					$order = $this->get_order( $order_id );
					if ( ! $order ) {
						$this->get_api()->log->add("graphflow", "Failed to get order for order_id: " . $order_id . "; request: " . $_SERVER['REQUEST_URI'] );
						continue;
					}
					$order_data = array_merge( $this->extract_order_data( $order ), $order_data );
					$order_user = $this->extract_order_user( $order );
					$order_users[$order_user] = $this->extract_customer_data_from_order( $order );
				}
			}
			// export order data to API
			if ( !empty( $order_data ) ) {
				$this->get_api()->add_user_interactions( $order_data );
			}
			// extract unique customers for exporting
			$customers = array();
			foreach ( $order_users as $order_user => $customer_data ) {
				$customers[] = $customer_data;
			}
			// export customer data
			if ( !empty( $customers ) ) {
				$this->capture_customers( $customers );
			}
			// update the order post meta
			foreach ( $order_ids as $order_id ) {
				// Set a meta field so we do not export again
				update_post_meta( $order_id, '_wc_graphflow_exported', 'yes' );
			}
		}

		/**
		 * Send most recent orders in your store to Graphflow
		 *
		 * @return int
		 */
		public function capture_all_orders() {
			// set a 3 minute time limit for export
			@set_time_limit(180);
			// WC 2.2 changes the 'post_status' mechanism so we need this version check
			if ( defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, '2.2', '>=' ) ) {
			     $post_status = array_keys( wc_get_order_statuses() );
			} else {
			     $post_status = 'publish';
			}
			$total_orders_exported = 0;
			// page 50 orders at a time for memory efficiency, up to max of selected number (default 1000)
			$max_number_orders = get_option( 'woocommerce_graphflow_max_order_export', 1000 );
			$current_page = 1;
			$max_pages = max( 1, ceil( $max_number_orders / 50 ) );
			$finished = false;
			while ( !$finished ) {
				$query_args = array(
					'posts_per_page' 	=> 50,
					'post_type' 	 	=> 'shop_order',
					'post_status' 		=> $post_status,
					'paged'				=> $current_page,
				);
				$query = new WP_Query( $query_args );
				if ( $current_page >= $query->max_num_pages || $current_page >= $max_pages ) {
					$finished = true;
				}
				$sales = array();
				while ( $query->have_posts() ) {
					$query->the_post();
					$sales[] = get_the_ID();
				}
				$this->capture_historic_orders( $sales );
				$current_page = $current_page + 1;
				$total_orders_exported = $total_orders_exported + count( $sales );
			}
			return absint ( $total_orders_exported );
		}


		// ==== CUSTOMER DATA EXPORT FUNCTIONS ==== //

		/**
		 * @param WC_Order $order
		 * @return array
		 */
		public function extract_customer_data_from_order( $order ) {
			$user = $order->get_user();
			if ( $user ) {
				return $this->extract_customer_data_from_user( $user );
			} else {
				$order_user = $this->extract_order_user( $order );
				$customer_data = array(
					'userId' => $order_user,
					'userData' => array(
						'name'			=> $order->billing_first_name. ' ' . $order->billing_last_name,
						'username' 		=> $order_user,
						'first_name'	=> $order->billing_first_name,
						'last_name' 	=> $order->billing_last_name,
						'email' 		=> $order_user,
						'company'		=> $order->billing_company,
						'address_1'		=> $order->billing_address_1,
						'address_2'		=> $order->billing_address_2,
						'city'			=> $order->billing_city,
						'state'			=> $order->billing_state,
						'postcode'		=> $order->billing_postcode,
						'country'		=> $order->billing_country,
					),
				);
				return $customer_data;
			}
		}

		/**
		 * @param WP_User $user
		 * @return array
		 */
		public function extract_customer_data_from_user( $user ) {
			$customer_data = array(
				'userId' => $user->ID,
				'userData' => array(
					'name'			=> $user->first_name . ' ' . $user->last_name,
					'username' 		=> $user->user_login,
					'first_name'	=> $user->first_name,
					'last_name' 	=> $user->last_name,
					'email' 		=> $user->user_email,
					'company'		=> $user->billing_company,
					'address_1'		=> $user->billing_address_1,
					'address_2'		=> $user->billing_address_2,
					'city'			=> $user->billing_city,
					'state'			=> $user->billing_state,
					'postcode'		=> $user->billing_postcode,
					'country'		=> $user->billing_country,
				),
			);
			return $customer_data;
		}

		/**
		 * Export customers to Graphflow
		 * @param $customers
		 */
		public function capture_customers( $customers ) {
			$this->get_api()->update_users( $customers );
			foreach ( $customers as $customer ) {
				update_user_meta( $customer['userId'], '_wc_graphflow_exported', 'yes' );
			}
		}

		/**
		 * @param $customer_id
		 * @param $data
		 * @param $pw
		 */
		public function capture_customer( $customer_id, $data, $pw ) {
			$user = get_user_by( 'id', $customer_id );
			if ( $user && $this->should_track_user( $user->ID ) ) {
				$customer_data = $this->extract_customer_data_from_user( $user );
				$this->capture_customers( array( $customer_data ) );
				$this->register_user_alias( '', $user );
			}
		}

		/**
		 * Capture customers on page loads when they have not been captured before.
		 * @return void
		 */
		public function maybe_capture_customer() {
			// Only capture logged in users
			$user = wp_get_current_user();
			if ( !$user->ID ) {
				return;
			}
			// Only capture if not already captured
			if ( 'yes' == get_user_meta( $user->ID, '_wc_graphflow_exported', true ) ) {
				return;
			}
			if ( $this->should_track_user() ) {
				$customer_data = $this->extract_customer_data_from_user( $user );
				$this->capture_customers( array( $customer_data ) );
				$this->register_user_alias( '', $user );
			}
		}

		/**
		 * Check if a user should be tracked - this is true for anonymous (not logged in) users and users that
		 * have a trackable role (default 'customer' but it is an option in Graphflow Settings)
		 *
		 * @param string $user_id
		 * @return bool
		 */
		public function should_track_user( $user_id = null ) {
			// if not logged in, track
			if ( !is_user_logged_in() ) {
				return true;
			} else {
				$user_info = get_userdata( $user_id ? $user_id : get_current_user_id() );
				// if we don't know the role, don't track by default ? (maybe we should track?)
				if ( !$user_info ) return false;
				$roles = $user_info->roles;
				$track_roles = get_option( 'woocommerce_graphflow_user_roles_track', array( 'customer', 'subscriber' ) );
				foreach ( $roles as $role ) {
					// if logged in and role is in the trackable roles option, track
					if ( in_array($role, $track_roles ) ) return true;
				}
				// if logged in but NOT a trackable role, don't track - usually for users that are Admin, Editors and other non-customer roles
				return false;
			}
		}

		/**
		 * Get the current user id
		 *
		 * @return int
		 */
		public function get_user_id() {
			if ( is_user_logged_in() ) {
				$user_id = get_current_user_id();
			} else {
				$user_id = $this->get_temp_user_id();
			}

			return $user_id;
		}

		/**
		 * Get Graphflow anonymous id if not logged in
		 * @return mixed
		 */
		public function get_temp_user_id() {
			if ( isset( $_COOKIE['gf_anon'] ) ) {
				$user_id = $_COOKIE['gf_anon'];
				$replaced = str_replace('\\"', '', $user_id);
				return $replaced;
			} else {
				// note an empty id here will cause 404 for recommendation requests.
				// and we explicitly bail out of any user 'alias' requests if this id is empty
				return "";
			}
		}

		/**
		 * Set a user alias when logging in
		 * @param  string $user_login
		 * @param  object $user
		 * @return void
		 */
		public function register_user_alias( $user_login, $user ) {
			$temp_id = $this->get_temp_user_id();
			if ( empty( $temp_id ) ) {
				$this->get_api()->log->add(
					"graphflow",
					"gf_anon temp id empty for alias call. Request: " . $_SERVER['REQUEST_URI']
				);
				return;
			}
			$this->get_api()->add_user_alias( $user->ID, $temp_id );
		}

		// ==== JS TRACKING ==== //

		/**
		 * Register JS scripts in Header
		 * @return void
		 */
		public function register_header_scripts() {
			wp_register_script( 'graphflowmin', '//cloudfront.graphflow.com/graphflow.min.js', array(), '1.0', false );
			wp_enqueue_script( 'graphflowmin' );
		}

		/**
		 * Register JS scripts in Footer
		 * @return void
		 */
		public function register_footer_scripts() {
			wp_register_script( 'gfajax', plugins_url( '/assets/js/gfajax.js', self::$file ), array( 'jquery' ), '1.0', true );
			// we enqueue the JS for cookie dropping and AJAX recs on all non-admin pages (in case of a widget)
			if ( !is_admin() ) {
				// Add GF params to script
				$event_data = $this->js_params();
				wp_localize_script( 'gfajax', 'gf_params', $event_data );
				wp_enqueue_script( 'gfajax' );
			}
		}

		/**
		 * Add Graphflow data to JS params for use with JS tracking library.
		 * @param array $params
		 * @return array
		 * @internal param array $params
		 */
		public function js_params( $params = array() ) {
			$params['gf_client_key'] = get_option( 'woocommerce_graphflow_client_key' );
			$params['gf_url'] = $this->get_api()->endpoint . 'beacon/track';
			// Set up event params
			$params['gf_events'] = array();

			// user id
			if ( is_user_logged_in() ) {
				// populate user id if logged in
				$params['gf_userid'] = get_current_user_id();
			} else if ( isset( $_REQUEST['gf_email_userid'] ) ) {
				// if user id was set in a recommendation email, use that
				$params['gf_userid'] = $_REQUEST['gf_email_userid'];
			}

			// Shop
			if ( is_shop() ) {
				$params['gf_shop'] = true;
			}

			// Product category
			if ( is_product_category() ) {
				global $wp_query;
        		$cat = $wp_query->get_queried_object();
				$params['gf_product_cat'] = $cat->term_id;
			}

			// Cart
			if ( is_cart() ) {
				$params['gf_cart'] = true;
			}


			// check for previous add-to-cart cart events
			$data = WC()->session->get( 'graphflow_addtocart_session_data' );
			$kept_data = array();
			if ( !empty( $data ) && $this->should_track_user() ) {
				foreach ( $data as $data_instance ) {
					$postid = url_to_postid( $_SERVER['HTTP_REFERER'] );
					if ( is_cart() && wc_get_page_id( 'cart' ) == $postid && $data_instance['gf_action'] === 'addtobasket' ) {
						// if the referring page is the cart page and it's an add-to-cart, don't try to export that event
						$kept_data[] = $data_instance;
					} else {
						// otherwise export it now
						$params['gf_events'][] = $data_instance;
					}
				}
				// clear data
				WC()->session->set( 'graphflow_addtocart_session_data', empty( $kept_data ) ? '' : $kept_data );
			}


			// Page views
			if ( is_product() ) {
				global $post;
				if ( ! isset( $_REQUEST['add-to-cart'] ) && $this->should_track_user() ) {
					$new_event = array(
						'gf_action'     => 'view',
						'gf_product_id' => $post->ID
					);
					// Track rec click events
					if ( isset( $_REQUEST['gf_rec_id'] ) ) {
						$new_event['gf_rec_id'] = $_REQUEST['gf_rec_id'];
					}
					$params['gf_events'][] = $new_event;
				}
				// also add the product id to the base params for similar product recommendations
				$params['gf_product_id'] = $post->ID;
			}
			return $params;
		}

		// ==== RECOMMENDATION DISPLAY ==== //

		/**
		 * Register our widgets
		 * @return void
		 */
		public function include_widgets() {
			include_once( 'widgets/class-wc-widget-graphflow-recommended-products.php' );
		}

		/**
		 * Show install message when plugin is activated
		 * @return HTML for recommendations
		 */
		public static function woocommerce_graphflow_recommendation_display_ajax() {
			global $wp_registered_widgets;
			// set up vars
			$product_id = '';
			$is_shop = false;
			$is_cart = false;
			$product_cat = '';
			$product_tag = '';
			$posts_per_page = '';
			$columns = '';
			$title = '';
			$recType = '';
			// get product id if set
			if ( isset( $_POST['gf_product_id'] ) ) {
				$product_id = $_POST['gf_product_id'];
				$recType = 'product';
			}
			// get user id or anonymous id if set
			if ( isset( $_POST['gf_userid'] ) && !empty( $_POST['gf_userid'] ) ) {
				$user_id = $_POST['gf_userid'];
			} elseif ( isset( $_POST['gf_anon'] ) && !empty( $_POST['gf_anon'] ) ) {
				$user_id = $_POST['gf_anon'];
			} else {
				// we need at least one of them to show recs, so bail out here if neither is present
				wp_die();
			}
			// check if shop is set
			if ( isset( $_POST['gf_shop'] ) ) {
				$is_shop = $_POST['gf_shop'];
				$recType = 'shop';
			}
			// check if it is the cart
			if ( isset( $_POST['gf_cart'] ) ) {
				$is_cart = $_POST['gf_cart'];
				$recType = 'cart';
			}
			// check if a product category is set
			if ( isset( $_POST['gf_product_cat'] ) ) {
				$product_cat = $_POST['gf_product_cat'];
				$recType = 'shop_cat';
			}
			// check if a product tag is set
			if ( isset( $_POST['gf_product_tag'] ) ) {
				$product_tag = $_POST['gf_product_tag'];
			}
			// here we set the shortcode options
			if ( isset( $_POST['gf_is_shortcode'] ) ) {
				$recType = 'shortcode';
				if ( isset( $_POST['gf_title'] ) ) {
					$title = $_POST['gf_title'];
				}
				if ( isset( $_POST['gf_num'] ) ) {
					$posts_per_page = $_POST['gf_num'];
				}
				if ( isset( $_POST['gf_columns'] ) ) {
					$columns = $_POST['gf_columns'];
				}
			}
			// check if the widget is present
			if ( isset( $_POST['gf_widget_id'] ) ) {
				$widget_id = $_POST['gf_widget_id'];
				// validation
				if ( !array_key_exists( $widget_id, $wp_registered_widgets ) ) {
					wp_die();
				}
				$the_id = explode( "-", $widget_id );
				if ( count( $the_id ) == 2 ) {
					$the_id = $the_id[1];
				} else {
					wp_die();
				}
				$recType = 'widget';
				$params = array(
					'widget_id' => $the_id,
					'user_id' => $user_id,
					'product_id' => $product_id,
				);
				// set up callback function
				$callback = $wp_registered_widgets[$widget_id]['callback'];
				$callback[1] = "woocommerce_graphflow_recommendation_display_widget_ajax";

				if ( is_callable( $callback ) ) {
					$rec_html = call_user_func_array($callback, $params);
					if ( $rec_html )
						echo $rec_html;
				}
				wp_die();
			}
			$rec_html = woocommerce_graphflow_recommendation_display( $user_id, $product_id, $is_shop, $is_cart, $product_cat, $product_tag, $posts_per_page, $columns, $title, $recType );
			if ( $rec_html )
				echo $rec_html;
			wp_die();
		}

		/**
		 * Show related products based on settings
		 * @return void
		 */
		public function display_recommendations_based_on_settings() {
			if ( 'yes' == get_option( 'woocommerce_graphflow_show_on_product' ) ) {
				remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15 );
				// remove storefront upsells since it is a common theme
				remove_action( 'woocommerce_after_single_product_summary', 'storefront_upsell_display', 15 );
				remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
				add_action( 'woocommerce_after_single_product_summary', 'woocommerce_graphflow_recommendations_placeholder', 15, 0 );

			}

			if ( 'yes' == get_option( 'woocommerce_graphflow_show_on_cart' ) ) {
				remove_action( 'woocommerce_cart_collaterals', 'woocommerce_cross_sell_display' );
				$cart_action = get_option( 'woocommerce_graphflow_show_on_cart_location', 'after_cart' );
				add_action( 'woocommerce_' . $cart_action, 'woocommerce_graphflow_recommendations_placeholder', 10, 0 );
			}

			if ( 'yes' == get_option( 'woocommerce_graphflow_show_on_shop' ) ) {
				add_action('woocommerce_before_shop_loop', 'woocommerce_graphflow_recommendations_placeholder', 10, 0 );
			}
		}

		/**
		 * graphflow_recommendations shortcode
		 * @param  array $atts
		 * @return string
		 */
		public function recommendations_shortcode( $atts ) {
			/**
			 * @var int $per_page
			 * @var int $columns
			 * @var string $orderby
			 * @var int $product
			 */
			extract( shortcode_atts( array(
				'per_page' 	=> '4',
				'columns' 	=> '4',
				'orderby' 	=> 'post__in',
				'product' 	=> '',
				'title'		=> '',
				'product_cat' => '',
				'product_cat_slug' => '',
				'product_tag' => '',
				'product_tag_slug' => '',
			), $atts ) );

			if ( ! empty( $title ) ) {
				$gf_title = $title;
			} else {
				$gf_title = get_option( 'woocommerce_graphflow_shortcode_rec_title' );
			}

			if ( get_option( 'woocommerce_graphflow_use_ajax', 'yes' ) == 'yes' ) {
				$content = "<div class='graphflow_recommendations_placeholder woocommerce columns-" . $columns . "' ";
				// build attributes for AJAX
				$content = $content . "gf_title='" . $gf_title . "' ";
				if ( ! empty ( $per_page ) ) {
					$content = $content .  "gf_num='" . $per_page . "' ";
				}
				if ( ! empty ( $columns ) ) {
					$content = $content .  "gf_columns='" . $columns . "' ";
				}
				if ( ! empty ( $product ) ) {
					$content = $content .  "gf_product_id='" . $product . "' ";
				}	
				if ( ! empty ( $product_cat_slug ) ) {
					$product_cat_term = get_term_by( 'slug', $product_cat_slug, 'product_cat' );
					$product_cat = $product_cat_term->term_id;
				}
				if ( ! empty ( $product_cat ) ) {
					$content = $content .  "gf_product_cat='" . $product_cat . "' ";
				}	
				if ( ! empty ( $product_tag_slug ) ) {
					$product_tag_term = get_term_by( 'slug', $product_tag_slug, 'product_tag' );
					$product_tag = $product_tag_term->term_id;
				}
				if ( ! empty ( $product_tag ) ) {
					$content = $content .  "gf_product_tag='" . $product_tag . "' ";
				}		
				$content = $content . "gf_is_shortcode='1'></div>";
				return $content;
			} else {
				if ( ! empty ( $product_cat_slug ) ) {
					$product_cat_term = get_term_by( 'slug', $product_cat_slug, 'product_cat' );
					$product_cat = $product_cat_term->term_id;
				}	
				if ( ! empty ( $product_tag_slug ) ) {
					$product_tag_term = get_term_by( 'slug', $product_tag_slug, 'product_tag' );
					$product_tag = $product_tag_term->term_id;
				}
				ob_start();
				woocommerce_graphflow_recommendation_display(
					$this->get_user_id(), 
					$product, 
					false,
					false,
					$product_cat,
					$product_tag,
					$per_page, 
					$columns, 
					$gf_title,
					'shortcode',
					$orderby
				);
				return '<div class="woocommerce columns-' . $columns . '">' . ob_get_clean() . '</div>';
			}
		}

	}
}
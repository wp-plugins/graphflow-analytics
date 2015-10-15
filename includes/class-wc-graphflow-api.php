<?php

/**
 * GraphFlow API class, handles all API calls to GraphFlow
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'WC_GraphFlow_API' ) ) {
	class WC_GraphFlow_API {

		/**
		 * Production API endpoint
		 * @var string
		 * @access public
		 * @since 1.0.0
		 */
		const PRODUCTION_ENDPOINT = 'https://api.graphflow.com/';

		public $log;

		/**
		 * Endpoint to use for making calls
		 * @var string
		 * @access public
		 * @since 1.0.0
		 */
		public $endpoint;

		/**
		 * Graphflow Client Key
		 * @var string
		 * @access public
		 * @since 1.0.0
		 */
		public $client_key;

		/**
		 * Graphflow API Key
		 * @var string
		 * @access public
		 * @since 1.0.0
		 */
		public $api_key;

		/**
		 * Constructor
		 * @param string $client_key
		 * @param string $api_key
		 */
		public function __construct( $client_key, $api_key ) {
			$this->client_key = $client_key;
			$this->api_key = $api_key;
			$this->endpoint = WC_GraphFlow_API::PRODUCTION_ENDPOINT;
			$this->log = new WC_Logger();
		}

		/**
		 * Make a call to the Graphflow API
		 * @param  string $endpoint
		 * @param  json $json
		 * @param  string $method
		 * @param  boolean $append_query_args
		 * @return string
		 * @throws Exception
		 */
		private function perform_request( $endpoint, $json, $method = 'GET', $append_query_args = false, $extra_params = '' ) {
			$args = array(
				'method' 	  => $method,
				'timeout'     => apply_filters( 'wc_graphflow_api_timeout', 10 ), // default to 10 seconds
				'redirection' => 0,
				'httpversion' => '1.0',
				'sslverify'   => false,
				'blocking'    => true,
				'headers'     => array(
					'accept'       	=> 'application/json',
					'content-type' 	=> 'application/json',
					'api_key' 		=> sanitize_text_field( $this->api_key ),
					'client_key'  	=> sanitize_text_field( $this->client_key )
				),
				'body'        => $json,
				'cookies'     => array(),
				'user-agent'  => "PHP " . PHP_VERSION . '/WooCommerce ' . get_option( 'woocommerce_db_version' ) . '/Graphflow ' . get_option( 'woocommerce_graphflow_plugin_version' )
			);

			// Append data as query args for GET requests
			$query_args = '?client_key=' . sanitize_text_field( $this->client_key );
			if ( 'GET' == $method || $append_query_args) {
				$q_data = json_decode( $json );
				$query_args .= '&' . http_build_query( $q_data );
				if ( !empty( $extra_params ) ) {
					$query_args .= $extra_params;
				}
			}

			$response = wp_remote_request( $this->endpoint . $endpoint . $query_args, $args );

			if ( is_wp_error( $response ) ) {
				throw new Exception( print_r( $response, true ) );
			}
			
			$api_code = $response['response']['code'];
			if ( $api_code != 200 && $api_code != 204 ) {
					$json_response = json_decode( $response['body'] );
					if ( isset( $json_response->message ) ) {
						$message = $json_response->message;
					} else {
						$message = $json_response;
					}
				$this->log->add("graphflow", "Received error code " . $api_code . " for API call " . $endpoint . "; Message: " . $message );
			}

			return $response;
		}

		// ==== PRODUCT CALLS ==== //

		/**
		 * Export product to Graphflow
		 *
		 * @param  int $item_id
		 * @param  array  $item_data
		 * @return array
		 */
		public function update_item( $item_id, $item_data = array() ) {
			$call_data = array(
				'itemId' => (string)$item_id,
				'itemData' => $item_data,
			);
			$response = $this->perform_request( 'item/', json_encode( $call_data ), 'PUT' );
			return json_decode( $response['body'] );
		}

		/**
		 * Export multiple products to Graphflow
		 * @param $items
		 * @return array|mixed
		 * @throws Exception
		 */
		public function update_items( $items ) {
			$response = $this->perform_request( 'item/itemlist', json_encode( $items ), 'PUT' );
			return json_decode( $response['body'] );
		}

		/**
		 * Delete item on Graphflow
		 *
		 * @param  int $item_id
		 * @return array
		 */
		public function delete_item( $item_id ) {
			$response = $this->perform_request( 'item/' . sanitize_text_field( $item_id ), '', 'DELETE' );
			return json_decode( $response['body'] );
		}

		/**
		 * Toggle item active status
		 * @param  int  $item_id
		 * @param  boolean $active
		 * @return array
		 */
		public function toggle_item( $item_id, $active = true ) {
			$call_data = array(
				'itemId' => (string)$item_id,
				'active' => (boolean)$active,
			);
			$response = $this->perform_request( 'item/activetoggle', json_encode( $call_data ), 'PUT', true );
			return json_decode( $response['body'] );
		}

		// ==== USER CALLS ==== //

		/**
		 * Export a user to Graphflow
		 * @param array $user_data
		 * @return array
		 */
		public function update_user( $user_data ) {
			$response = $this->perform_request( 'user/', json_encode( $user_data ), 'PUT' );
			return json_decode( $response['body'] );
		}

		/**
		 * Export multiple users to Graphflow
		 * @param array $users
		 * @return array|mixed
		 * @throws Exception
		 */
		public function update_users( $users ) {
			$response = $this->perform_request( 'user/userlist', json_encode( $users ), 'PUT' );
			return json_decode( $response['body'] );
		}

		/**
		 * Set an alias user id for the current user id
		 * @param int $user_id Logged in user id
		 * @param string $other_id Previous custom generated user id
		 * @return array
		 */
		public function add_user_alias( $user_id, $other_id ) {
			$call_data = array(
				'userId'  => (string)$user_id,
				'otherId' => (string)$other_id,
			);
			$response = $this->perform_request( '/user/alias', json_encode( $call_data ), 'GET', true );
			return json_decode( $response['body'] );
		}

		/**
		 * Log user interaction on Graphflow
		 *
		 * @param int $item_id
		 * @param int $user_id
		 * @param string $interaction_type
		 * @param int|string $qty
		 * @param string $price
		 * @return array
		 * @throws Exception
		 */
		public function add_user_interaction( $item_id, $user_id, $interaction_type, $qty = '', $price = '' ) {
			$call_data = array(
				'fromId' => (string) $user_id,
				'toId' => (string) $item_id,
				'interactionType' => (string) $interaction_type,
			);
			if ( ! empty( $qty ) ) {
				$call_data['quantity'] = (int) $qty;
			}
			if ( ! empty( $price ) ) {
				$call_data['price'] = (float) $price;
			}
			$response = $this->perform_request( 'user/interaction', json_encode( $call_data ), 'POST' );
			return json_decode( $response['body'] );
		}

		/**
		 * Log multiple user interactions at once on Graphflow
		 *
		 * @param array $interactions
		 * @return array
		 * @throws Exception
		 * @internal param int $user_id
		 */
		public function add_user_interactions( $interactions ) {
			$call_data = array();

			foreach ( $interactions as $interaction ) {
				$interaction_data = array(
					'fromId' => (string) $interaction['fromId'],
					'toId' => (string) $interaction['toId'],
					'interactionType' => $interaction['interactionType'],
					'timestamp' => $interaction['timestamp'],
					'interactionData' => $interaction['interactionData'],
				);
				if ( isset( $interaction['price'] ) ) {
					$interaction_data['price'] = (float) $interaction['price'];
				}
				if ( isset( $interaction['quantity'] ) ) {
					$interaction_data['quantity'] = (int) $interaction['quantity'];
				}
				$call_data[] = $interaction_data;
			}
			$response = $this->perform_request( 'user/interactionlist', json_encode( $call_data ), 'POST' );
			return json_decode( $response['body'] );
		}

		/**
		 * Toggle item active status
		 * @param $order_id
		 * @param $order_status
		 * @return array
		 * @throws Exception
		 */
		public function update_order_status( $order_id, $order_status ) {
			$call_data = array(
				'order_status' => (string) $order_status,
				'order_id' => (string) $order_id,
			);
			$response = $this->perform_request( 'user/interaction/order/status', json_encode( $call_data ), 'PUT', true );
			return json_decode( $response['body'] );
		}

		 // ==== RECOMMENDATION CALLS ==== //

		/**
		 * Get recommendations based on product
		 * @param  int $product_id
		 * @param  mixed $user_id
		 * @param int $number
		 * @param string $filters
		 * @param string $recType
		 * @param bool $is_cart
		 * @return array
		 * @throws Exception
		 */
		public function get_product_recommendations(
			$product_id,
			$user_id,
			$number = 5,
			$filters = '',
			$recType = '',
			$is_cart = false ) {
			$params = json_encode( array(
				'userId' => $user_id,
				'num' => $number,
				'filters' => $filters,
				'recType' => $recType,
				'referrer' => $_SERVER['HTTP_REFERER'],
				'remoteAddr' => $_SERVER['REMOTE_ADDR'],
				'uaRaw' => $_SERVER['HTTP_USER_AGENT'],
				'purchasedTogether' => $is_cart ? 'true' : 'false'
			) );
			if ( is_array( $product_id ) ) {
				$extra_params = '';
				foreach ( $product_id as $id ) {
					$extra_params .= '&itemId=' . absint( $id );
				}
				$response = $this->perform_request( 'recommend/item/similar', $params, 'GET', true, $extra_params );
			} else {
				$response = $this->perform_request( 'recommend/item/' . absint( $product_id ) . '/similar', $params, 'GET' );
			}
			if ( isset( $response['response']['code'] ) &&  200 != $response['response']['code'] ) {
				// if error code, return an empty array
				return array();
			}
			return json_decode( $response['body'] );
		}

		/**
		 * Get recommendations based on user
		 * @param  mixed $user_id
		 * @param  int $number
		 * @param string $filters
		 * @param string $recType
		 * @return array
		 * @throws Exception
		 */
		public function get_user_recommendations( $user_id, $number = 5, $filters = '', $recType = '' ) {
			$response = $this->perform_request( '/recommend/user/' . urlencode( $user_id ),
				json_encode( array( 
					'num' => $number, 
					'filters' => $filters, 
					'recType' => $recType,
					'referrer' => $_SERVER['HTTP_REFERER'],
					'remoteAddr' => $_SERVER['REMOTE_ADDR'], 
					'uaRaw' => $_SERVER['HTTP_USER_AGENT'],
					) 
				), 'GET' );
			if ( isset( $response['response']['code'] ) && 204 == $response['response']['code'] && strpos($recType, 'email') !== false ) {
				$this->log->add("graphflow", "Received no-content response for user [" . $user_id . "] - user has unsubscribed from email recommendations" );
			}
			if ( isset( $response['response']['code'] ) && 200 != $response['response']['code'] ) {
				// if error code, return an empty array
				return array();
			}
			return json_decode( $response['body'] );
		}

		/**
		 * Get recommendations based on user
		 * @param  mixed $user_id
		 * @param  int $number
		 * @param string $filters
		 * @param string $recType
		 * @return array
		 * @throws Exception
		 */
		public function get_trending_recommendations( $user_id, $number = 5, $from_time = '', $to_time = '', $type = '', $aggType = '', $filters = '', $recType = '' ) {
			$params = array();
			if ( !empty( $from_time ) ) $params = array_merge( $params, array( 'fromTime' => $from_time ) );
			if ( !empty( $to_time ) ) $params = array_merge( $params, array( 'toTime' => $to_time ) );
			if ( !empty( $type ) ) $params = array_merge( $params, array( 'type' => $type ) );
			if ( !empty( $aggType ) ) $params = array_merge( $params, array( 'aggType' => $aggType ) );
			$other = array(
				'num'               => $number,
				'userId'            => $user_id,
				'filters'           => $filters,
				'recType'           => $recType,
				'referrer'          => $_SERVER['HTTP_REFERER'],
				'remoteAddr'        => $_SERVER['REMOTE_ADDR'],
				'uaRaw'             => $_SERVER['HTTP_USER_AGENT'],
				'excludeConverted'  => true
			);
			$params = array_merge( $params, $other );
			$response = $this->perform_request( '/recommend/item/popular', json_encode( $params ), 'GET' );
			if ( isset( $response['response']['code'] ) &&  200 != $response['response']['code'] ) {
				// if error code, return an empty array
				return array();
			}
			return json_decode( $response['body'] );
		}

		/**
		 * Get recommendations based on the user and product
		 * @param  int $product_id
		 * @param  mixed $user_id
		 * @param int $number
		 * @param string $filters
		 * @param string $recType
		 * @return array
		 * @throws Exception
		 */
		public function get_user_product_recommendations( $product_id, $user_id, $number = 5, $filters = '', $recType = '' ) {
			$response = $this->perform_request( '/recommend/useritem/' . urlencode( $user_id ),
				json_encode( array( 
					'itemId' => absint( $product_id ), 
					'num' => $number, 
					'filters' => $filters, 
					'recType' => $recType,
					'referrer' => $_SERVER['HTTP_REFERER'],
					'remoteAddr' => $_SERVER['REMOTE_ADDR'], 
					'uaRaw' => $_SERVER['HTTP_USER_AGENT'],
					) 
				), 'GET' );
			if ( isset( $response['response']['code'] ) &&  200 != $response['response']['code'] ) {
				// if error code, return an empty array
				return array();
			}
			return json_decode( $response['body'] );
		}

		public function post_beacon_event( $user_id, $data ) {
			$response = $this->perform_request( '/beacon/track', json_encode( array( 'gf_userid' => $user_id, 'gf_events' => $data ) ), 'POST' );
			if ( $response['response']['code'] == 200 ) {
				return true;
			} else {
				return false;
			}
		}


		/**
		 * @return bool
		 * @throws Exception
		 */
		public function test_auth() {
			$currency = get_woocommerce_currency();
			$version = get_option( 'woocommerce_graphflow_plugin_version', '' );
			$version  = str_replace( ".", "", $version );
			$response = $this->perform_request( '/analytics/woocommerce/meta', json_encode( array( 'currency' => $currency, 'version' => $version ) ), 'GET' );
			if ( $response['response']['code'] != 401 ) {
				return true;
			} else {
				return false;
			}

		}

		/**
		 * @param $user_id
		 * @param $rec_id
		 * @return string
		 */
		public function generate_tracking_pixel( $user_id, $rec_id, $action ) {
			$pixel = $this->endpoint
			         . 'beacon/beacon.gif?client_key=' . $this->client_key
			         . '&gf_email_userid=' . urlencode( $user_id )
			         . '&gf_email_rec_id=' . urlencode( $rec_id )
			         . '&gf_email_rec_action=' . urlencode( $action );
			return $pixel;
		}

		public function generate_tracking_url( $user_id, $product_id, $rec_id, $action, $redirect ) {
			$product_var = empty( $product_id ) ? '' : '&gf_email_product_id=' . urlencode( $product_id );
			$redirect = add_query_arg( array( 'gf_email_userid' => urlencode( $user_id ) ), $redirect );
			$pixel = $this->endpoint
			         . 'beacon/track?client_key=' . $this->client_key
			         //. '&gf_email_userid=' . urlencode( $user_id )
			         . $product_var
			         . '&gf_email_rec_id=' . urlencode( $rec_id )
			         . '&gf_email_rec_action=' . urlencode( $action )
			         . '&gf_redirect=' . urlencode( $redirect );
			return $pixel;
		}

		public function generate_unsub_link( $user_id, $rec_id ) {
			$store_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
			$unsub = $this->endpoint
			         . 'beacon/email/unsub?client_key=' . $this->client_key
			         . '&gf_email_userid=' . urlencode( $user_id )
			         . '&gf_email_rec_id=' . urlencode( $rec_id )
			         . '&gf_email_store_name=' . urlencode( $store_name );
			return $unsub;
		}
	}
}

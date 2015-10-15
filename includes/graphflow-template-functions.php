<?php

/**
 * Output the related products from Graphflow API
 *
 * @access public
 * @return void
 */
function woocommerce_graphflow_recommendations_placeholder(
	$posts_per_page = '', 
	$columns = '', 
	$product_id = '', 
	$title = '', 
	$product_cat = '', 
	$product_tag = '', 
	$orderby = 'post__in' ) {
	if ( is_search() ) {
		return;
	}
	if ( get_option( 'woocommerce_graphflow_use_ajax', 'yes' ) == 'yes' ) {
		echo "<div class='graphflow_recommendations_placeholder'></div>";
	} else {
		global $product;
		// set up user id
		$user_id = $GLOBALS['wc_graphflow']->get_user_id();
		// set up product id
		if ( is_product() ) {
			$product_id = $product->id;
			$recType = 'product';
		} 
		$is_shop = false;
		if ( is_shop() ) {
			$is_shop = true;
			$recType = 'shop';
		}
		if ( is_product_category() ) {
			// get the category
			global $wp_query;
			$cat = $wp_query->get_queried_object();
			$product_cat = $cat->term_id;
			// set other vars
			$is_shop = true;
			$recType = 'shop_cat';
		}
		$is_cart = false;
		if ( is_cart() ) {
			$is_cart = true;
			$recType = 'cart';
		}
		woocommerce_graphflow_recommendation_display(
			$user_id, 
			$product_id, 
			$is_shop,
			$is_cart,
			$product_cat,
			$product_tag,
			$posts_per_page, 
			$columns, 
			$title,
			$recType,
			$orderby
		);
	}
}

function woocommerce_graphflow_add_product_class( $classes ) {
	$classes[] = 'product';
	return $classes;
}

function get_cart_recommendations( $gf_user, $gf_num, $filters, $recType ) {
	$gf_recIds = array();
	$gf_products = array();
	$cart_products = array();
	$total_prods = WC()->cart->get_cart_contents_count();
	// if no contents in cart, return
	if ( $total_prods <= 0 ) return;
	//$num_per_prod = ceil( $gf_num / $total_prods );
	foreach ( WC()->cart->get_cart() as $cart_item ) {
		$cart_products[] = $cart_item['product_id'];
	}
	$gf_recommendations = $GLOBALS['wc_graphflow']->get_api()->get_product_recommendations( $cart_products, $gf_user, apply_filters( 'woocommerce_graphflow_cart_recommended_products_total', $gf_num ), $filters, $recType, true );
	 /*
		$gf_recommendations = $GLOBALS['wc_graphflow']->get_api()->get_user_product_recommendations( $cart_item['product_id'], $gf_user, apply_filters( 'woocommerce_graphflow_cart_recommended_products_total', $num_per_prod ), $filters, $recType );
		if ( isset( $gf_recommendations->result ) ) {
			foreach ( $gf_recommendations->result as $item ) {
				$gf_products[] = $item->itemId;
			}
		}
		if ( isset( $gf_recommendations->recId ) ) {
			$gf_recIds[] = $gf_recommendations->recId;
		}
	}
	$gf_recId = implode( ' ', $gf_recIds );
	return array( $gf_recId, $gf_products, $cart_products );
    */

	if ( isset( $gf_recommendations->result ) ) {
		foreach ( $gf_recommendations->result as $item ) {
			$gf_products[] = $item->itemId;
		}
	}
	$gf_recId = '';
	if ( isset( $gf_recommendations->recId ) ) {
		$gf_recId = $gf_recommendations->recId;
	}
	return array( $gf_recId, $gf_products, $cart_products );
}

function get_user_recommendations( $gf_user, $gf_num, $filters, $recType ) {
	$gf_products = array();
	$gf_recommendations = $GLOBALS['wc_graphflow']->get_api()->get_user_recommendations( $gf_user, apply_filters( 'woocommerce_graphflow_recommended_products_total', $gf_num ), $filters, $recType );
	if ( isset( $gf_recommendations->result ) ) {
		foreach ( $gf_recommendations->result as $item ) {
			$gf_products[] = $item->itemId;
		}
	}
	$gf_recId = '';
	if ( isset( $gf_recommendations->recId ) ) {
		$gf_recId = $gf_recommendations->recId;
	}
	return array( $gf_recId, $gf_products );
}

function get_similar_products_recommendations( $gf_product_id, $gf_user, $gf_num, $filters, $recType ) {
	$gf_products = array();
	$gf_recommendations = $GLOBALS['wc_graphflow']->get_api()->get_product_recommendations( $gf_product_id, $gf_user, apply_filters( 'woocommerce_graphflow_recommended_products_total', $gf_num ), $filters, $recType );
	if ( isset( $gf_recommendations->result ) ) {
		foreach ( $gf_recommendations->result as $item ) {
			$gf_products[] = $item->itemId;
		}
	}
	$gf_recId = '';
	if ( isset( $gf_recommendations->recId ) ) {
		$gf_recId = $gf_recommendations->recId;
	}
	return array( $gf_recId, $gf_products );
}

function woocommerce_graphflow_post_event( $gf_user, $gf_events ) {
	$GLOBALS['wc_graphflow']->get_api()->post_beacon_event( $gf_user, $gf_events );
}


function woocommerce_graphflow_recommendation_display(
	$user_id = '', 
	$product_id = '', 
	$is_shop = false,
	$is_cart = false,
	$product_cat = '',
	$product_tag = '',
	$posts_per_page = '', 
	$columns = '', 
	$title = '',
	$recType = '',
	$orderby = 'post__in') {

	global $woocommerce, $woocommerce_loop;

	add_filter( 'post_class', 'woocommerce_graphflow_add_product_class' );

	// set up variables
	$filters = '';
	$gf_recId = '';

	// if the current user is logged in, use that id
	if ( is_user_logged_in() ) {
		$gf_user = get_current_user_id();
	} else {
		$gf_user = $user_id;
	}
	// can't get recs for an empty user id!
	if ( empty( $gf_user ) ) {
		$GLOBALS['wc_graphflow']->get_api()->log->add( 'graphflow', 'Got empty user id for recommendation call. If you are using caching, please enable AJAX recommendations.');
		return "";
	}

	// set up title
	if ( ! empty( $title ) ) {
		$gf_title = $title;
	} else if ( $is_shop || ! empty( $product_cat ) ) {
		$gf_title = get_option( 'woocommerce_graphflow_user_rec_title' );
	} else if ( $is_cart ) {
		$gf_title = get_option( 'woocommerce_graphflow_cart_rec_title' );
	} else {
		$gf_title = get_option( 'woocommerce_graphflow_product_rec_title' );
	}

	// set up total number of recs
	if ( ! empty( $posts_per_page ) && $posts_per_page > 0 ) {
		$gf_num = $posts_per_page;
	} else if ( $is_shop || ! empty( $product_cat ) ) {
		$gf_num = get_option( 'woocommerce_graphflow_user_rec_num' );
	} else if ( $is_cart ) {
		$gf_num = get_option( 'woocommerce_graphflow_cart_rec_num' );
	} else {
		$gf_num = get_option( 'woocommerce_graphflow_product_rec_num' );
	}
	if ( $gf_num <= 0) return "";

	// set up display columns
	if ( ! empty( $columns ) ) {
		$gf_columns = $columns;
	} else if ( $is_shop || ! empty($product_cat) ) {
		$gf_columns = get_option( 'woocommerce_graphflow_user_rec_col' );
	} else if ( $is_cart ) {
		$gf_columns = get_option( 'woocommerce_graphflow_cart_rec_col' );
	} else {
		$gf_columns = get_option( 'woocommerce_graphflow_product_rec_col' );
	}

	$cart_products = array();
	// get the recommendations
	if ( isset( $product_id ) && ! empty( $product_id ) ) {
		// get product categories for filters
		$product = get_product( $product_id );
		if ( ! $product ) {
			$GLOBALS['wc_graphflow']->get_api()->log->add( 'graphflow', 'Failed to get_product on recommend AJAX call with id: ' . $product_id );
			return "";
		}
		if ( 'yes' == get_option( 'woocommerce_graphflow_product_rec_restrict_category' ) ) {
			$product_cats = implode( ' ', wp_get_object_terms( $product->id, 'product_cat', array( 'fields' => 'ids' ) ) );
			$filters = 'product_cat_ids=' . $product_cats;
		}
		$gf_result = get_similar_products_recommendations( $product_id, $gf_user, $gf_num, $filters, $recType );
	} else if ( $is_cart ) {
		$gf_result = get_cart_recommendations( $gf_user, $gf_num, $filters, $recType );
		$cart_products = $gf_result[2];
	} else {
		// set up filters - used for shortcode
		$filters_arr = array();
		if ( ! empty( $product_cat ) ) {
			$filters_arr[] = 'product_cat_ids=' . $product_cat;
		} 
		if ( ! empty( $product_tag ) ) {
			$filters_arr[] = 'product_tag_ids=' . $product_tag;
		} 
		$filters = implode( ',', $filters_arr );
		$gf_result = get_user_recommendations( $gf_user, $gf_num, $filters, $recType );
	}
	$gf_recId = $gf_result[0];
	$gf_products = $gf_result[1];

	// unique set of products
	$gf_products = array_unique( $gf_products );
	// remove products in the cart
	$gf_products = array_diff( $gf_products, $cart_products );
	if ( sizeof( $gf_products ) == 0 ) return "";

	if ( !empty( $gf_recId ) ) {
		$_REQUEST['gf_set_rec_id'] = $gf_recId;
	}

	$template_args = array(
		'gf_products'	 => $gf_products,
		'gf_title'		 => $gf_title,
		'gf_num'		 => $gf_num,
		'gf_columns'	 => $gf_columns,
		'gf_recId'		 => $gf_recId,
		'orderby'        => $orderby
	);

	if ( $is_cart ) {
		// get cart recs
		wc_get_template( 'cart/graphflow-recommended-products.php', $template_args, '', untrailingslashit( plugin_dir_path( WC_Graphflow::$file ) ) . '/templates/' );
	} else {
		// get user or product recs
		wc_get_template( 'single-product/graphflow-recommended-products.php', $template_args, '', untrailingslashit( plugin_dir_path( WC_Graphflow::$file ) ) . '/templates/' );
	}
}
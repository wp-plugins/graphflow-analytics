<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

global $woocommerce_loop;

$gf_products = array_slice( $gf_products, 0, apply_filters( 'woocommerce_graphflow_recommended_products_widget_total', $number ) );

$args = array(
	'post_type' 	 => 'product',
	'no_found_rows'  => 1,
	'orderby'		 => 'post__in',
	'post__in' 		 => $gf_products
);

$products = new WP_Query( $args );

if ( $products->have_posts() ) {

	echo $before_widget;

	if ( $title )
		echo $before_title . $title . $after_title;

	echo '<ul class="graphflow_recommendations_widget product_list_widget" gf_recid="' . $gf_recId . '">';

	while ( $products->have_posts()) {
		$products->the_post();
		wc_get_template( 'content-widget-product.php', array( 'show_rating' => $show_rating ) );
	}

	echo '</ul>';

	echo $after_widget;
}

wp_reset_postdata();
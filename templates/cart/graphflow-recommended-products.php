<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

global $woocommerce_loop;

$gf_products = array_slice( $gf_products, 0, apply_filters( 'woocommerce_graphflow_cart_recommended_products_total', $gf_num ) );

$args = array(
	'post_type'           => 'product',
	'ignore_sticky_posts' => 1,
	'no_found_rows'       => 1,
	'orderby'             => $orderby,
	'post__in'            => $gf_products,
);

$products = new WP_Query( $args );

$gf_title = trim( $gf_title );

$woocommerce_loop['columns'] = apply_filters( 'woocommerce_graphflow_cart_recommended_products_columns', $gf_columns );

if ( $products->have_posts() ) : ?>

	<div class="graphflow_recommendations cross-sells products" gf_recid="<?php echo $gf_recId;?>">

		<?php if ( !empty( $gf_title ) ) : ?>
			<h2><?php echo $gf_title; ?></h2>
		<?php endif; ?>

		<?php woocommerce_product_loop_start(); ?>

			<?php while ( $products->have_posts() ) : $products->the_post(); ?>

				<?php wc_get_template_part( 'content', 'product' ); ?>

			<?php endwhile; // end of the loop. ?>

		<?php woocommerce_product_loop_end(); ?>

	</div>

<?php endif;

wp_reset_query();
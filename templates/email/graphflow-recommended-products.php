<?php

/**
 * @var string $gf_title
 * @var string $gf_userid
 * @var string $gf_recId
 * @var int $gf_rows
 * @var int $gf_columns
 * @var bool $is_fue
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

global $woocommerce_loop;

$bg = get_option( 'woocommerce_email_background_color' );
$base = get_option( 'woocommerce_email_base_color' );
$base_text = wc_light_or_dark( $base, '#202020', '#ffffff' );

$gf_products = array_slice( $gf_products, 0, apply_filters( 'woocommerce_graphflow_recommended_products_email_total', absint( $gf_rows * $gf_columns ) ) );

$args = array(
	'post_type' 	 => 'product',
	// only published
	'post_status'    => 'publish',
	'no_found_rows'  => 1,
	'orderby'		 => 'post__in',
	'post__in' 		 => $gf_products,
	// exclude non-visible products
	'meta_query' => array(
		array(
			'key' => '_visibility',
			'value' => array( 'catalog', 'visible' ),
			'compare' => 'IN'
		)
	)
);

$products = new WP_Query( $args );

$gf_title = trim( $gf_title );

if ( !empty( $gf_userid ) && !empty( $gf_recId) ) {
	$pixel = $GLOBALS['wc_graphflow']->get_api()->generate_tracking_pixel( $gf_userid, $gf_recId, 'open' );
	$unsub = $GLOBALS['wc_graphflow']->get_api()->generate_unsub_link( $gf_userid, $gf_recId );
	$_REQUEST['gf_unsub_link'] = $unsub;
} else {
	$pixel = '';
}

$width = (int) floor( 100 / $gf_columns );
$width_text = (string) "width:" . $width . "%;max-width:" . $width . "%;";

if ( $products->have_posts() ) : ?>
	<div class="<?php echo $email_class; ?>">
		<?php if ( $pixel ): ?>
			<img src="<?php echo $pixel; ?>" width="1px" height="1px">
		<?php endif; ?>
		<?php if ( !empty( $gf_title ) ) : ?>
			<h2><?php echo $gf_title; ?></h2>
		<?php endif; ?>
		<?php if ( !empty( $gf_desc ) ) : ?>
			<p><?php echo $gf_desc; ?></p>
		<?php endif; ?>
		<table style="width:100%;" cellspacing="0" cellpadding="0">
			<?php $rows = 0; while ( $rows < $gf_rows  ) : ?>
				<tr>
					<?php
					$cols = 0;
					while ( $products->have_posts() && $cols < $gf_columns ) {
						$products->the_post();
						wc_get_template(
							'email/recommended-product.php',
							array(
								'gf_recId'  => $gf_recId,
								'gf_userid' => $gf_userid,
								'width'     => $width_text,
								'is_fue'    => $is_fue
							),
							'',
							untrailingslashit( plugin_dir_path( WC_Graphflow::$file ) ) . '/templates/');
						$cols++;
					}
					?>
				</tr>
				<?php $rows++; ?>
			<?php endwhile; // end of the loop. ?>
		</table>
		<?php if ( !empty( $coupon ) ) :
			$shop_page_url = $GLOBALS['wc_graphflow']->get_api()->generate_tracking_url( $gf_userid, '', $coupon['code'], 'click', get_permalink( wc_get_page_id( 'shop' ) ) );
			?>
		<div class="graphflow-coupon">
			<table style="width:100%; background-color: <?php echo esc_attr( $bg ); ?>; margin-top: 8px;" border="0" cellspacing="0" cellpadding="0">
				<tr>
					<td>
						<h3 id="graphflow-coupon-title"><?php echo $coupon['title'];?></h3>
						<p id="graphflow-coupon-text"><?php echo $coupon['text'];?></p>
						<table style="width: 100%;" border="0" cellspacing="0" cellpadding="0">
							<tr>
								<td align="center" valign="middle" style="width:100%; background-color: <?php echo esc_attr( $base ); ?>; padding: 12px 18px 12px 18px; -webkit-border-radius:3px; border-radius:3px">
									<a href="<?php echo $shop_page_url; ?>" target="_blank" style="color: <?php echo esc_attr( $base_text ); ?>; text-decoration: none; display: inline-block;"><strong><?php echo $coupon['code']; ?></strong></a>
								</td>
							</tr>
						</table>
						<p id="graphflow-coupon-expiry"><strong><?php echo 'Expires on ' . $coupon['expiry'];?></strong></p>
						<?php if ( !empty( $coupon['limit'] ) ): ?>
						<p id="graphflow-coupon-limits"><i><?php echo $coupon['limit'];?></i></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>
		<?php endif; ?>
	</div>
	<?php
endif;
wp_reset_query();


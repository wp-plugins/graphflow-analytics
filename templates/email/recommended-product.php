<?php

/**
 * @var string $gf_recId
 * @var bool $is_fue
 */

global $woocommerce_loop;

$product = wc_get_product( get_the_id() );

if ( !$product ) {
    ?>
    <td valign="top" style="width:33%;max-width:33%;text-align:center;border:none;"></td>
    <?php
}

$a_text = apply_filters( 'woocommerce_graphflow_recommended_products_email_a_text', 'View Product' );

// Ensure visibilty
if ( !$product->is_visible() )
    return;

$args = array_filter( array( 'gf_rec_id' => $gf_recId, 'gf_email_userid' => urlencode( $gf_userid ) ) );
$permalink = esc_url_raw( add_query_arg( $args, get_the_permalink() ) );
if ( $is_fue ) {
	// convert links to FUE Plugin compatible links, for FUE click tracking
	$permalink = "{link url=" . $permalink . "}";
}
?>
<td valign="top" style="<?php echo $width; ?>text-align:center;border:none;">
    <a href="<?php echo $permalink; ?>" title="<?php the_title(); ?>">
        <?php echo $product->get_image(); ?>
    </a>
    <h3><?php the_title(); ?></h3>
    <div>
        <div class="graphflow-price"><?php echo $product->get_price_html(); ?></div>
        <a class="graphflow-more-link" href="<?php echo $permalink; ?>" title="<?php the_title(); ?>"><?php echo $a_text; ?></a>
    </div>
</td>
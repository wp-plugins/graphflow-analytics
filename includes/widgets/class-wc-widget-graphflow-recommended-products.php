<?php
/**
 * List recommended products for user.
 *
 * @author 		WooThemes
 * @category 	Widgets
 * @package 	WooCommerce/Widgets
 * @version 	1.0
 * @extends 	WC_Widget
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class WC_Widget_Graphflow_Recommended_Products extends WC_Widget {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->widget_cssclass    = 'woocommerce widget_products';
		$this->widget_description = __( 'Display a list of recommended products for the current user on your site.', 'wc-graphflow' );
		$this->widget_id          = 'graphflow_recommendations_widget';
		$this->widget_name        = __( 'WooCommerce Graphflow Recommended Products', 'wc-graphflow' );
		$this->settings           = array(
			'title'  => array(
				'type'  => 'text',
				'std'   => __( 'Recommended For You', 'wc-graphflow' ),
				'label' => __( 'Title', 'wc-graphflow' )
			),
			'type'  => array(
				'type'  => 'select',
				'std'   => 'recommended',
				'options' => array( 'recommended' => 'Recommended', 'popular' => 'Popular', 'trending' => 'Trending' ),
				'label' => __( 'Recommendation Type', 'wc-graphflow' )
			),
			'period' => array(
				'type'  => 'number',
				'step'  => 1,
				'min'   => 7,
				'max'   => 180,
				'std'   => 30,
				'label' => __( "Period (in days) to use for 'trending' or 'popular'", 'wc-graphflow' )
			),
			'event'  => array(
				'type'  => 'select',
				'std'   => 'all',
				'options' => array( 'all' => 'All Activity', 'view' => 'Views Only', 'purchase' => 'Purchases Only' ),
				'label' => __( "Event types to use for 'trending' and 'popular'", 'wc-graphflow' )
			),
			'number' => array(
				'type'  => 'number',
				'step'  => 1,
				'min'   => 1,
				'max'   => 10,
				'std'   => 4,
				'label' => __( 'Number of products to show', 'wc-graphflow' )
			),
			'title_size' => array(
				'type'  => 'number',
				'step'  => 1,
				'min'   => 1,
				'max'   => 6,
				'std'   => 3,
				'label' => __( 'HTML heading size for title', 'wc-graphflow' )
			),
		);
		parent::__construct();
	}

	public function woocommerce_graphflow_recommendation_display_widget_ajax( $widget_id, $user_id, $product_id, $filters = '', $recType = 'widget' ) {

		$widget_options_all = get_option($this->option_name);
		if ( ! isset( $widget_options_all ) ) {
			return "";
		}
		$instance = $widget_options_all[$widget_id];

		// if the current user is logged in, use that id
		if ( is_user_logged_in() ) {
			$gf_user = get_current_user_id();
		} else {
			$gf_user = $user_id;
		}
		// widget instance options
		$title       = apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base );
		$number      = absint( $instance['number'] );
		$title_size  = absint( $instance['title_size'] );
		$type        = isset( $instance['type'] ) ? $instance['type'] : 'recommended';
		$period      = isset( $instance['period'] ) ? $instance['period'] : 30;
		$event       = isset( $instance['event'] ) ? $instance['event'] : 'all';
		$show_rating = apply_filters( 'woocommerce_graphflow_widget_show_ratings', false );

		$gf_products = array();

		if ( $type == 'recommended' ) {
			if ( isset( $product_id ) && ! empty( $product_id ) ) {
				$gf_recommendations = $GLOBALS['wc_graphflow']->get_api()->get_user_product_recommendations( $product_id, $gf_user, $number, $filters, $recType );
			} else {
				$gf_recommendations = $GLOBALS['wc_graphflow']->get_api()->get_user_recommendations( $gf_user, $number, $filters, $recType );
			}
		} else if ( $type == 'popular' || $type == 'trending' ) {
			$period = min( max( $period, 7 ), 180 );
			$fromTime = 'now-' . absint( $period ) . 'd/d';
			switch ($event) {
				case 'view':
					$event_type = 'view';
					break;
				case 'purchase':
					$event_type = 'purchase';
					break;
				default:
					$event_type = '';
			}
			$gf_recommendations = $GLOBALS['wc_graphflow']->get_api()->get_trending_recommendations( $gf_user, $number, $fromTime, '', $event_type, $type, $filters, $recType );
		}

		if ( isset( $gf_recommendations->result ) ) {
			foreach ( $gf_recommendations->result as $item ) {
				$gf_products[] = $item->itemId;
			}
		}

		$gf_recId = '';
		if ( isset( $gf_recommendations->recId ) ) {
			$gf_recId = $gf_recommendations->recId;
			$_REQUEST['gf_set_rec_id'] = $gf_recId;
		}

		$gf_products = array_unique( $gf_products );

		if ( count( $gf_products ) == 0 ) {
			return "";
		}

		ob_start();

		wc_get_template( 'widget/graphflow-recommended-products-widget.php', array(
			'gf_products'	 => $gf_products,
			'number'  		 => $number,
			'title_size'     => $title_size,
			'title'		     => $title,
			'gf_recId'		 => $gf_recId,
			'show_rating'	 => $show_rating
		), '', untrailingslashit( plugin_dir_path( WC_Graphflow::$file ) ) . '/templates/' );

		$content = ob_get_clean();
		return $content;
	}

	/**
	 * widget function.
	 *
	 * @see WP_Widget
	 * @access public
	 * @param array $args
	 * @param array $instance
	 * @return void
	 */
	public function widget( $args, $instance ) {

		if ( $this->get_cached_widget( $args ) ) {
			return;
		}

		if ( get_option( 'woocommerce_graphflow_use_ajax', 'yes' ) == 'yes' ) {
			$content = "<div class='graphflow_recommendations_placeholder woocommerce widget widget_products' gf_widget_id='" . $this->id . "'></div>";
		} else {
			$content = $this->widget_old( $args, $instance );
		}

		echo $content;
		$this->cache_widget( $args, $content );
	}

	public function widget_old( $args, $instance ) {

		if ( $this->get_cached_widget( $args ) )
			return;

		extract( $args );

		$gf_user 	 = $GLOBALS['wc_graphflow']->get_user_id();
		$title       = apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base );
		$number      = absint( $instance['number'] );
		$show_rating = apply_filters( 'woocommerce_graphflow_widget_show_ratings', false );

		$gf_products = array();

		if ( is_product() ) {
			global $product;
			$gf_recommendations = $GLOBALS['wc_graphflow']->get_api()->get_user_product_recommendations( $product->id, $gf_user, $number, '', 'widget' );
		} else {
			$gf_recommendations = $GLOBALS['wc_graphflow']->get_api()->get_user_recommendations( $gf_user, $number, '', 'widget' );
		}

		if ( isset( $gf_recommendations->result ) ) {
			foreach ( $gf_recommendations->result as $item ) {
				$gf_products[] = $item->itemId;
			}
		}

		$gf_recId = '';
		if ( isset( $gf_recommendations->recId ) ) {
			$gf_recId = $gf_recommendations->recId;
			$_REQUEST['gf_set_rec_id'] = $gf_recId;
		}

		$gf_products = array_unique( $gf_products );

		if ( count( $gf_products ) == 0 ) {
			return;
		}

		ob_start();

		wc_get_template( 'widget/graphflow-recommended-products-widget-nonajax.php', array(
			'gf_products'	 => $gf_products,
			'number'  		 => $number,
			'title'		     => $title,
			'gf_recId'		 => $gf_recId,
			'show_rating'	 => $show_rating,
			'before_widget'  => $before_widget,
			'after_widget'   => $after_widget,
			'before_title'   => $before_title,
			'after_title'    => $after_title,
		), '', untrailingslashit( plugin_dir_path( WC_Graphflow::$file ) ) . '/templates/' );

		$content = ob_get_clean();
		return $content;
	}

}

register_widget( 'WC_Widget_Graphflow_Recommended_Products' );
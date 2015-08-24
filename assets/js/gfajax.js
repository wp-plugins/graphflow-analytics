
//var gf_params;

jQuery( document ).ready(function( $ ) {

	// Make a copy of the woocommerce_params
	//gf_params = woocommerce_params;

	// Set or get the gf_anon cookie value and include in the object
	gf_params.gf_anon = graphflow.anonymousId();

	// Check if a gf_userid has been provided in woocommerce_params and set it if so
	if (typeof gf_params.gf_userid !== undefined && gf_params.gf_userid != null) {
		graphflow.userid(gf_params.gf_userid);
	} 

	// Ensure gf_user included in gf_params payload
	gf_params.gf_userid = graphflow.userid() || null;

	// Trigger track event if action and product provided
	if (typeof gf_params.gf_events !== undefined && gf_params.gf_events.length != 0) {
		graphflow.track('events', gf_params);
	}

	gf_params['action'] = 'graphflow_get_recs';

    $( ".graphflow_recommendations_placeholder" ).on("click", "a", function(e) {
        var that = $(this);
        if (that.is("[class*=product_type_]")) {
            if (!that.attr("data-product_id") || "undefined" == typeof gf_params)
                return !0;

            var gf_parent = that.closest(".graphflow_recommendations");
            var product_id = that.attr("data-product_id");
            var rec_id = that.attr("data-gf_rec_id") || gf_parent.attr('gf_recid');
            var click_params = {
                "gf_url" : gf_params.gf_url,
                "gf_userid" : gf_params.gf_userid || graphflow.userid(),
                "gf_client_key" : gf_params.gf_client_key,
                "gf_anon" : gf_params.gf_anon || graphflow.anonymousId(),
                "gf_events" : [
                    { "gf_product_id" : product_id, "gf_action" : "click", "gf_rec_id" : rec_id }
                ]
            };
            graphflow.track('events', click_params);
        }
    });

	// Find any Similar Product recommendation placeholders and
	// asynchronously request the html from admin-ajax.php
	$( ".graphflow_recommendations_placeholder" ).each(function(i){
		var that = $(this);
		var rec_params = JSON.parse(JSON.stringify(gf_params));
        // handle widget recs
		if (typeof $(this).attr( "gf_widget_id" ) !== undefined && $(this).attr( "gf_widget_id" ) != null) {
			rec_params.gf_widget_id = $(this).attr( "gf_widget_id" ) || null;
		}
		// handle shortcode recs
		if (typeof $(this).attr( "gf_is_shortcode" ) !== undefined && $(this).attr( "gf_is_shortcode" ) != null) {
			rec_params.gf_is_shortcode = $(this).attr( "gf_is_shortcode" ) || null;
			if (typeof $(this).attr( "gf_title" ) !== undefined && $(this).attr( "gf_title" ) != null) {
				rec_params.gf_title = $(this).attr( "gf_title" ) || null;
			}
			if (typeof $(this).attr( "gf_num" ) !== undefined && $(this).attr( "gf_num" ) != null) {
				rec_params.gf_num = $(this).attr( "gf_num" ) || null;
			}
			if (typeof $(this).attr( "gf_columns" ) !== undefined && $(this).attr( "gf_columns" ) != null) {
				rec_params.gf_columns = $(this).attr( "gf_columns" ) || null;
			}
			if (typeof $(this).attr( "gf_product_id" ) !== undefined && $(this).attr( "gf_product_id" ) != null) {
				rec_params.gf_product_id = $(this).attr( "gf_product_id" ) || null;
			}
			if (typeof $(this).attr( "gf_product_cat" ) !== undefined && $(this).attr( "gf_product_cat" ) != null) {
				rec_params.gf_product_cat = $(this).attr( "gf_product_cat" ) || null;
			}	
			if (typeof $(this).attr( "gf_product_tag" ) !== undefined && $(this).attr( "gf_product_tag" ) != null) {
				rec_params.gf_product_tag = $(this).attr( "gf_product_tag" ) || null;
			}
		}
		// Call API with gf_params on $this element
		$.ajax({
	        url: woocommerce_params.ajax_url,
	        type: 'POST',
	        data: rec_params
    	}).done(function(html){
			that.html( html );
    	});
	});


});
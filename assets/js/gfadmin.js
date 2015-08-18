jQuery( document ).ready(function( $ ) {

    // email preview toggle button
    $('a.toggle_editor').text( 'Show Email Preview' ).toggle( function() {
        $( this ).text( 'Hide Email Preview' ).closest('.graphflow_email_container').find('.graphflow_email_recommendations_preview').slideToggle();
        return false;
    }, function() {
        $( this ).text( 'Show Email Preview' ).closest('.graphflow_email_container').find('.graphflow_email_recommendations_preview').slideToggle();
        return false;
    } );

    // tooltips
    $( '.dashicons-editor-help' ).tooltip({ placement: 'bottom', animation: true, html: true, delay: { show: 300, hide: 100 } });
});
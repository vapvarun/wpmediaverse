/**
 * Tag management admin: select-all checkbox toggle. Replaces the former inline
 * <script>. jQuery is a dependency (the page already loads it in admin).
 *
 * @package WPMediaVerse
 */
jQuery( function ( $ ) {
	$( '#cb-select-all-1, #cb-select-all-2' ).on( 'change', function () {
		var checked = $( this ).prop( 'checked' );
		$( 'input[name="tag_ids[]"]' ).prop( 'checked', checked );
	} );
} );

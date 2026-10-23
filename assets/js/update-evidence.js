/* Keep horizontal table scrolling operable in browsers without native arrow support. */
( function() {
	'use strict';

	document.querySelectorAll( '.keel-defaults-update-table-scroll' ).forEach( function( region ) {
		region.addEventListener( 'keydown', function( event ) {
			if ( event.target !== region || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey ) {
				return;
			}
			if ( 'ArrowLeft' !== event.key && 'ArrowRight' !== event.key ) {
				return;
			}
			const before = region.scrollLeft;
			// Physical directions work for both positive LTR and negative RTL offsets.
			region.scrollLeft += 'ArrowRight' === event.key ? 40 : -40;
			if ( region.scrollLeft !== before ) {
				event.preventDefault();
			}
		} );
	} );
}() );

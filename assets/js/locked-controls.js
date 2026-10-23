/*
 * Refuse changes to a control that wp-config.php or network policy has settled.
 *
 * Shared by Settings → Keel and the network policy screen, which had two copies
 * of this that had already drifted apart in one detail.
 *
 * A locked control is aria-disabled rather than disabled, so it stays focusable
 * and can announce why it cannot be changed. That also makes it operable, so
 * refuse the change here. The server refuses it too — keel_defaults_sanitize_site()
 * keeps the stored value for any locked key — so this is the courtesy, not the
 * enforcement.
 */
( function() {
	'use strict';

	// A policy may lock a dependent setting hidden by its controller. Summary
	// links must still reveal that setting and move keyboard focus to its control.
	document.querySelectorAll( '.keel-lock-summary a' ).forEach( function( link ) {
		link.addEventListener( 'click', function( event ) {
			if ( event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ) {
				return;
			}
			const target = document.getElementById( link.hash.slice( 1 ) );
			const row = target && target.closest( '.keel-dep-item, tr' );
			if ( ! row ) {
				return;
			}
			row.style.display = '';
			document.querySelectorAll( '[aria-controls]' ).forEach( function( controller ) {
				if ( row.id && controller.getAttribute( 'aria-controls' ).split( ' ' ).indexOf( row.id ) !== -1 ) {
					controller.setAttribute( 'aria-expanded', 'true' );
				}
			} );
			const control = row.querySelector( '[data-keel-locked]' );
			if ( control ) {
				event.preventDefault();
				window.location.hash = link.hash;
				target.scrollIntoView();
				window.requestAnimationFrame( function() {
					control.focus( { preventScroll: true } );
				} );
			}
		} );
	} );

	document.querySelectorAll( '[data-keel-locked]' ).forEach( function( control ) {
		const initialValue = control.value;
		const initialChecked = control.checked;

		control.addEventListener( 'mousedown', function( event ) {
			event.preventDefault();
		} );

		control.addEventListener( 'click', function( event ) {
			event.preventDefault();
		} );

		control.addEventListener( 'keydown', function( event ) {
			if ( 'Tab' === event.key || 'Escape' === event.key || event.altKey || event.ctrlKey || event.metaKey ) {
				return;
			}
			// Space toggles a checkbox; navigation keys should still scroll the page.
			// Other controls use arrows, Home/End and Page Up/Down to edit values.
			if ( 'checkbox' !== control.type || ' ' === event.key ) {
				event.preventDefault();
			}
		} );

		control.addEventListener( 'change', function() {
			if ( 'checkbox' === control.type ) {
				control.checked = initialChecked;
			} else {
				control.value = initialValue;
			}
		} );
	} );
}() );

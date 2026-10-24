/* Keep horizontal table scrolling operable in browsers without native arrow support. */
( function() {
	'use strict';

	const notice = document.querySelector( '.keel-update-policy-notice' );
	const heading = document.querySelector( '.wp-current-version' );
	if ( notice && heading ) {
		heading.before( notice );
	}

	if ( new URLSearchParams( window.location.search ).get( 'keel-update-diagnostics' ) === '1' ) {
		const openDiagnostics = function() {
			const trigger = document.querySelector( 'button[aria-controls="health-check-accordion-block-keel_update_diagnostics"]' );
			if ( ! trigger ) {
				return false;
			}
			const passed = document.querySelector( '.site-health-view-passed' );
			if ( passed && passed.getAttribute( 'aria-expanded' ) === 'false' ) {
				passed.click();
			}
			if ( trigger.getAttribute( 'aria-expanded' ) === 'false' ) {
				trigger.click();
			}
			trigger.focus();
			trigger.scrollIntoView();
			return true;
		};
		if ( ! openDiagnostics() ) {
			const observer = new MutationObserver( function() {
				if ( openDiagnostics() ) {
					observer.disconnect();
				}
			} );
			observer.observe( document.body, { childList: true, subtree: true } );
			window.setTimeout( function() {
				observer.disconnect();
			}, 30000 );
		}
	}

	// Delegated: Site Health inserts its diagnostic tables after this script runs.
	document.addEventListener( 'keydown', function( event ) {
		const region = event.target;
		if ( ! ( region instanceof Element ) || ! region.classList.contains( 'keel-defaults-update-table-scroll' ) ) {
			return;
		}
		if ( event.altKey || event.ctrlKey || event.metaKey || event.shiftKey ) {
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
}() );

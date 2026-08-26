/*
 * Settings → Keel: dependent-row hiding, and the menu-width slider preview.
 *
 * Both were inline <script> blocks built by PHP. The per-field values the range
 * slider needs — its labels and its pixel widths — now arrive as JSON in
 * data-keel-range-labels and data-keel-range-widths on the input itself, so this
 * file is static, cacheable, and works for any number of range fields rather
 * than being re-emitted once per field.
 */
( function () {
	'use strict';

	/* --- The menu-width slider's readout and live preview. --- */

	function parseJSONAttribute( element, name ) {
		try {
			return JSON.parse( element.getAttribute( name ) || '[]' );
		} catch ( e ) {
			return [];
		}
	}

	document.querySelectorAll( 'input[type="range"][data-keel-range]' ).forEach( function ( input ) {
		var output = document.getElementById( input.getAttribute( 'data-keel-range' ) );
		var labels = parseJSONAttribute( input, 'data-keel-range-labels' );
		var widths = parseJSONAttribute( input, 'data-keel-range-widths' );

		if ( ! output || ! document.body || ! labels.length ) {
			return;
		}

		function pos() {
			return parseInt( input.value, 10 ) || 0;
		}

		// Cheap: runs live while dragging — only the readout text changes.
		function updateLabel() {
			var text = labels[ pos() ] || labels[0];
			output.textContent = text;
			// The value a screen reader announces has to move with the word, or
			// the slider goes on saying whatever it was rendered at.
			input.setAttribute( 'aria-valuetext', text );
		}

		// Expensive: reflows the whole admin layout and reads a computed style,
		// so it runs only when the drag settles (release/keyboard), not on every
		// 'input' tick — otherwise the slider is janky.
		function applyPreview() {
			document.body.style.setProperty( '--keel-menu-preview-width', ( widths[ pos() ] || widths[0] ) + 'px' );

			var adminMenu = document.getElementById( 'adminmenu' );
			if ( adminMenu ) {
				document.body.style.setProperty( '--keel-menu-preview-bg', window.getComputedStyle( adminMenu ).backgroundColor );
			}

			document.body.classList.add( 'keel-menu-width-preview' );
		}

		input.addEventListener( 'input', updateLabel );
		input.addEventListener( 'change', applyPreview );
		input.addEventListener( 'pointerup', applyPreview );
		input.addEventListener( 'keyup', function ( event ) {
			if ( [ 'ArrowLeft', 'ArrowRight', 'Home', 'End', 'PageUp', 'PageDown' ].indexOf( event.key ) !== -1 ) {
				applyPreview();
			}
		} );
	} );

	/* --- Hide rows whose setting is moot given a controlling setting's value. --- */

	function controllerValue( name ) {
		var els = document.querySelectorAll( '[name="keel_settings[' + name + ']"]' );
		if ( ! els.length ) {
			return null;
		}

		var el = els[0];

		if ( 'checkbox' === el.type ) {
			return el.checked ? el.value : 'no';
		}

		if ( 'radio' === el.type ) {
			var picked = document.querySelector( '[name="keel_settings[' + name + ']"]:checked' );
			return picked ? picked.value : '';
		}

		return el.value;
	}

	/*
	 * Any element carrying the attribute, not just table rows.
	 *
	 * Dependent settings render two ways: a plain setting is a <tr>, and a
	 * setting inside a group — the three XML-RPC method controls — is a <div>
	 * sharing one row with its siblings. Selecting only 'tr' meant the grouped
	 * ones never showed or hid until a reload, and never got the aria-controls
	 * and aria-expanded wiring at all, which is the half a screen reader needs.
	 */
	document.querySelectorAll( '[data-keel-dep-field]' ).forEach( function ( row ) {
		var field = row.getAttribute( 'data-keel-dep-field' );
		var hide  = row.getAttribute( 'data-keel-dep-hide' );
		var ctrls = document.querySelectorAll( '[name="keel_settings[' + field + ']"]' );

		if ( ! ctrls.length ) {
			return;
		}

		/*
		 * Tell assistive technology which control governs this row, and whether
		 * the row is showing. Without aria-controls the only link between the two
		 * is a data attribute this script reads, which no screen reader can see —
		 * so a row appearing had no announced relationship to the choice that
		 * produced it.
		 */
		ctrls.forEach( function ( c ) {
			var owned = ( c.getAttribute( 'aria-controls' ) || '' ).split( ' ' ).filter( Boolean );
			if ( row.id && -1 === owned.indexOf( row.id ) ) {
				owned.push( row.id );
				c.setAttribute( 'aria-controls', owned.join( ' ' ) );
			}
		} );

		function sync() {
			var hidden = controllerValue( field ) === hide;
			row.style.display = hidden ? 'none' : '';
			ctrls.forEach( function ( c ) {
				c.setAttribute( 'aria-expanded', hidden ? 'false' : 'true' );
			} );
		}

		ctrls.forEach( function ( c ) {
			c.addEventListener( 'change', sync );
		} );

		sync();
	} );
} )();

<?php
/**
 * Shared inputs for site and network settings screens.
 *
 * Screens own authorization, rows, help, and save handling. This module owns
 * control markup. Network ranges remain selects; site ranges retain their
 * preview slider. The presentation context never changes stored values.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve discrete values to translated labels for either screen.
 *
 * @param array $field Schema field.
 * @param array $s     Display strings.
 * @return array<string|int,string>
 */
function keel_defaults_control_choices( array $field, array $s ) {
	if ( 'multiselect' === $field['type'] && ! isset( $field['choices'] ) ) {
		return keel_defaults_exemptable_roles();
	}

	$range   = 'range' === $field['type'];
	$values  = $range ? array_values( $field['values'] ) : $field['choices'];
	$labels  = $range && isset( $s['labels'] ) ? array_values( $s['labels'] ) : array();
	$choices = array();

	foreach ( $values as $i => $value ) {
		$choices[ (string) $value ] = $range
			? ( isset( $labels[ $i ] ) ? $labels[ $i ] : (string) $value )
			: ( isset( $s['choices'][ $value ] ) ? $s['choices'][ $value ] : (string) $value );
	}

	return $choices;
}

/**
 * Render an input without its screen's row, help, or lock explanation.
 *
 * Site controls retain hidden stored preferences under configuration locks.
 * Network forms use their own network policy sanitizer and presentation.
 *
 * @param string $key         Schema key.
 * @param string $name        Form input name.
 * @param mixed  $value       Stored/effective preference supplied by the screen.
 * @param array  $field       Schema field.
 * @param array  $s           Display strings.
 * @param string $statement   Toggle statement.
 * @param string $describedby Description IDs.
 * @param bool   $locked      Whether the control is locked.
 * @param string $context     Site or network presentation.
 * @return void
 */
function keel_defaults_render_control( $key, $name, $value, $field, $s, $statement, $describedby, $locked = false, $context = 'site' ) {
	$site  = 'network' !== $context;
	$type  = isset( $field['type'] ) ? $field['type'] : 'toggle';
	$label = isset( $s['label'] ) ? $s['label'] : $key;
	$shown = $site && $locked ? keel_defaults_config_locked_value( $key, $value ) : $value;
	$aria  = '' !== $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : '';
	$lock  = $locked ? ' aria-disabled="true" data-keel-locked="1"' : '';

	if ( $site && $locked && in_array( $type, array( 'select', 'number' ), true ) ) {
		printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), esc_attr( $value ) );
	}

	if ( 'toggle' === $type ) {
		if ( $site ) {
			printf( '<fieldset><legend class="screen-reader-text"><span>%s</span></legend>', esc_html( $label ) );
		}
		if ( $site && $locked && 'yes' === $value ) {
			printf( '<input type="hidden" name="%s" value="yes" />', esc_attr( $name ) );
		}
		keel_defaults_render_checkbox( $name, $shown, $statement, $locked, $describedby );
		if ( $site ) {
			echo '</fieldset>';
		}
		return;
	}

	if ( 'range' === $type && $site ) {
		keel_defaults_render_range_field( $key, $name, $value, $field, $s, $label, $describedby, $locked );
		return;
	}

	if ( 'number' === $type ) {
		printf( '<input type="number" name="%s" value="%s" min="%d"', esc_attr( $name ), esc_attr( $shown ), isset( $field['min'] ) ? (int) $field['min'] : 0 );
		if ( isset( $field['max'] ) || ! $site ) {
			printf( ' max="%d"', isset( $field['max'] ) ? (int) $field['max'] : 3650 );
		}
		if ( $site ) {
			printf( ' step="1" aria-label="%s"', esc_attr( $label ) );
		}
		printf( ' class="small-text"%s%s%s />', $aria, $lock, $locked ? ' readonly' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped attributes or fixed literals.
		if ( $site && ! empty( $s['unit'] ) ) {
			printf( '<span class="keel-unit" id="%s" style="margin-inline-start:6px;">%s</span>', esc_attr( 'keel-' . $key . '-unit' ), esc_html( $s['unit'] ) );
		} elseif ( ! $site ) {
			echo ' ' . esc_html( isset( $s['unit'] ) ? $s['unit'] : '' );
		}
		return;
	}

	$choices = keel_defaults_control_choices( $field, $s );
	if ( 'multiselect' === $type ) {
		$chosen = array_map( 'strval', (array) $value );
		if ( $site ) {
			printf( '<fieldset%s><legend class="screen-reader-text"><span>%s</span></legend>', $aria, esc_html( $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- aria is escaped above.
			if ( empty( $choices ) ) {
				echo '<p class="description">' . esc_html__( 'No low-privilege roles are available to exempt.', 'keel-defaults' ) . '</p>';
			}
		}
		foreach ( $choices as $choice => $choice_label ) {
			printf(
				'<label style="%s"><input type="checkbox" name="%s[]" value="%s" %s%s /> %s</label>',
				esc_attr( $site ? 'display:block;margin-bottom:6px;' : 'margin-inline-end:12px;' ),
				esc_attr( $name ),
				esc_attr( $choice ),
				checked( in_array( (string) $choice, $chosen, true ), true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal.
				$site ? $lock : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal; network role controls retain their existing attributes.
				esc_html( $choice_label )
			);
		}
		if ( $site ) {
			echo '</fieldset>';
		}
		return;
	}

	printf( '<select name="%s"', esc_attr( $name ) );
	if ( $site ) {
		printf( ' aria-label="%s"', esc_attr( $label ) );
	}
	echo $aria . $lock . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped attributes or fixed literals.
	foreach ( $choices as $choice => $choice_label ) {
		printf( '<option value="%s" %s>%s</option>', esc_attr( $choice ), selected( (string) $choice, (string) $shown, false ), esc_html( $choice_label ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a fixed literal.
	}
	echo '</select>';
}

/**
 * Echo a checkbox + its "true when checked" statement (the <label> only).
 *
 * @param string $name        Field input name.
 * @param mixed  $value       Current value.
 * @param string $statement   The "true when checked" statement (already translated).
 * @param bool   $disabled    Whether to mark the checkbox as locked (focusable).
 * @param string $describedby Space-separated ids for aria-describedby (help/lock).
 */
function keel_defaults_render_checkbox( $name, $value, $statement, $disabled = false, $describedby = '' ) {
	$aria = ( '' !== $describedby ) ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : '';

	/*
	 * aria-disabled, not disabled.
	 *
	 * A `disabled` control is removed from the tab sequence, so a screen-reader
	 * user never lands on it — and the aria-describedby wiring that announces
	 * *why* it cannot be changed is announced on focus, which never happens. The
	 * reason was written first in that attribute on purpose and then could not be
	 * heard. `aria-disabled` keeps the control focusable and announced as
	 * unavailable, so the explanation reaches the person it was written for.
	 *
	 * Safe only because the lock is now enforced on save in
	 * keel_defaults_sanitize_site(): a focusable control is a submittable one, and
	 * before that enforcement this would have let a locked value be written.
	 */
	$locked_attrs = $disabled ? ' aria-disabled="true" data-keel-locked="1"' : '';

	printf(
		'<label><input type="checkbox" name="%s" value="yes" %s%s%s /> %s</label>',
		esc_attr( $name ),
		checked( 'yes', $value, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a fixed literal.
		$locked_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal.
		$aria, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr().
		wp_kses( $statement, array( 'code' => array() ) )
	);
}

/**
 * Render a range (slider) field, with its live admin-menu preview.
 *
 * Extracted from the settings template, where it was 105 of that function's 338
 * lines and the sole reason the file reached thirteen levels of indentation. The
 * other field types run ten to twenty lines each; this one carried a whole
 * feature — the slider, the preview stylesheet it toggles, and the script that
 * drives them — and inlining all of it put CSS at a depth where the surrounding
 * PHP was no longer legible.
 *
 * The stylesheet and the script have since moved out of PHP altogether, into
 * assets/css/settings.css and assets/js/settings.js. What is left here is the
 * markup, and the three data- attributes that carry this field's labels and
 * pixel widths to the script — which is why the script is now static and works
 * for any number of range fields instead of being re-emitted once per field.
 *
 * @param string $key         Schema key, used to build element ids.
 * @param string $name        Input name attribute.
 * @param mixed  $value       Stored value.
 * @param array  $field       Schema field.
 * @param array  $s           Display strings for this field.
 * @param string $label       Field label.
 * @param string $describedby Space-separated ids for aria-describedby.
 * @param bool   $locked      Whether wp-config.php or network policy has settled this.
 * @return void
 */
function keel_defaults_render_range_field( $key, $name, $value, $field, $s, $label, $describedby, $locked = false ) {
	$rvalues = array_map( 'strval', array_values( $field['values'] ) );
	$rlabels = array_values( isset( $s['labels'] ) ? $s['labels'] : array() );
	$rcur    = array_search( (string) $value, $rvalues, true );
	$rcur    = ( false === $rcur ) ? 0 : (int) $rcur;
	$rpx     = array_map(
		static function ( $v ) {
			return ( 'default' === $v ) ? 160 : (int) $v;
		},
		$rvalues
	);
	$rid     = $key; // raw schema slug; escaped per output context below.
	?>
	<fieldset>
		<legend class="screen-reader-text"><?php echo esc_html( $label ); ?></legend>
		<?php
		/*
		 * aria-valuetext, because the value is a position and the meaning is a
		 * word. Without it a screen reader announces this slider as "2" — the
		 * index — and the site owner is choosing between Default, Narrow and
		 * Wide, none of which are numbers. The visible <output> carries the word
		 * for sighted users; this is the same information for everyone else, and
		 * it is announced as the *value* rather than as a description.
		 */
		?>
		<input type="range" min="0" max="<?php echo (int) ( count( $rvalues ) - 1 ); ?>" step="1"
			name="<?php echo esc_attr( $name ); ?>"
			id="<?php echo esc_attr( $rid ); ?>-range"
			value="<?php echo (int) $rcur; ?>"
			list="<?php echo esc_attr( $rid ); ?>-stops"
			aria-label="<?php echo esc_attr( $label ); ?>"
			aria-valuetext="<?php echo esc_attr( $rlabels[ $rcur ] ); ?>"
			<?php
			/*
			 * A locked slider has to say so to the script as well as to a reader.
			 * Selects, numbers and toggles already emitted this; the slider and
			 * the role checkboxes did not, so a network-locked control announced
			 * itself as locked and then previewed and accepted edits anyway. The
			 * server refuses the value either way — this is the screen agreeing
			 * with what the server will do.
			 */
			echo $locked ? ' aria-disabled="true" data-keel-locked="1"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal.
			?>
			data-keel-range="<?php echo esc_attr( $rid ); ?>-output"
			data-keel-range-labels="<?php echo esc_attr( wp_json_encode( $rlabels ) ); ?>"
			data-keel-range-widths="<?php echo esc_attr( wp_json_encode( array_values( $rpx ) ) ); ?>"
			<?php echo '' !== $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr(). ?> style="vertical-align:middle;max-width:240px;" />
		<datalist id="<?php echo esc_attr( $rid ); ?>-stops">
			<?php foreach ( $rlabels as $ri => $rl ) : ?>
				<option value="<?php echo (int) $ri; ?>" label="<?php echo esc_attr( $rl ); ?>"></option>
			<?php endforeach; ?>
		</datalist>
		<?php
		/*
		 * aria-live="off", deliberately, on an element whose implicit live
		 * politeness is "polite". The word is already announced as the slider's
		 * own value through aria-valuetext, so leaving this a live region made
		 * every arrow-key press say it twice — which is the "stream of noise"
		 * this control was suspected of and the reason to check. It stays a
		 * visible <output> for sighted users and is silent to a screen reader.
		 */
		?>
		<output for="<?php echo esc_attr( $rid ); ?>-range" id="<?php echo esc_attr( $rid ); ?>-output" aria-live="off" style="margin-inline-start:10px;font-weight:600;"><?php echo esc_html( $rlabels[ $rcur ] ); ?></output>
	</fieldset>
	<?php
}

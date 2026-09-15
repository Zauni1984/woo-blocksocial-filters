/**
 * Variation swatches.
 *
 * The original WooCommerce <select> stays in the DOM and remains the single
 * source of truth: swatches write to it and read availability back from it, so
 * the stock WooCommerce variation script keeps working untouched.
 */
( function ( $ ) {
	'use strict';

	var settings = window.bsfSwatchData || {};

	function each( list, callback ) {
		Array.prototype.forEach.call( list, callback );
	}

	/**
	 * Mark swatches whose value is no longer offered by the select.
	 */
	function refreshAvailability( wrap ) {
		var select = wrap.querySelector( 'select' );
		var group = wrap.querySelector( '.bsf-swatches' );

		if ( ! select || ! group ) {
			return;
		}

		var available = {};

		each( select.options, function ( option ) {
			if ( option.value && ! option.disabled ) {
				available[ option.value ] = true;
			}
		} );

		var anyOffered = Object.keys( available ).length > 0;

		each( group.querySelectorAll( '.bsf-swatch-item' ), function ( item ) {
			var value = item.dataset.value;
			var unavailable = anyOffered && ! available[ value ];

			item.classList.toggle( 'is-unavailable', unavailable );
			item.setAttribute( 'aria-disabled', unavailable ? 'true' : 'false' );

			var selected = select.value === value;

			item.classList.toggle( 'is-selected', selected );
			item.setAttribute( 'aria-checked', selected ? 'true' : 'false' );
		} );
	}

	/**
	 * Wire one attribute group.
	 */
	function initWrap( wrap ) {
		if ( wrap.dataset.bsfReady === '1' ) {
			return;
		}

		wrap.dataset.bsfReady = '1';

		var select = wrap.querySelector( 'select' );
		var group = wrap.querySelector( '.bsf-swatches' );

		if ( ! select || ! group ) {
			return;
		}

		group.setAttribute( 'data-oos', settings.outOfStock || 'crossed' );
		group.setAttribute( 'data-tooltips', settings.tooltips ? '1' : '0' );

		group.addEventListener( 'click', function ( event ) {
			var item = event.target.closest( '.bsf-swatch-item' );

			if ( ! item ) {
				return;
			}

			event.preventDefault();

			if ( item.classList.contains( 'is-unavailable' ) ) {
				return;
			}

			var value = item.dataset.value;

			// Clicking the active swatch clears the choice, which is what the
			// "Clear" link does in the default WooCommerce form.
			select.value = select.value === value ? '' : value;

			if ( $ ) {
				$( select ).trigger( 'change' );
			} else {
				select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}

			refreshAvailability( wrap );
		} );

		group.addEventListener( 'keydown', function ( event ) {
			if ( event.key !== 'ArrowRight' && event.key !== 'ArrowLeft' ) {
				return;
			}

			var items = Array.prototype.slice.call( group.querySelectorAll( '.bsf-swatch-item:not(.is-unavailable)' ) );
			var index = items.indexOf( document.activeElement );

			if ( index === -1 ) {
				return;
			}

			event.preventDefault();

			var next = event.key === 'ArrowRight' ? index + 1 : index - 1;

			if ( items[ next ] ) {
				items[ next ].focus();
			}
		} );

		select.addEventListener( 'change', function () {
			refreshAvailability( wrap );
		} );

		refreshAvailability( wrap );
	}

	function initAll( scope ) {
		each( ( scope || document ).querySelectorAll( '.bsf-swatch-wrap' ), initWrap );
	}

	function boot() {
		initAll( document );

		if ( ! $ ) {
			return;
		}

		// WooCommerce rewrites the option lists as the shopper narrows down a
		// variation; mirror each of those passes onto the swatches.
		$( document.body ).on(
			'woocommerce_update_variation_values wc_variation_form check_variations woocommerce_variation_has_changed reset_data',
			function () {
				window.setTimeout( function () {
					initAll( document );
					each( document.querySelectorAll( '.bsf-swatch-wrap' ), refreshAvailability );
				}, 0 );
			}
		);

		$( document.body ).on( 'click', '.reset_variations', function () {
			window.setTimeout( function () {
				each( document.querySelectorAll( '.bsf-swatch-wrap' ), refreshAvailability );
			}, 0 );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}( window.jQuery ) );

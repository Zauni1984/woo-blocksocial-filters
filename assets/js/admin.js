/**
 * Admin scripts: colour pickers, media fields, the filter set builder and the
 * batched index runner.
 */
( function ( $ ) {
	'use strict';

	var admin = window.bsfAdmin || {};
	var i18n = admin.i18n || {};

	/* ---------------------------------------------------------------------
	 * Colour pickers and media fields
	 * ------------------------------------------------------------------ */

	function initColorPickers( scope ) {
		if ( ! $.fn.wpColorPicker ) {
			return;
		}

		$( '.bsf-color-picker', scope || document ).each( function () {
			var $input = $( this );

			if ( $input.data( 'bsfColor' ) ) {
				return;
			}

			$input.data( 'bsfColor', true ).wpColorPicker();
		} );
	}

	function initMediaFields() {
		$( document ).on( 'click', '.bsf-image-field__select', function ( event ) {
			event.preventDefault();

			var $field = $( this ).closest( '.bsf-image-field' );
			var frame = wp.media( {
				title: i18n.selectImage || 'Select image',
				button: { text: i18n.useImage || 'Use image' },
				multiple: false
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var url = attachment.sizes && attachment.sizes.thumbnail
					? attachment.sizes.thumbnail.url
					: attachment.url;

				$field.find( 'input[type="hidden"]' ).val( attachment.id );
				$field.find( '.bsf-image-field__preview' ).html(
					$( '<img>' ).attr( { src: url, alt: '', width: 48, height: 48 } )
				);
			} );

			frame.open();
		} );

		$( document ).on( 'click', '.bsf-image-field__remove', function ( event ) {
			event.preventDefault();

			var $field = $( this ).closest( '.bsf-image-field' );

			$field.find( 'input[type="hidden"]' ).val( '' );
			$field.find( '.bsf-image-field__preview' ).empty();
		} );
	}

	/* ---------------------------------------------------------------------
	 * Filter rows
	 * ------------------------------------------------------------------ */

	function applySourceVisibility( $row ) {
		var source = $row.find( '.bsf-source' ).val();
		var needsTaxonomy = source === 'attribute' || source === 'taxonomy';
		var needsMeta = source === 'numeric';

		$row.find( '.bsf-field--taxonomy' ).toggle( needsTaxonomy );
		$row.find( '.bsf-field--meta' ).toggle( needsMeta );
	}

	function initRows() {
		var $rows = $( '#bsf-filter-rows' );

		if ( ! $rows.length ) {
			return;
		}

		$rows.find( '.bsf-filter-row' ).each( function () {
			applySourceVisibility( $( this ) );
		} );

		$( document ).on( 'change', '.bsf-source', function () {
			applySourceVisibility( $( this ).closest( '.bsf-filter-row' ) );
		} );

		$( document ).on( 'click', '.bsf-filter-row__toggle', function ( event ) {
			event.preventDefault();
			$( this ).closest( '.bsf-filter-row' ).toggleClass( 'is-open' );
		} );

		$( document ).on( 'click', '.bsf-filter-row__remove', function ( event ) {
			event.preventDefault();

			if ( ! window.confirm( i18n.confirmDelete || 'Delete this filter?' ) ) {
				return;
			}

			$( this ).closest( '.bsf-filter-row' ).remove();
		} );

		$( '#bsf-add-filter' ).on( 'click', function ( event ) {
			event.preventDefault();

			var template = $( '#tmpl-bsf-filter-row' ).html();

			if ( ! template ) {
				return;
			}

			var index = $rows.find( '.bsf-filter-row' ).length;
			var markup = template.replace( /__INDEX__/g, String( index ) );
			var $row = $( markup );

			$row.addClass( 'is-open' );
			$rows.append( $row );

			applySourceVisibility( $row );
			initColorPickers( $row );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Index runner
	 * ------------------------------------------------------------------ */

	function request( path, body ) {
		return window.fetch( admin.rest + path, {
			method: body ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': admin.nonce
			},
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}

			return response.json();
		} );
	}

	function initIndexer() {
		var $start = $( '[data-bsf-index-start]' );

		if ( ! $start.length ) {
			return;
		}

		var $cancel = $( '[data-bsf-index-cancel]' );
		var $progress = $( '[data-bsf-progress]' );
		var $bar = $progress.find( '.bsf-progress__bar span' );
		var $text = $( '[data-bsf-progress-text]' );
		var $status = $( '[data-bsf-index-status]' );
		var cancelled = false;

		function paint( state ) {
			var total = Math.max( 1, parseInt( state.total, 10 ) || 1 );
			var done = parseInt( state.processed, 10 ) || 0;
			var percent = Math.min( 100, Math.round( ( done / total ) * 100 ) );

			$bar.css( 'width', percent + '%' );
			$text.text( done + ' / ' + total + '  ·  ' + percent + '%' );
			$status.text( state.status );
		}

		function step() {
			if ( cancelled ) {
				return;
			}

			request( '/index/run', { action: 'batch' } )
				.then( function ( state ) {
					paint( state );

					if ( state.status === 'running' ) {
						window.setTimeout( step, 50 );
						return;
					}

					finish( state.status === 'done' ? ( i18n.done || 'Index complete.' ) : ( i18n.cancelled || 'Cancelled.' ) );
				} )
				.catch( function () {
					finish( i18n.failed || 'The index build failed.' );
				} );
		}

		function finish( message ) {
			$start.prop( 'disabled', false );
			$cancel.prop( 'hidden', true );
			$text.text( message );
		}

		$start.on( 'click', function ( event ) {
			event.preventDefault();

			cancelled = false;
			$start.prop( 'disabled', true );
			$cancel.prop( 'hidden', false );
			$progress.prop( 'hidden', false );
			$text.text( i18n.building || 'Building index…' );

			request( '/index/run', { action: 'start' } )
				.then( function ( state ) {
					paint( state );
					step();
				} )
				.catch( function () {
					finish( i18n.failed || 'The index build failed.' );
				} );
		} );

		$cancel.on( 'click', function ( event ) {
			event.preventDefault();

			cancelled = true;

			request( '/index/run', { action: 'cancel' } ).then( function ( state ) {
				paint( state );
				finish( i18n.cancelled || 'Cancelled.' );
			} );
		} );
	}

	$( function () {
		initColorPickers();
		initMediaFields();
		initRows();
		initIndexer();
	} );
}( window.jQuery ) );

/**
 * BlockSocial Filters — front end runtime.
 *
 * No jQuery, no build step. The panel keeps a small state object mirroring the
 * URL encoding done in PHP, so every interaction can produce a shareable link
 * whether or not AJAX is enabled.
 */
( function () {
	'use strict';

	var config = window.bsfData || {};
	var RANGE_GLUE = config.rangeGlue || '..';
	var SORT_PARAM = 'ordr';
	var SEARCH_PARAM = 'srch';

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	function qsa( selector, scope ) {
		return Array.prototype.slice.call( ( scope || document ).querySelectorAll( selector ) );
	}

	function debounce( fn, wait ) {
		var timer = null;

		return function () {
			var args = arguments;
			var self = this;

			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				fn.apply( self, args );
			}, wait );
		};
	}

	function trimNumber( value ) {
		var out = parseFloat( value );

		if ( isNaN( out ) ) {
			return '';
		}

		return String( Math.round( out * 1e6 ) / 1e6 );
	}

	/* ---------------------------------------------------------------------
	 * State: URL <-> object
	 * ------------------------------------------------------------------ */

	/**
	 * Read the selections encoded in a URL.
	 */
	function readState( panel, url ) {
		var prefix = panel.dataset.bsfPrefix || config.prefix || 'f_';
		var state = {};
		var parsed;

		try {
			parsed = new URL( url, window.location.origin );
		} catch ( error ) {
			return state;
		}

		parsed.searchParams.forEach( function ( value, key ) {
			if ( key === SEARCH_PARAM ) {
				state[ SEARCH_PARAM ] = { type: 'text', text: value };
				return;
			}

			if ( key === SORT_PARAM ) {
				state.__sort = value;
				return;
			}

			if ( key.indexOf( prefix ) !== 0 ) {
				return;
			}

			var name = key.slice( prefix.length );

			if ( value.indexOf( RANGE_GLUE ) !== -1 ) {
				var bounds = value.split( RANGE_GLUE );

				state[ name ] = {
					type: 'range',
					min: bounds[ 0 ] === '' ? null : bounds[ 0 ],
					max: bounds[ 1 ] === '' ? null : bounds[ 1 ]
				};

				return;
			}

			state[ name ] = {
				type: 'terms',
				values: value.split( ',' ).filter( Boolean )
			};
		} );

		if ( ( panel.dataset.bsfUrlmode || config.urlMode ) === 'pretty' ) {
			readPrettyState( panel, parsed, state );
		}

		return state;
	}

	/**
	 * Recover selections from readable path segments.
	 */
	function readPrettyState( panel, parsed, state ) {
		var separator = panel.dataset.bsfSeparator || config.separator || '-';
		var base = panel.dataset.bsfBase || '';
		var basePath = '/';

		try {
			basePath = new URL( base, window.location.origin ).pathname;
		} catch ( error ) {
			basePath = '/';
		}

		var rest = parsed.pathname.indexOf( basePath ) === 0
			? parsed.pathname.slice( basePath.length )
			: '';

		rest.split( '/' ).filter( Boolean ).forEach( function ( segment ) {
			var keys = Object.keys( panelKeys( panel ) );
			var matched = '';

			keys.forEach( function ( key ) {
				if ( segment.indexOf( key + separator ) === 0 && key.length > matched.length ) {
					matched = key;
				}
			} );

			if ( ! matched ) {
				return;
			}

			var value = segment.slice( matched.length + separator.length );

			if ( ! value ) {
				return;
			}

			if ( value.indexOf( RANGE_GLUE ) !== -1 ) {
				var bounds = value.split( RANGE_GLUE );

				state[ matched ] = {
					type: 'range',
					min: bounds[ 0 ] === '' ? null : bounds[ 0 ],
					max: bounds[ 1 ] === '' ? null : bounds[ 1 ]
				};

				return;
			}

			state[ matched ] = {
				type: 'terms',
				values: value.split( ',' ).filter( Boolean )
			};
		} );
	}

	/**
	 * Map of filter keys rendered inside a panel.
	 */
	function panelKeys( panel ) {
		var keys = {};

		qsa( '.bsf-filter[data-bsf-key]', panel ).forEach( function ( node ) {
			keys[ node.dataset.bsfKey ] = true;
		} );

		return keys;
	}

	/**
	 * Encode one selection the same way PHP does.
	 */
	function encodeSelection( selection ) {
		if ( ! selection ) {
			return '';
		}

		if ( selection.type === 'range' ) {
			var min = selection.min === null || selection.min === undefined ? '' : trimNumber( selection.min );
			var max = selection.max === null || selection.max === undefined ? '' : trimNumber( selection.max );

			if ( min === '' && max === '' ) {
				return '';
			}

			return min + RANGE_GLUE + max;
		}

		if ( selection.type === 'text' ) {
			return selection.text || '';
		}

		return ( selection.values || [] ).join( ',' );
	}

	/**
	 * Turn a state object back into a URL.
	 */
	function buildUrl( panel, state ) {
		var prefix = panel.dataset.bsfPrefix || config.prefix || 'f_';
		var mode = panel.dataset.bsfUrlmode || config.urlMode || 'query';
		var separator = panel.dataset.bsfSeparator || config.separator || '-';
		var base = panel.dataset.bsfBase || window.location.href;
		var url;

		try {
			url = new URL( base, window.location.origin );
		} catch ( error ) {
			url = new URL( window.location.href );
		}

		var keys = Object.keys( state ).filter( function ( key ) {
			return key !== '__sort';
		} ).sort();

		var segments = [];

		keys.forEach( function ( key ) {
			var value = encodeSelection( state[ key ] );

			if ( ! value ) {
				return;
			}

			if ( key === SEARCH_PARAM ) {
				url.searchParams.set( SEARCH_PARAM, value );
				return;
			}

			if ( mode === 'pretty' ) {
				segments.push( key + separator + value );
				return;
			}

			url.searchParams.set( prefix + key, value );
		} );

		if ( mode === 'pretty' && segments.length ) {
			url.pathname = url.pathname.replace( /\/+$/, '' ) + '/' + segments.join( '/' ) + '/';
		}

		if ( state.__sort ) {
			url.searchParams.set( SORT_PARAM, state.__sort );
		}

		return url.toString();
	}

	/* ---------------------------------------------------------------------
	 * Panel
	 * ------------------------------------------------------------------ */

	function Panel( root ) {
		this.root = root;
		this.mode = root.dataset.bsfMode || config.mode || 'auto';
		this.ajax = root.dataset.bsfAjax === '1' && config.ajax !== false;
		this.setId = root.dataset.bsfSet || '';
		this.pending = null;
		this.controller = null;
		this.paging = config.paging || 'theme';
		this.page = parseInt( root.dataset.bsfPage, 10 ) || 1;
		this.maxPages = parseInt( root.dataset.bsfMaxpages, 10 ) || 0;
		this.currentUrl = window.location.href;
		this.loading = false;
		this.observer = null;

		this.state = readState( root, window.location.href );

		this.bind();
		this.markSelections();
		this.collapseOnMobile();
		this.syncLoadMore();
	}

	/**
	 * Start collapsed on small screens so an archive bar with many attributes
	 * is a short list of headings rather than an endless page. Filters that
	 * already carry a selection stay open so the shopper can see it.
	 */
	Panel.prototype.collapseOnMobile = function () {
		if ( window.innerWidth > 782 ) {
			return;
		}

		var state = this.state;

		qsa( '.bsf-filter.is-collapsible', this.root ).forEach( function ( filter ) {
			var selected = !! state[ filter.dataset.bsfKey ];
			var body = filter.querySelector( '.bsf-filter__body' );
			var title = filter.querySelector( '.bsf-filter__title' );

			filter.classList.toggle( 'is-collapsed', ! selected );
			filter.classList.remove( 'is-open' );

			if ( body ) {
				body.hidden = ! selected;
			}

			if ( title ) {
				title.setAttribute( 'aria-expanded', selected ? 'true' : 'false' );
			}
		} );
	};

	Panel.prototype.bind = function () {
		var self = this;

		this.root.addEventListener( 'click', function ( event ) {
			self.onClick( event );
		} );

		this.root.addEventListener( 'change', function ( event ) {
			self.onChange( event );
		} );

		this.root.addEventListener( 'input', function ( event ) {
			self.onInput( event );
		} );

		this.root.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				self.closeDrawer();
				self.closeDropdowns();
			}
		} );

		qsa( '[data-bsf-search]', this.root ).forEach( function ( input ) {
			input.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
					self.setSearch( input.value );
				}
			} );
		} );

		this.initRanges();
		this.initOptionSearch();
	};

	/* ----- interaction ------------------------------------------------- */

	Panel.prototype.onClick = function ( event ) {
		var self = this;

		var drawerToggle = event.target.closest( '.bsf-drawer-toggle' );

		if ( drawerToggle ) {
			event.preventDefault();
			this.toggleDrawer();
			return;
		}

		if ( event.target.closest( '.bsf-panel__close' ) || event.target.closest( '.bsf-backdrop' ) ) {
			event.preventDefault();
			this.closeDrawer();
			return;
		}

		var title = event.target.closest( '.bsf-filter__title' );

		if ( title && ! title.classList.contains( 'bsf-filter__title--static' ) ) {
			event.preventDefault();
			this.toggleFilter( title.closest( '.bsf-filter' ) );
			return;
		}

		var showMore = event.target.closest( '.bsf-showmore' );

		if ( showMore ) {
			event.preventDefault();
			var filter = showMore.closest( '.bsf-filter' );
			var expanded = filter.classList.toggle( 'is-expanded' );
			showMore.textContent = expanded ? showMore.dataset.less : showMore.dataset.more;
			return;
		}

		var reset = event.target.closest( '[data-bsf-reset]' );

		if ( reset ) {
			event.preventDefault();
			this.state = {};
			this.pending = null;
			this.submit( buildUrl( this.root, this.state ) );
			return;
		}

		var chip = event.target.closest( '.bsf-chip' );

		if ( chip && chip.dataset.bsfKey ) {
			event.preventDefault();
			this.toggleValue( chip.dataset.bsfKey, chip.dataset.bsfValue, true );
			this.commit( chip.href );
			return;
		}

		var apply = event.target.closest( '.bsf-apply' );

		if ( apply ) {
			event.preventDefault();

			if ( this.pending ) {
				this.state = this.pending;
				this.pending = null;
			}

			this.submit( buildUrl( this.root, this.state ) );
			return;
		}

		var link = event.target.closest( '.bsf-option__link' );

		if ( link ) {
			var option = link.closest( '.bsf-option' );

			if ( ! option || option.classList.contains( 'is-disabled' ) ) {
				event.preventDefault();
				return;
			}

			event.preventDefault();

			var group = link.closest( '[data-bsf-key]' );

			if ( ! group ) {
				return;
			}

			var multi = group.dataset.bsfMulti === '1' && group.dataset.bsfDisplay !== 'radio';

			this.toggleValue( group.dataset.bsfKey, option.dataset.bsfValue, multi );
			this.commit( link.getAttribute( 'href' ) );

			return;
		}

		// Clicking outside a horizontal dropdown closes it.
		if ( ! event.target.closest( '.bsf-filter' ) ) {
			this.closeDropdowns();
		}

		void self;
	};

	Panel.prototype.onChange = function ( event ) {
		var select = event.target.closest( '[data-bsf-select]' );

		if ( select ) {
			var group = select.closest( '[data-bsf-key]' );
			var option = select.options[ select.selectedIndex ];
			var target = option ? option.dataset.bsfUrl : '';

			if ( group ) {
				if ( select.value ) {
					this.state[ group.dataset.bsfKey ] = { type: 'terms', values: [ select.value ] };
				} else {
					delete this.state[ group.dataset.bsfKey ];
				}
			}

			this.commit( target );
			return;
		}

		var sort = event.target.closest( '[data-bsf-sort]' );

		if ( sort ) {
			if ( sort.value ) {
				this.state.__sort = sort.value;
			} else {
				delete this.state.__sort;
			}

			this.submit( buildUrl( this.root, this.state ) );
			return;
		}

		var dates = event.target.closest( '[data-bsf-daterange]' );

		if ( dates ) {
			this.commitRange( dates );
		}
	};

	Panel.prototype.onInput = function ( event ) {
		var range = event.target.closest( '[data-bsf-range]' );

		if ( range ) {
			this.syncRange( range, event.target );
		}
	};

	/**
	 * Add or remove one value from the working state.
	 */
	Panel.prototype.toggleValue = function ( key, value, multi ) {
		var working = this.mode === 'apply' ? ( this.pending || Object.assign( {}, this.state ) ) : this.state;
		var current = working[ key ] && working[ key ].type === 'terms' ? working[ key ].values.slice() : [];
		var position = current.indexOf( value );

		if ( position !== -1 ) {
			current.splice( position, 1 );
		} else if ( multi ) {
			current.push( value );
		} else {
			current = [ value ];
		}

		if ( current.length ) {
			working[ key ] = { type: 'terms', values: current };
		} else {
			delete working[ key ];
		}

		if ( this.mode === 'apply' ) {
			this.pending = working;
		}

		this.markSelections();
	};

	/**
	 * Apply immediately, or stage the change in "select and apply" mode.
	 */
	Panel.prototype.commit = function ( fallbackUrl ) {
		if ( this.mode === 'apply' ) {
			this.setApplyEnabled( true );
			return;
		}

		var url = buildUrl( this.root, this.state );

		this.submit( url || fallbackUrl );
	};

	Panel.prototype.setSearch = function ( value ) {
		if ( value ) {
			this.state[ SEARCH_PARAM ] = { type: 'text', text: value };
		} else {
			delete this.state[ SEARCH_PARAM ];
		}

		this.submit( buildUrl( this.root, this.state ) );
	};

	Panel.prototype.setApplyEnabled = function ( enabled ) {
		qsa( '.bsf-apply', this.root ).forEach( function ( button ) {
			button.disabled = ! enabled;
		} );
	};

	/**
	 * Reflect the working state in the DOM without waiting for the server.
	 */
	Panel.prototype.markSelections = function () {
		var working = this.mode === 'apply' && this.pending ? this.pending : this.state;
		var selectedCount = 0;

		qsa( '.bsf-filter[data-bsf-key]', this.root ).forEach( function ( group ) {
			var selection = working[ group.dataset.bsfKey ];
			var values = selection && selection.type === 'terms' ? selection.values : [];
			var groupHasSelection = false;

			qsa( '.bsf-option', group ).forEach( function ( option ) {
				var isSelected = values.indexOf( option.dataset.bsfValue ) !== -1;

				option.classList.toggle( 'is-selected', isSelected );

				var native = option.querySelector( '.bsf-native' );

				if ( native ) {
					native.checked = isSelected;
				}

				if ( isSelected ) {
					groupHasSelection = true;
				}
			} );

			if ( selection ) {
				selectedCount++;
			}

			group.classList.toggle( 'has-selection', groupHasSelection || ( !! selection && selection.type === 'range' ) );
		} );

		var badge = this.root.querySelector( '.bsf-drawer-toggle__count' );

		if ( badge ) {
			badge.textContent = selectedCount ? String( selectedCount ) : '';
			badge.setAttribute( 'data-empty', selectedCount ? '0' : '1' );
		}
	};

	/* ----- collapsing, drawer, dropdowns -------------------------------- */

	Panel.prototype.toggleFilter = function ( filter ) {
		if ( ! filter ) {
			return;
		}

		var horizontal = this.root.classList.contains( 'bsf--horizontal' ) && window.innerWidth > 782;

		if ( horizontal ) {
			var wasOpen = filter.classList.contains( 'is-open' );

			this.closeDropdowns();

			if ( ! wasOpen ) {
				filter.classList.add( 'is-open' );
			}

			return;
		}

		var body = filter.querySelector( '.bsf-filter__body' );
		var title = filter.querySelector( '.bsf-filter__title' );
		var collapsed = filter.classList.toggle( 'is-collapsed' );

		if ( body ) {
			body.hidden = collapsed;
		}

		if ( title ) {
			title.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
		}
	};

	Panel.prototype.closeDropdowns = function () {
		qsa( '.bsf-filter.is-open', this.root ).forEach( function ( filter ) {
			filter.classList.remove( 'is-open' );
		} );
	};

	Panel.prototype.toggleDrawer = function () {
		var open = this.root.classList.toggle( 'is-drawer-open' );
		var backdrop = this.root.querySelector( '.bsf-backdrop' );
		var toggle = this.root.querySelector( '.bsf-drawer-toggle' );

		if ( backdrop ) {
			backdrop.hidden = ! open;
		}

		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}

		document.body.style.overflow = open ? 'hidden' : '';
	};

	Panel.prototype.closeDrawer = function () {
		if ( ! this.root.classList.contains( 'is-drawer-open' ) ) {
			return;
		}

		this.toggleDrawer();
	};

	/* ----- ranges ------------------------------------------------------- */

	Panel.prototype.initRanges = function () {
		var self = this;

		qsa( '[data-bsf-range]', this.root ).forEach( function ( range ) {
			self.syncRange( range, null );

			var commit = debounce( function () {
				self.commitRange( range );
			}, 350 );

			qsa( '.bsf-range__input', range ).forEach( function ( input ) {
				input.addEventListener( 'change', commit );
			} );

			qsa( '.bsf-range__number', range ).forEach( function ( input ) {
				input.addEventListener( 'change', function () {
					self.numbersToSliders( range );
					self.commitRange( range );
				} );
			} );
		} );
	};

	/**
	 * Keep the two thumbs from crossing and repaint the filled track.
	 */
	Panel.prototype.syncRange = function ( range, source ) {
		var minInput = range.querySelector( '.bsf-range__input--min' );
		var maxInput = range.querySelector( '.bsf-range__input--max' );
		var fill = range.querySelector( '.bsf-range__fill' );

		if ( ! minInput || ! maxInput ) {
			return;
		}

		var low = parseFloat( minInput.value );
		var high = parseFloat( maxInput.value );

		if ( low > high ) {
			if ( source === maxInput ) {
				low = high;
				minInput.value = String( low );
			} else {
				high = low;
				maxInput.value = String( high );
			}
		}

		var bound = parseFloat( minInput.min );
		var span = parseFloat( minInput.max ) - bound;

		if ( fill && span > 0 ) {
			fill.style.left = ( ( low - bound ) / span ) * 100 + '%';
			fill.style.width = ( ( high - low ) / span ) * 100 + '%';
		}

		var minNumber = range.querySelector( '.bsf-range__number--min' );
		var maxNumber = range.querySelector( '.bsf-range__number--max' );
		var prefix = range.dataset.prefix || '';
		var suffix = range.dataset.suffix || '';

		if ( minNumber && document.activeElement !== minNumber ) {
			minNumber.value = prefix + low + suffix;
		}

		if ( maxNumber && document.activeElement !== maxNumber ) {
			maxNumber.value = prefix + high + suffix;
		}
	};

	/**
	 * Push typed numbers back into the sliders.
	 */
	Panel.prototype.numbersToSliders = function ( range ) {
		var minInput = range.querySelector( '.bsf-range__input--min' );
		var maxInput = range.querySelector( '.bsf-range__input--max' );
		var minNumber = range.querySelector( '.bsf-range__number--min' );
		var maxNumber = range.querySelector( '.bsf-range__number--max' );

		if ( ! minInput || ! maxInput ) {
			return;
		}

		var bound = parseFloat( minInput.min );
		var ceiling = parseFloat( minInput.max );

		var parse = function ( node, fallback ) {
			if ( ! node ) {
				return fallback;
			}

			var digits = String( node.value ).replace( /[^0-9.,-]/g, '' ).replace( ',', '.' );
			var value = parseFloat( digits );

			return isNaN( value ) ? fallback : Math.min( ceiling, Math.max( bound, value ) );
		};

		var low = parse( minNumber, bound );
		var high = parse( maxNumber, ceiling );

		if ( low > high ) {
			var swap = low;
			low = high;
			high = swap;
		}

		minInput.value = String( low );
		maxInput.value = String( high );

		this.syncRange( range, null );
	};

	Panel.prototype.commitRange = function ( container ) {
		var group = container.closest( '[data-bsf-key]' );

		if ( ! group ) {
			return;
		}

		var key = group.dataset.bsfKey;
		var working = this.mode === 'apply' ? ( this.pending || Object.assign( {}, this.state ) ) : this.state;

		if ( container.hasAttribute( 'data-bsf-daterange' ) ) {
			var from = container.querySelector( '.bsf-daterange__input--from' );
			var to = container.querySelector( '.bsf-daterange__input--to' );

			if ( ! from || ! to ) {
				return;
			}

			working[ key ] = {
				type: 'range',
				min: from.value ? Math.floor( Date.parse( from.value + 'T00:00:00Z' ) / 1000 ) : null,
				max: to.value ? Math.floor( Date.parse( to.value + 'T23:59:59Z' ) / 1000 ) : null
			};
		} else {
			var minInput = container.querySelector( '.bsf-range__input--min' );
			var maxInput = container.querySelector( '.bsf-range__input--max' );

			if ( ! minInput || ! maxInput ) {
				return;
			}

			var low = parseFloat( minInput.value );
			var high = parseFloat( maxInput.value );
			var floor = parseFloat( minInput.min );
			var ceiling = parseFloat( minInput.max );

			if ( low <= floor && high >= ceiling ) {
				delete working[ key ];
			} else {
				working[ key ] = { type: 'range', min: low, max: high };
			}
		}

		if ( this.mode === 'apply' ) {
			this.pending = working;
			this.setApplyEnabled( true );
			this.markSelections();
			return;
		}

		this.submit( buildUrl( this.root, this.state ) );
	};

	/* ----- option search ------------------------------------------------ */

	Panel.prototype.initOptionSearch = function () {
		qsa( '.bsf-optionsearch__input', this.root ).forEach( function ( input ) {
			var filter = input.closest( '.bsf-filter' );

			input.addEventListener( 'input', debounce( function () {
				var needle = input.value.trim().toLowerCase();

				qsa( '.bsf-option', filter ).forEach( function ( option ) {
					var label = ( option.dataset.bsfLabel || option.textContent || '' ).toLowerCase();
					var hit = ! needle || label.indexOf( needle ) !== -1;

					option.style.display = hit ? '' : 'none';
				} );
			}, 150 ) );
		} );
	};

	/* ----- submitting --------------------------------------------------- */

	Panel.prototype.submit = function ( url, skipHistory, append ) {
		if ( ! url ) {
			return;
		}

		if ( ! this.ajax ) {
			window.location.href = url;
			return;
		}

		var self = this;
		var products = this.productsContainer();

		if ( this.controller ) {
			this.controller.abort();
		}

		this.controller = new AbortController();
		this.loading = true;
		this.root.classList.add( 'is-loading' );

		// Appending keeps the cards that are already on screen, so the whole
		// grid must not be dimmed.
		if ( products && ! append ) {
			products.classList.add( 'bsf-busy' );
		}

		this.setLoadMoreBusy( true );

		var allSets = panels.map( function ( panel ) {
			return panel.setId;
		} ).filter( Boolean );

		if ( allSets.indexOf( this.setId ) === -1 ) {
			allSets.push( this.setId );
		}

		var endpoint = config.rest + ( config.rest.indexOf( '?' ) === -1 ? '?' : '&' ) +
			'url=' + encodeURIComponent( url ) +
			'&set=' + encodeURIComponent( this.setId ) +
			'&sets=' + encodeURIComponent( allSets.join( ',' ) );

		window.fetch( endpoint, {
			method: 'GET',
			credentials: 'same-origin',
			signal: this.controller.signal,
			headers: { Accept: 'application/json' }
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}

				return response.json();
			} )
			.then( function ( payload ) {
				self.render( payload, url, skipHistory, append );
			} )
			.catch( function ( error ) {
				if ( error && error.name === 'AbortError' ) {
					return;
				}

				if ( append ) {
					// Loading the next page failed; leave what is on screen and
					// let the shopper retry rather than throwing the page away.
					self.setLoadMoreBusy( false );
					return;
				}

				// A failed request must never leave the shopper stuck.
				window.location.href = url;
			} )
			.finally( function () {
				self.loading = false;
				self.root.classList.remove( 'is-loading' );

				if ( products ) {
					products.classList.remove( 'bsf-busy' );
				}

				self.setLoadMoreBusy( false );
			} );
	};

	/**
	 * Find or drop the plugin's own "load more" control.
	 *
	 * A theme that ships infinite scroll cannot follow an AJAX filter: its
	 * script is bound to the list that was on the page when it loaded. In
	 * loadmore/infinite mode the plugin takes paging over entirely so the two
	 * never fight.
	 */
	Panel.prototype.syncLoadMore = function () {
		if ( this.paging === 'theme' ) {
			return;
		}

		var products = this.productsContainer();

		if ( ! products || ! products.parentNode ) {
			return;
		}

		var button = document.querySelector( '.bsf-loadmore' );
		var more = this.maxPages > 0 && this.page < this.maxPages;

		if ( ! more ) {
			this.stopObserver();

			if ( button ) {
				button.remove();
			}

			return;
		}

		if ( ! button ) {
			var self = this;

			button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'bsf-loadmore';
			button.addEventListener( 'click', function () {
				self.loadNext();
			} );

			products.parentNode.insertBefore( button, products.nextSibling );
		}

		button.textContent = config.i18n.loadMore || 'Load more';
		button.disabled = false;

		if ( this.paging === 'infinite' ) {
			this.observe( button );
		}
	};

	Panel.prototype.setLoadMoreBusy = function ( busy ) {
		var button = document.querySelector( '.bsf-loadmore' );

		if ( ! button ) {
			return;
		}

		button.disabled = !! busy;
		button.classList.toggle( 'is-busy', !! busy );
		button.textContent = busy
			? ( config.i18n.loading || 'Loading…' )
			: ( config.i18n.loadMore || 'Load more' );
	};

	/**
	 * Load the next page and append it.
	 */
	Panel.prototype.loadNext = function () {
		if ( this.loading || this.maxPages === 0 || this.page >= this.maxPages ) {
			return;
		}

		var next;

		try {
			next = new URL( this.currentUrl || window.location.href, window.location.origin );
		} catch ( error ) {
			return;
		}

		next.searchParams.set( 'paged', String( this.page + 1 ) );

		this.submit( next.toString(), true, true );
	};

	Panel.prototype.observe = function ( target ) {
		if ( ! window.IntersectionObserver ) {
			return;
		}

		this.stopObserver();

		var self = this;

		this.observer = new window.IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					self.loadNext();
				}
			} );
		}, { rootMargin: '400px 0px' } );

		this.observer.observe( target );
	};

	Panel.prototype.stopObserver = function () {
		if ( this.observer ) {
			this.observer.disconnect();
			this.observer = null;
		}
	};

	Panel.prototype.productsContainer = function () {
		var selector = ( config.selectors && config.selectors.products ) || '';

		return document.querySelector( selector || '[data-bsf-products], ul.products, .products' );
	};

	/**
	 * Pull the product cards out of a response.
	 *
	 * The endpoint returns the loop wrapper too, so the cards are lifted out of
	 * it and moved into the container the page already has. Replacing the
	 * container node instead would detach anything the theme bound to it.
	 */
	Panel.prototype.extractItems = function ( html ) {
		var holder = document.createElement( 'div' );
		holder.innerHTML = html;

		var first = holder.firstElementChild;

		if ( first && holder.children.length === 1 && first.children.length ) {
			return Array.prototype.slice.call( first.children );
		}

		return Array.prototype.slice.call( holder.childNodes );
	};

	Panel.prototype.render = function ( payload, url, skipHistory, append ) {
		if ( ! payload ) {
			return;
		}

		var products = this.productsContainer();

		if ( products && typeof payload.products === 'string' ) {
			var items = this.extractItems( payload.products );

			if ( ! append ) {
				products.innerHTML = '';
			}

			items.forEach( function ( node ) {
				products.appendChild( node );
			} );
		}

		this.currentUrl = url;
		this.page = parseInt( payload.page, 10 ) || 1;
		this.maxPages = parseInt( payload.max_pages, 10 ) || 0;

		var paginationSelector = ( config.selectors && config.selectors.pagination ) || '.woocommerce-pagination';
		var pagination = document.querySelector( paginationSelector );

		if ( this.paging === 'theme' && typeof payload.pagination === 'string' ) {
			if ( pagination ) {
				// Keep the element and swap its contents: a theme script that
				// bound to this node keeps working.
				if ( payload.pagination ) {
					var fresh = document.createElement( 'div' );
					fresh.innerHTML = payload.pagination;
					pagination.innerHTML = fresh.firstElementChild
						? fresh.firstElementChild.innerHTML
						: payload.pagination;
					pagination.hidden = false;
				} else {
					pagination.innerHTML = '';
					pagination.hidden = true;
				}
			} else if ( payload.pagination && products && products.parentNode ) {
				products.insertAdjacentHTML( 'afterend', payload.pagination );
			}
		} else if ( this.paging !== 'theme' && pagination ) {
			// The plugin owns paging in these modes.
			pagination.hidden = true;
		}

		var countSelector = ( config.selectors && config.selectors.count ) || '.woocommerce-result-count';

		qsa( countSelector ).concat( qsa( '[data-bsf-count]' ) ).forEach( function ( node ) {
			if ( payload.count_text ) {
				node.textContent = payload.count_text;
			}
		} );

		var bySet = payload.filters_by_set || {};
		var self = this;

		panels.forEach( function ( panel ) {
			var html = bySet[ panel.setId ];

			if ( typeof html !== 'string' || ! html ) {
				html = panel === self && typeof payload.filters === 'string' ? payload.filters : '';
			}

			if ( html ) {
				panel.replacePanel( html, url );

				return;
			}

			// No fresh markup for this panel: at least keep its selection in
			// step with the URL that is now current.
			panel.state = readState( panel.root, url );
			panel.pending = null;
			panel.currentUrl = url;
			panel.markSelections();
		} );

		if ( ! skipHistory ) {
			window.history.pushState( { bsf: true }, '', url );
		}

		if ( config.scrollTop !== false && ! append ) {
			this.scrollToResults();
		}

		this.syncLoadMore();

		// Themes and lazy load scripts listen for these; give them a chance to
		// pick up the cards that were just added.
		document.dispatchEvent( new CustomEvent( 'bsf:updated', { detail: { payload: payload, url: url, append: !! append } } ) );

		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( 'post-load' );
			window.jQuery( document.body ).trigger( 'wc_fragments_refreshed' );
		}
	};

	/**
	 * Swap the panel for the freshly counted markup, keeping open sections open.
	 */
	Panel.prototype.replacePanel = function ( html, url ) {
		var open = {};
		var drawerOpen = this.root.classList.contains( 'is-drawer-open' );

		qsa( '.bsf-filter', this.root ).forEach( function ( filter ) {
			open[ filter.dataset.bsfFilter ] = {
				collapsed: filter.classList.contains( 'is-collapsed' ),
				expanded: filter.classList.contains( 'is-expanded' ),
				dropdown: filter.classList.contains( 'is-open' )
			};
		} );

		var holder = document.createElement( 'div' );
		holder.innerHTML = html;

		var fresh = holder.firstElementChild;

		if ( ! fresh ) {
			return;
		}

		this.root.parentNode.replaceChild( fresh, this.root );
		this.root = fresh;

		qsa( '.bsf-filter', this.root ).forEach( function ( filter ) {
			var previous = open[ filter.dataset.bsfFilter ];

			if ( ! previous ) {
				return;
			}

			filter.classList.toggle( 'is-collapsed', previous.collapsed );
			filter.classList.toggle( 'is-expanded', previous.expanded );
			filter.classList.toggle( 'is-open', previous.dropdown );

			var body = filter.querySelector( '.bsf-filter__body' );

			if ( body ) {
				body.hidden = previous.collapsed;
			}
		} );

		if ( drawerOpen ) {
			this.root.classList.add( 'is-drawer-open' );

			var backdrop = this.root.querySelector( '.bsf-backdrop' );

			if ( backdrop ) {
				backdrop.hidden = false;
			}
		}

		// Read back the URL this render belongs to. window.location is still the
		// previous page at this point, and using it would re-apply the previous
		// selection over the markup the server just produced.
		this.state = readState( this.root, url || window.location.href );
		this.currentUrl = url || this.currentUrl;
		this.pending = null;
		this.bind();
		this.markSelections();
	};

	Panel.prototype.scrollToResults = function () {
		var products = this.productsContainer();

		if ( ! products ) {
			return;
		}

		var top = products.getBoundingClientRect().top + window.pageYOffset - 80;

		if ( window.pageYOffset > top ) {
			window.scrollTo( { top: top, behavior: 'smooth' } );
		}
	};

	/* ---------------------------------------------------------------------
	 * Boot
	 * ------------------------------------------------------------------ */

	var panels = [];

	function init() {
		panels = qsa( '.bsf[data-bsf-set]' ).map( function ( root ) {
			return new Panel( root );
		} );

		// Pagination inside an AJAX filtered grid stays on the page.
		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( '.woocommerce-pagination a.page-numbers, .bsf-pagination a.page-numbers' );

			if ( ! link || ! panels.length || ! panels[ 0 ].ajax || panels[ 0 ].paging !== 'theme' ) {
				return;
			}

			event.preventDefault();
			panels[ 0 ].submit( link.href );
		} );
	}

	window.addEventListener( 'popstate', function () {
		panels.forEach( function ( panel ) {
			panel.state = readState( panel.root, window.location.href );
			panel.pending = null;
			panel.markSelections();
			panel.submit( window.location.href, true );
		} );
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

/* global dosieciInstantSearch */
( function () {
	'use strict';

	var DEBOUNCE_MS = 180;
	var config = window.dosieciInstantSearch || {};
	var counter = 0;

	function suggestUrl( term ) {
		var endpoint = config.endpoint || '';
		// With plain permalinks the REST URL already carries "?rest_route=".
		return endpoint + ( endpoint.indexOf( '?' ) === -1 ? '?' : '&' ) + 'q=' + encodeURIComponent( term );
	}

	function attach( input ) {
		var id = 'dosieci-is-panel-' + ( ++counter );
		var panel = document.createElement( 'div' );
		var timer = null;
		var controller = null;
		var items = [];
		var active = -1;

		panel.className = 'dosieci-is-panel';
		panel.id = id;
		panel.setAttribute( 'role', 'listbox' );
		panel.setAttribute( 'aria-label', config.label || '' );
		panel.hidden = true;
		// Appended to <body> and positioned under the field, instead of
		// wrapping the field in a new element: wrapping breaks the flex and
		// grid layouts themes use for search forms.
		document.body.appendChild( panel );

		input.setAttribute( 'autocomplete', 'off' );
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', id );

		function place() {
			var rect = input.getBoundingClientRect();
			panel.style.top = ( rect.bottom + window.pageYOffset ) + 'px';
			panel.style.left = ( rect.left + window.pageXOffset ) + 'px';
			panel.style.width = Math.max( rect.width, 280 ) + 'px';
		}

		function close() {
			panel.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			active = -1;
		}

		function highlight( index ) {
			items.forEach( function ( item, i ) {
				item.setAttribute( 'aria-selected', i === index ? 'true' : 'false' );
			} );
			active = index;
			if ( index >= 0 ) {
				input.setAttribute( 'aria-activedescendant', items[ index ].id );
				items[ index ].scrollIntoView( { block: 'nearest' } );
			} else {
				input.removeAttribute( 'aria-activedescendant' );
			}
		}

		function render( results ) {
			panel.textContent = '';
			items = [];
			active = -1;

			if ( ! results.length ) {
				close();
				return;
			}

			results.forEach( function ( result, i ) {
				var link = document.createElement( 'a' );
				link.className = 'dosieci-is-item';
				link.id = id + '-' + i;
				link.href = result.url;
				link.setAttribute( 'role', 'option' );
				link.setAttribute( 'aria-selected', 'false' );
				link.tabIndex = -1;

				if ( result.image ) {
					var img = document.createElement( 'img' );
					img.src = result.image;
					img.alt = '';
					img.loading = 'lazy';
					link.appendChild( img );
				}

				var text = document.createElement( 'span' );
				text.className = 'dosieci-is-title';
				// textContent, never innerHTML: titles are user data.
				text.textContent = result.title;
				link.appendChild( text );

				if ( result.price ) {
					var price = document.createElement( 'span' );
					price.className = 'dosieci-is-price';
					price.textContent = result.price;
					link.appendChild( price );
				}

				items.push( link );
				panel.appendChild( link );
			} );

			place();
			panel.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
		}

		input.addEventListener( 'input', function () {
			var term = input.value.trim();

			window.clearTimeout( timer );

			if ( term.length < ( config.minChars || 2 ) ) {
				close();
				return;
			}

			timer = window.setTimeout( function () {
				// Abort the previous request so a slow response for "sho"
				// cannot land after the one for "shoes".
				if ( controller ) {
					controller.abort();
				}
				controller = new AbortController();

				fetch( suggestUrl( term ), { signal: controller.signal, credentials: 'same-origin' } )
					.then( function ( response ) {
						return response.ok ? response.json() : { results: [] };
					} )
					.then( function ( payload ) {
						render( ( payload && payload.results ) || [] );
					} )
					.catch( function () {
						/* Aborted or offline: keep the current state. */
					} );
			}, DEBOUNCE_MS );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( panel.hidden ) {
				return;
			}

			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				highlight( active + 1 < items.length ? active + 1 : 0 );
			} else if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				highlight( active > 0 ? active - 1 : items.length - 1 );
			} else if ( 'Enter' === event.key && active >= 0 ) {
				// Go to the highlighted suggestion instead of submitting the
				// search form.
				event.preventDefault();
				window.location.href = items[ active ].href;
			} else if ( 'Escape' === event.key ) {
				close();
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( ! panel.contains( event.target ) && event.target !== input ) {
				close();
			}
		} );

		function follow() {
			if ( ! panel.hidden ) {
				place();
			}
		}

		// Keeps the list under fields inside sticky or fixed headers.
		window.addEventListener( 'resize', follow );
		window.addEventListener( 'scroll', follow, { passive: true } );
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( 'input[name="s"]' ), attach );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

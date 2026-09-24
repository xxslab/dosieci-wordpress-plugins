/* global dosieciInstantSearch */
( function () {
	'use strict';

	var DEBOUNCE_MS = 180;

	function buildPanel( input ) {
		var panel = document.createElement( 'div' );
		panel.className = 'dosieci-is-panel';
		panel.setAttribute( 'role', 'listbox' );
		panel.hidden = true;

		var wrapper = document.createElement( 'div' );
		wrapper.className = 'dosieci-is-wrapper';
		input.parentNode.insertBefore( wrapper, input );
		wrapper.appendChild( input );
		wrapper.appendChild( panel );

		return panel;
	}

	function render( panel, results ) {
		panel.textContent = '';

		if ( ! results.length ) {
			panel.hidden = true;
			return;
		}

		results.forEach( function ( item ) {
			var link = document.createElement( 'a' );
			link.className = 'dosieci-is-item';
			link.href = item.url;
			link.setAttribute( 'role', 'option' );

			if ( item.image ) {
				var img = document.createElement( 'img' );
				img.src = item.image;
				img.alt = '';
				img.loading = 'lazy';
				link.appendChild( img );
			}

			var text = document.createElement( 'span' );
			text.className = 'dosieci-is-title';
			// textContent, never innerHTML: product titles are user data.
			text.textContent = item.title;
			link.appendChild( text );

			if ( item.price ) {
				var price = document.createElement( 'span' );
				price.className = 'dosieci-is-price';
				price.textContent = item.price;
				link.appendChild( price );
			}

			panel.appendChild( link );
		} );

		panel.hidden = false;
	}

	function attach( input ) {
		var panel = buildPanel( input );
		var timer = null;
		var controller = null;

		input.setAttribute( 'autocomplete', 'off' );

		input.addEventListener( 'input', function () {
			var term = input.value.trim();

			window.clearTimeout( timer );

			if ( term.length < dosieciInstantSearch.minChars ) {
				panel.hidden = true;
				return;
			}

			timer = window.setTimeout( function () {
				// Abort the previous in-flight request so a slow response
				// for "sho" cannot land after the response for "shoes".
				if ( controller ) {
					controller.abort();
				}
				controller = new AbortController();

				fetch(
					dosieciInstantSearch.endpoint + '?q=' + encodeURIComponent( term ),
					{ signal: controller.signal }
				)
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( payload ) {
						render( panel, ( payload && payload.results ) || [] );
					} )
					.catch( function () {
						/* aborted or offline: leave the previous panel state */
					} );
			}, DEBOUNCE_MS );
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( ! panel.contains( event.target ) && event.target !== input ) {
				panel.hidden = true;
			}
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				panel.hidden = true;
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var inputs = document.querySelectorAll( 'input[name="s"]' );
		Array.prototype.forEach.call( inputs, attach );
	} );
}() );

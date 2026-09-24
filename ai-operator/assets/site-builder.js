/**
 * Site Builder screen.
 *
 * Drives four server endpoints and renders whatever they return. It
 * deliberately holds almost no state: the plan, its approval and its
 * progress all live server-side, so this file only ever knows a plan id and
 * the hash it last displayed. A refresh mid-build re-reads real progress
 * from the server rather than losing it.
 *
 * The plan hash is echoed back on approval so the server can refuse a click
 * aimed at a plan that has since been regenerated. It is not a credential --
 * the server compares it against its own copy.
 *
 * ## The describe -> review -> propose boundary
 *
 * A natural-language description produces a blueprint CANDIDATE, which is
 * loaded into the structured form for human review before any plan exists.
 * That review has to be a round trip: everything the server's blueprint
 * carried has to survive being edited and submitted back, or "describe"
 * silently narrows what "propose" ever sees. The translation between the
 * server's blueprint shape and the form's DOM state is deliberately split
 * into pure functions (mapDescribeResponseToFormState / mapFormStateToBlueprint
 * / validateFormState) with no DOM access, so that round trip is testable
 * without a browser -- see tests/site-builder.form.test.js.
 */
( function ( root, factory ) {
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = factory();
	} else {
		factory( true );
	}
}( typeof window !== 'undefined' ? window : this, function ( isBrowser ) {
	'use strict';

	// -----------------------------------------------------------------
	// Pure form <-> blueprint mapping. No DOM access anywhere in this
	// section: every function takes plain data in and returns plain data
	// out, so the describe -> review -> propose round trip is unit
	// testable in plain Node.
	// -----------------------------------------------------------------

	/**
	 * The server's blueprint shape (SiteBlueprint::toArray(), or the
	 * `blueprint` field of a describe/propose response) -> the form's
	 * internal state shape.
	 *
	 * @param {object} bp
	 * @return {object}
	 */
	function mapDescribeResponseToFormState( bp ) {
		bp = bp || {};

		var rawStore = bp.store;
		var hasStore = rawStore && typeof rawStore === 'object' && Object.keys( rawStore ).length > 0;

		return {
			name: bp.business_name || '',
			description: bp.description || '',
			pages: Array.isArray( bp.pages ) ? bp.pages.join( ', ' ) : '',
			contactForm: Array.isArray( bp.features ) && bp.features.indexOf( 'contact_form' ) !== -1,
			siteType: bp.site_type || '',
			store: hasStore ? {
				country: rawStore.store_country || '',
				currency: rawStore.currency || '',
				weightUnit: rawStore.weight_unit || 'kg',
				dimensionUnit: rawStore.dimension_unit || 'cm',
				categories: ( rawStore.categories || [] ).map( function ( c ) {
					return {
						role: c.logical_role || c.name || '',
						name: c.name || '',
						description: c.description || ''
					};
				} ),
				products: ( rawStore.initial_products || [] ).map( function ( p ) {
					return {
						role: p.logical_role || p.name || '',
						name: p.name || '',
						description: p.description || '',
						shortDescription: p.short_description || '',
						regularPrice: p.regular_price != null ? String( p.regular_price ) : '',
						categoryRoles: Array.isArray( p.category_roles ) ? p.category_roles.slice() : [],
						imageStrategy: p.image_strategy || 'library'
					};
				} )
			} : null,
			// Fields the review form has no dedicated widget for yet.
			// Carried through unedited so the round trip never silently
			// drops them -- see SiteBlueprint::toArray()'s full contract.
			passthrough: {
				language: bp.language || 'pl',
				brand_colors: bp.brand_colors || {},
				brand_style: bp.brand_style || 'modern_professional',
				page_builder: bp.page_builder || 'gutenberg'
			}
		};
	}

	/**
	 * The form's internal state -> the exact shape SiteBlueprint::fromArray()
	 * (and, for a store, StoreBlueprint::fromArray()) expects.
	 *
	 * @param {object} formState
	 * @return {object}
	 */
	function mapFormStateToBlueprint( formState ) {
		formState = formState || {};

		var passthrough = formState.passthrough || {};
		var pages = ( formState.pages || '' )
			.split( ',' )
			.map( function ( page ) {
				return page.trim();
			} )
			.filter( Boolean );

		var blueprint = {
			site_type: formState.siteType || '',
			business_name: formState.name || '',
			description: formState.description || '',
			language: passthrough.language || 'pl',
			pages: pages,
			features: formState.contactForm ? [ 'contact_form' ] : [],
			brand_colors: passthrough.brand_colors || {},
			brand_style: passthrough.brand_style || 'modern_professional',
			page_builder: passthrough.page_builder || 'gutenberg',
			woocommerce: false
		};

		if ( formState.store ) {
			var store = formState.store;

			blueprint.woocommerce = true;
			blueprint.store = {
				store_country: store.country || '',
				currency: store.currency || '',
				weight_unit: store.weightUnit || 'kg',
				dimension_unit: store.dimensionUnit || 'cm',
				categories: ( store.categories || [] ).map( function ( c ) {
					return {
						logical_role: c.role || c.name,
						name: c.name,
						description: c.description || ''
					};
				} ),
				initial_products: ( store.products || [] ).map( function ( p ) {
					return {
						logical_role: p.role || p.name,
						name: p.name,
						description: p.description || '',
						short_description: p.shortDescription || '',
						regular_price: p.regularPrice,
						category_roles: p.categoryRoles || [],
						image_strategy: p.imageStrategy || 'library'
					};
				} )
			};
		}

		return blueprint;
	}

	/**
	 * The client-side half of "fail closed": a store site with incomplete
	 * store data must never quietly submit as a generic plan. This mirrors
	 * only the obvious completeness rules (something in every required
	 * field); the server's SiteBlueprint/StoreBlueprint validation stays
	 * the authority on exact shape and remains defense in depth for any
	 * request that does not go through this form at all.
	 *
	 * @param {object} formState
	 * @return {{valid: boolean, message: (string|undefined)}}
	 */
	function validateFormState( formState ) {
		formState = formState || {};

		if ( 'store' !== formState.siteType ) {
			return { valid: true };
		}

		var store = formState.store;

		if ( ! store ) {
			return { valid: false, message: 'Wybrano rodzaj „Sklep” — uzupełnij dane sklepu poniżej.' };
		}

		if ( ! /^[A-Za-z]{2}(:[A-Za-z0-9]{1,6})?$/.test( store.country || '' ) ) {
			return { valid: false, message: 'Podaj prawidłowy kod kraju sklepu (np. PL).' };
		}

		if ( ! /^[A-Za-z]{3}$/.test( store.currency || '' ) ) {
			return { valid: false, message: 'Podaj prawidłowy kod waluty (np. PLN).' };
		}

		if ( ! store.categories || 0 === store.categories.length ) {
			return { valid: false, message: 'Dodaj przynajmniej jedną kategorię produktów.' };
		}

		if ( ! store.products || 0 === store.products.length ) {
			return { valid: false, message: 'Dodaj przynajmniej jeden produkt.' };
		}

		for ( var i = 0; i < store.products.length; i++ ) {
			var product = store.products[ i ];

			if ( ! product.name ) {
				return { valid: false, message: 'Każdy produkt musi mieć nazwę.' };
			}

			if ( ! /^\d{1,7}([.,]\d{1,2})?$/.test( String( product.regularPrice || '' ).trim() ) ) {
				return { valid: false, message: 'Produkt „' + product.name + '” ma nieprawidłową cenę.' };
			}

			if ( ! product.categoryRoles || 0 === product.categoryRoles.length ) {
				return { valid: false, message: 'Produkt „' + product.name + '” nie ma przypisanej kategorii.' };
			}
		}

		return { valid: true };
	}

	var pureFunctions = {
		mapDescribeResponseToFormState: mapDescribeResponseToFormState,
		mapFormStateToBlueprint: mapFormStateToBlueprint,
		validateFormState: validateFormState
	};

	if ( ! isBrowser ) {
		return pureFunctions;
	}

	// -----------------------------------------------------------------
	// Browser wiring below. Nothing past this point is unit tested
	// directly -- it is exercised by the real-WordPress UI-equivalent
	// smoke test instead (see docs/architecture, Site Builder section).
	// -----------------------------------------------------------------

	( function () {
		var cfg = window.dosieciAiOperator || {};
		var planId = null;
		var planHash = null;
		var polling = false;

		// The full blueprint from the last "Przygotuj opis" response, kept
		// only so fields the review form has no widget for (brand colours,
		// brand style, page builder) survive being carried through to
		// propose unedited. Never the sole home of anything commercial --
		// store data always lives in the visible, editable form fields.
		var lastDescribedBlueprint = {};

		function el( id ) {
			return document.getElementById( id );
		}

		function post( action, data ) {
			var body = new FormData();
			body.append( 'action', action );
			body.append( 'nonce', cfg.builderNonce );

			Object.keys( data || {} ).forEach( function ( key ) {
				body.append( key, data[ key ] );
			} );

			return fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					if ( ! payload || ! payload.success ) {
						var message = payload && payload.data && payload.data.message
							? payload.data.message
							: 'Wystąpił błąd.';
						throw new Error( message );
					}

					return payload.data;
				} );
		}

		function text( value ) {
			// Everything rendered here originates from a model or from user
			// input; nothing is inserted as HTML.
			return document.createTextNode( String( value == null ? '' : value ) );
		}

		function node( tag, className, content ) {
			var node = document.createElement( tag );

			if ( className ) {
				node.className = className;
			}

			if ( content !== undefined ) {
				node.appendChild( text( content ) );
			}

			return node;
		}

		function statusIcon( status ) {
			switch ( status ) {
				case 'succeeded':
					return '✅';
				case 'failed':
				case 'rollback_failed':
					return '❌';
				case 'running':
					return '⏳';
				case 'rolled_back':
					return '↩';
				default:
					return '⬜';
			}
		}

		function renderBlueprint( data ) {
			var panel = el( 'dosieci-sb-blueprint' );
			panel.textContent = '';
			panel.hidden = false;

			panel.appendChild( node( 'h2', null, 'Co zrozumiałem' ) );

			var list = document.createElement( 'ul' );
			var bp = data.blueprint || {};

			[
				[ 'Firma', bp.business_name ],
				[ 'Rodzaj', bp.site_type ],
				[ 'Strony', ( bp.pages || [] ).join( ', ' ) ],
				[ 'Funkcje', ( bp.features || [] ).join( ', ' ) || 'brak' ]
			].forEach( function ( row ) {
				var li = document.createElement( 'li' );
				li.appendChild( node( 'strong', null, row[ 0 ] + ': ' ) );
				li.appendChild( text( row[ 1 ] ) );
				list.appendChild( li );
			} );

			if ( bp.store && Object.keys( bp.store ).length ) {
				var products = bp.store.initial_products || [];
				var li = document.createElement( 'li' );
				li.appendChild( node( 'strong', null, 'Sklep: ' ) );
				li.appendChild( text(
					( bp.store.store_country || '' ) + ' / ' + ( bp.store.currency || '' ) + ', '
					+ ( bp.store.categories || [] ).length + ' kategorii, '
					+ products.length + ' produktów'
				) );
				list.appendChild( li );
			}

			panel.appendChild( list );
		}

		function renderPlan( data ) {
			var panel = el( 'dosieci-sb-plan' );
			panel.textContent = '';
			panel.hidden = false;

			panel.appendChild( node( 'h2', null, 'Plan budowy — ' + data.steps.length + ' kroków' ) );
			panel.appendChild( node( 'p', 'description', 'Przejrzyj wszystkie kroki. Zatwierdzasz je razem — nic nie wykona się wcześniej.' ) );

			var list = document.createElement( 'ol' );
			data.steps.forEach( function ( step ) {
				var li = document.createElement( 'li' );
				li.appendChild( text( step.description ) );

				if ( ! step.reversible ) {
					li.appendChild( node( 'em', null, ' (nieodwracalne)' ) );
				}

				list.appendChild( li );
			} );
			panel.appendChild( list );

			if ( data.status === 'awaiting_approval' ) {
				var approve = node( 'button', 'button button-primary', 'Zatwierdź cały plan' );
				approve.addEventListener( 'click', function () {
					approve.disabled = true;
					post( 'dosieci_ai_sb_approve', { plan_id: planId, plan_hash: planHash } )
						.then( function ( updated ) {
							update( updated );
							runNextStep();
						} )
						.catch( function ( error ) {
							approve.disabled = false;
							showError( error.message );
						} );
				} );

				var cancel = node( 'button', 'button', 'Anuluj' );
				cancel.addEventListener( 'click', function () {
					post( 'dosieci_ai_sb_cancel', { plan_id: planId } ).then( update ).catch( function ( e ) {
						showError( e.message );
					} );
				} );

				panel.appendChild( approve );
				panel.appendChild( text( ' ' ) );
				panel.appendChild( cancel );
			}
		}

		/**
		 * The final audit, check by check.
		 *
		 * Every verdict here is the server's. This function decides nothing --
		 * it does not count failures, does not derive `passed` from the rows,
		 * and does not compose the summary sentence. Two implementations of
		 * "did the build succeed" is one too many, and the one that matters is
		 * the one that gates the plan's status.
		 */
		function renderAudit( panel, audit ) {
			if ( ! audit || ! audit.checks || ! audit.checks.length ) {
				return;
			}

			panel.appendChild( node( 'h3', null, 'Audyt końcowy' ) );
			panel.appendChild(
				node( 'p', audit.passed ? 'notice notice-success' : 'notice notice-error', audit.summary )
			);

			var list = document.createElement( 'ul' );
			list.className = 'dosieci-sb-audit';

			audit.checks.forEach( function ( check ) {
				var li = document.createElement( 'li' );
				li.className = check.passed ? 'is-passed' : 'is-failed';
				li.appendChild( text( ( check.passed ? '✅ ' : '❌ ' ) + check.check ) );

				// The detail is the useful half on a failure ("expected PLN,
				// actual EUR"); on a pass it is just noise in a long list.
				if ( ! check.passed && check.detail ) {
					li.appendChild( node( 'div', 'dosieci-sb-error', check.detail ) );
				}

				list.appendChild( li );
			} );

			panel.appendChild( list );
		}

		function renderProgress( data ) {
			var panel = el( 'dosieci-sb-progress' );
			panel.textContent = '';

			if ( data.status === 'awaiting_approval' || data.status === 'draft' ) {
				panel.hidden = true;
				return;
			}

			panel.hidden = false;

			var done = data.progress.succeeded;
			panel.appendChild( node( 'h2', null, 'Postęp — ' + done + ' / ' + data.progress.total ) );

			var list = document.createElement( 'ul' );
			list.className = 'dosieci-sb-steps';

			data.steps.forEach( function ( step ) {
				var li = document.createElement( 'li' );
				li.appendChild( text( statusIcon( step.status ) + ' ' + step.description ) );

				if ( step.error ) {
					li.appendChild( node( 'div', 'dosieci-sb-error', step.error ) );
				}

				list.appendChild( li );
			} );

			panel.appendChild( list );

			renderAudit( panel, data.audit );

			if ( data.failure_reason ) {
				panel.appendChild( node( 'p', 'notice notice-error', data.failure_reason ) );
			}

			if ( data.status === 'running' ) {
				var pause = node( 'button', 'button', 'Wstrzymaj' );
				pause.addEventListener( 'click', function () {
					post( 'dosieci_ai_sb_pause', { plan_id: planId } ).then( update ).catch( function ( e ) {
						showError( e.message );
					} );
				} );

				var cancelRun = node( 'button', 'button', 'Przerwij' );
				cancelRun.addEventListener( 'click', function () {
					post( 'dosieci_ai_sb_cancel', { plan_id: planId } ).then( update ).catch( function ( e ) {
						showError( e.message );
					} );
				} );

				panel.appendChild( pause );
				panel.appendChild( text( ' ' ) );
				panel.appendChild( cancelRun );
			}

			if ( data.status === 'paused' ) {
				var resume = node( 'button', 'button button-primary', 'Wznów' );
				resume.addEventListener( 'click', function () {
					post( 'dosieci_ai_sb_resume', { plan_id: planId } ).then( function ( updated ) {
						update( updated );
						runNextStep();
					} ).catch( function ( e ) {
						showError( e.message );
					} );
				} );
				panel.appendChild( resume );
			}

			if ( data.can_rollback ) {
				var rollback = node( 'button', 'button', 'Cofnij wprowadzone zmiany' );
				rollback.addEventListener( 'click', function () {
					rollback.disabled = true;
					post( 'dosieci_ai_sb_rollback', { plan_id: planId } ).then( update ).catch( function ( e ) {
						rollback.disabled = false;
						showError( e.message );
					} );
				} );
				panel.appendChild( text( ' ' ) );
				panel.appendChild( rollback );
			}
		}

		function showError( message ) {
			var panel = el( 'dosieci-sb-progress' );
			panel.hidden = false;
			panel.appendChild( node( 'p', 'notice notice-error', message ) );
		}

		function update( data ) {
			planId = data.plan_id;
			planHash = data.plan_hash;

			renderBlueprint( data );
			renderPlan( data );
			renderProgress( data );
		}

		/**
		 * One request per step. The server runs a bounded batch and persists
		 * before answering, so a dropped connection costs at most the step in
		 * flight -- never the whole build.
		 */
		function runNextStep() {
			if ( polling ) {
				return;
			}

			polling = true;

			post( 'dosieci_ai_sb_step', { plan_id: planId } )
				.then( function ( data ) {
					polling = false;
					update( data );

					if ( data.status === 'running' ) {
						runNextStep();
					}
				} )
				.catch( function ( error ) {
					polling = false;
					showError( error.message );
				} );
		}

		// -------------------------------------------------------------
		// Store review section: category/product rows.
		// -------------------------------------------------------------

		function categoriesTbody() {
			return el( 'dosieci-sb-categories' );
		}

		function productsTbody() {
			return el( 'dosieci-sb-products' );
		}

		function currentCategoryOptions() {
			var rows = categoriesTbody().querySelectorAll( '.dosieci-sb-category-row' );
			var options = [];

			rows.forEach( function ( row ) {
				var name = row.querySelector( '.dosieci-sb-category-name' ).value.trim();
				options.push( { role: row.dataset.role, name: name } );
			} );

			return options;
		}

		/** Rebuilds every product row's category <select>, keeping its selection where the role still exists. */
		function refreshProductCategoryOptions() {
			var options = currentCategoryOptions();

			productsTbody().querySelectorAll( '.dosieci-sb-product-category' ).forEach( function ( select ) {
				var previous = select.value;
				select.textContent = '';

				options.forEach( function ( option ) {
					var optionEl = document.createElement( 'option' );
					optionEl.value = option.role;
					optionEl.textContent = option.name || option.role;
					select.appendChild( optionEl );
				} );

				if ( options.some( function ( o ) { return o.role === previous; } ) ) {
					select.value = previous;
				}
			} );
		}

		function buildCategoryRow( category ) {
			category = category || { role: '', name: '' };

			var row = document.createElement( 'tr' );
			row.className = 'dosieci-sb-category-row';
			row.dataset.role = category.role || category.name || ( 'cat_' + Date.now() + '_' + Math.random().toString( 36 ).slice( 2 ) );

			var nameCell = document.createElement( 'td' );
			var nameInput = document.createElement( 'input' );
			nameInput.type = 'text';
			nameInput.className = 'dosieci-sb-category-name regular-text';
			nameInput.maxLength = 100;
			nameInput.value = category.name || '';
			nameInput.addEventListener( 'input', refreshProductCategoryOptions );
			nameCell.appendChild( nameInput );
			row.appendChild( nameCell );

			var removeCell = document.createElement( 'td' );
			var removeButton = node( 'button', 'button-link', '✕' );
			removeButton.type = 'button';
			removeButton.addEventListener( 'click', function () {
				row.remove();
				refreshProductCategoryOptions();
			} );
			removeCell.appendChild( removeButton );
			row.appendChild( removeCell );

			return row;
		}

		function buildProductRow( product ) {
			product = product || { role: '', name: '', regularPrice: '', categoryRoles: [] };

			var row = document.createElement( 'tr' );
			row.className = 'dosieci-sb-product-row';
			row.dataset.role = product.role || product.name || ( 'prod_' + Date.now() + '_' + Math.random().toString( 36 ).slice( 2 ) );

			var nameCell = document.createElement( 'td' );
			var nameInput = document.createElement( 'input' );
			nameInput.type = 'text';
			nameInput.className = 'dosieci-sb-product-name regular-text';
			nameInput.maxLength = 120;
			nameInput.value = product.name || '';
			nameCell.appendChild( nameInput );
			row.appendChild( nameCell );

			var priceCell = document.createElement( 'td' );
			var priceInput = document.createElement( 'input' );
			priceInput.type = 'text';
			priceInput.className = 'dosieci-sb-product-price small-text';
			priceInput.value = product.regularPrice || '';
			priceCell.appendChild( priceInput );
			row.appendChild( priceCell );

			var categoryCell = document.createElement( 'td' );
			var categorySelect = document.createElement( 'select' );
			categorySelect.className = 'dosieci-sb-product-category';
			categoryCell.appendChild( categorySelect );
			row.appendChild( categoryCell );

			var removeCell = document.createElement( 'td' );
			var removeButton = node( 'button', 'button-link', '✕' );
			removeButton.type = 'button';
			removeButton.addEventListener( 'click', function () {
				row.remove();
			} );
			removeCell.appendChild( removeButton );
			row.appendChild( removeCell );

			// Populated after insertion, once the row (and its sibling
			// category rows) are attached -- see addProductRow().
			row._pendingCategoryRole = ( product.categoryRoles || [] )[ 0 ] || '';

			return row;
		}

		function addCategoryRow( category ) {
			var row = buildCategoryRow( category );
			categoriesTbody().appendChild( row );
			refreshProductCategoryOptions();
			return row;
		}

		function addProductRow( product ) {
			var row = buildProductRow( product );
			productsTbody().appendChild( row );
			refreshProductCategoryOptions();

			if ( row._pendingCategoryRole ) {
				row.querySelector( '.dosieci-sb-product-category' ).value = row._pendingCategoryRole;
			}

			return row;
		}

		function clearStoreRows() {
			categoriesTbody().textContent = '';
			productsTbody().textContent = '';
		}

		function readStoreSection() {
			var categories = [];
			categoriesTbody().querySelectorAll( '.dosieci-sb-category-row' ).forEach( function ( row ) {
				categories.push( {
					role: row.dataset.role,
					name: row.querySelector( '.dosieci-sb-category-name' ).value.trim(),
					description: ''
				} );
			} );

			var products = [];
			productsTbody().querySelectorAll( '.dosieci-sb-product-row' ).forEach( function ( row ) {
				var categorySelect = row.querySelector( '.dosieci-sb-product-category' );

				products.push( {
					role: row.dataset.role,
					name: row.querySelector( '.dosieci-sb-product-name' ).value.trim(),
					description: '',
					shortDescription: '',
					regularPrice: row.querySelector( '.dosieci-sb-product-price' ).value.trim(),
					categoryRoles: categorySelect && categorySelect.value ? [ categorySelect.value ] : [],
					imageStrategy: 'library'
				} );
			} );

			return {
				country: el( 'dosieci-sb-store-country' ).value.trim().toUpperCase(),
				currency: el( 'dosieci-sb-store-currency' ).value.trim().toUpperCase(),
				weightUnit: el( 'dosieci-sb-store-weight-unit' ).value,
				dimensionUnit: el( 'dosieci-sb-store-dimension-unit' ).value,
				categories: categories,
				products: products
			};
		}

		function setStoreSectionVisible( visible ) {
			el( 'dosieci-sb-store' ).hidden = ! visible;
		}

		function isStoreTypeSelected() {
			return 'store' === el( 'dosieci-sb-type' ).value;
		}

		// -------------------------------------------------------------
		// DOM glue around the pure mapping functions.
		// -------------------------------------------------------------

		/** @param {object} bp Server blueprint shape (SiteBlueprint::toArray()). */
		function populateBlueprintForm( bp ) {
			lastDescribedBlueprint = bp || {};

			var formState = mapDescribeResponseToFormState( bp );

			el( 'dosieci-sb-name' ).value = formState.name;
			el( 'dosieci-sb-description' ).value = formState.description;
			el( 'dosieci-sb-pages' ).value = formState.pages;
			el( 'dosieci-sb-contact-form' ).checked = formState.contactForm;

			if ( formState.siteType ) {
				el( 'dosieci-sb-type' ).value = formState.siteType;
			}

			clearStoreRows();

			if ( formState.store ) {
				el( 'dosieci-sb-store-country' ).value = formState.store.country;
				el( 'dosieci-sb-store-currency' ).value = formState.store.currency;
				el( 'dosieci-sb-store-weight-unit' ).value = formState.store.weightUnit;
				el( 'dosieci-sb-store-dimension-unit' ).value = formState.store.dimensionUnit;

				formState.store.categories.forEach( addCategoryRow );
				formState.store.products.forEach( addProductRow );
			}

			setStoreSectionVisible( isStoreTypeSelected() );
			hideStoreError();
		}

		/** @return {object} The exact shape to JSON.stringify for `dosieci_ai_sb_propose`. */
		function blueprintFromForm() {
			var formState = {
				name: el( 'dosieci-sb-name' ).value,
				description: el( 'dosieci-sb-description' ).value,
				pages: el( 'dosieci-sb-pages' ).value,
				contactForm: el( 'dosieci-sb-contact-form' ).checked,
				siteType: el( 'dosieci-sb-type' ).value,
				store: isStoreTypeSelected() ? readStoreSection() : null,
				passthrough: {
					language: lastDescribedBlueprint.language || 'pl',
					brand_colors: lastDescribedBlueprint.brand_colors || {},
					brand_style: lastDescribedBlueprint.brand_style || 'modern_professional',
					page_builder: lastDescribedBlueprint.page_builder || 'gutenberg'
				}
			};

			return { formState: formState, blueprint: mapFormStateToBlueprint( formState ) };
		}

		function showStoreError( message ) {
			var el2 = el( 'dosieci-sb-store-error' );
			el2.textContent = message;
			el2.hidden = false;
		}

		function hideStoreError() {
			var el2 = el( 'dosieci-sb-store-error' );
			el2.hidden = true;
			el2.textContent = '';
		}

		/**
		 * Natural-language entry point. The answer is a blueprint CANDIDATE:
		 * it is loaded into the structured form so the user can review and edit
		 * it before any plan exists. Nothing is built at this stage.
		 */
		function bindDescribe() {
			var describe = el( 'dosieci-sb-describe' );

			if ( ! describe ) {
				return;
			}

			describe.addEventListener( 'submit', function ( event ) {
				event.preventDefault();

				var request = el( 'dosieci-sb-request' ).value.trim();

				if ( ! request ) {
					return;
				}

				var button = describe.querySelector( 'button' );
				button.disabled = true;
				button.textContent = 'Przygotowuję…';

				post( 'dosieci_ai_sb_describe', { request: request } )
					.then( function ( data ) {
						populateBlueprintForm( data.blueprint || {} );

						button.disabled = false;
						button.textContent = 'Przygotuj opis';
					} )
					.catch( function ( error ) {
						button.disabled = false;
						button.textContent = 'Przygotuj opis';
						showError( error.message );
					} );
			} );
		}

		document.addEventListener( 'DOMContentLoaded', function () {
			bindDescribe();

			var form = el( 'dosieci-sb-form' );

			if ( ! form ) {
				return;
			}

			var typeSelect = el( 'dosieci-sb-type' );
			if ( typeSelect ) {
				typeSelect.addEventListener( 'change', function () {
					setStoreSectionVisible( isStoreTypeSelected() );
				} );

				// Syncs the store section to whatever the select already
				// shows on load -- a browser restoring form state (e.g. bfcache)
				// can leave it on "Sklep" before any change event fires.
				setStoreSectionVisible( isStoreTypeSelected() );
			}

			var addCategoryButton = el( 'dosieci-sb-add-category' );
			if ( addCategoryButton ) {
				addCategoryButton.addEventListener( 'click', function () {
					addCategoryRow();
				} );
			}

			var addProductButton = el( 'dosieci-sb-add-product' );
			if ( addProductButton ) {
				addProductButton.addEventListener( 'click', function () {
					addProductRow();
				} );
			}

			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();

				var result = blueprintFromForm();
				var check = validateFormState( result.formState );

				if ( ! check.valid ) {
					showStoreError( check.message );
					return;
				}

				hideStoreError();

				post( 'dosieci_ai_sb_propose', { blueprint: JSON.stringify( result.blueprint ) } )
					.then( update )
					.catch( function ( error ) {
						showError( error.message );
					} );
			} );
		} );
	}() );

	return pureFunctions;
} ) );

/**
 * Unit tests for the pure describe->review->propose mapping functions in
 * assets/site-builder.js (mapDescribeResponseToFormState,
 * mapFormStateToBlueprint, validateFormState).
 *
 * These pin the exact bug Codex found on real staging: a store blueprint's
 * describe() response reached the review form, but the form's submit
 * handler built a brand-new blueprint containing only generic fields --
 * WooCommerce, store settings, categories and products were silently
 * dropped between "Prepare description" and "Prepare plan". These tests run
 * with no DOM and no WordPress: they exercise only the data transform, the
 * same boundary the browser crosses on every real click.
 *
 * Run with: node --test tests/Assets/site-builder-form.test.js
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );

const {
	mapDescribeResponseToFormState,
	mapFormStateToBlueprint,
	validateFormState
} = require( path.join( __dirname, '..', '..', 'assets', 'site-builder.js' ) );

/** Mirrors SiteBlueprint::toArray() for a store, as a describe()/propose() response would return it. */
function storeDescribeResponse( overrides ) {
	return Object.assign(
		{
			site_type: 'store',
			business_name: 'HydroWear',
			description: '',
			language: 'pl',
			pages: [ 'Start', 'Sklep', 'Kontakt' ],
			features: [ 'contact_form' ],
			brand_colors: { primary: '#0b2e59' },
			brand_style: 'modern_professional',
			page_builder: 'gutenberg',
			woocommerce: true,
			store: {
				store_country: 'PL',
				currency: 'PLN',
				weight_unit: 'kg',
				dimension_unit: 'cm',
				categories: [
					{ logical_role: 'Koszulki', name: 'Koszulki', description: '' },
					{ logical_role: 'Bluzy', name: 'Bluzy', description: '' }
				],
				initial_products: [
					{
						logical_role: 'Koszulka Classic', name: 'Koszulka Classic', description: '',
						short_description: '', regular_price: '79.00',
						category_roles: [ 'Koszulki' ], image_strategy: 'library'
					},
					{
						logical_role: 'Koszulka Premium', name: 'Koszulka Premium', description: '',
						short_description: '', regular_price: '119.00',
						category_roles: [ 'Koszulki' ], image_strategy: 'library'
					},
					{
						logical_role: 'Bluza Classic', name: 'Bluza Classic', description: '',
						short_description: '', regular_price: '189.00',
						category_roles: [ 'Bluzy' ], image_strategy: 'library'
					}
				]
			}
		},
		overrides || {}
	);
}

test( 'store round trip: describe response survives unedited into the propose payload', () => {
	const describeResponse = storeDescribeResponse();

	const formState = mapDescribeResponseToFormState( describeResponse );
	const blueprint = mapFormStateToBlueprint( formState );

	assert.equal( blueprint.site_type, 'store' );
	assert.equal( blueprint.woocommerce, true );
	assert.ok( blueprint.store, 'store must survive the round trip' );
	assert.equal( blueprint.store.store_country, 'PL' );
	assert.equal( blueprint.store.currency, 'PLN' );
	assert.equal( blueprint.store.categories.length, 2 );
	assert.deepEqual(
		blueprint.store.categories.map( ( c ) => c.name ),
		[ 'Koszulki', 'Bluzy' ]
	);
	assert.equal( blueprint.store.initial_products.length, 3 );
	assert.deepEqual(
		blueprint.store.initial_products.map( ( p ) => [ p.name, p.regular_price ] ),
		[
			[ 'Koszulka Classic', '79.00' ],
			[ 'Koszulka Premium', '119.00' ],
			[ 'Bluza Classic', '189.00' ]
		]
	);
} );

test( 'proposed blueprint carries WooCommerce, store settings, categories and products (the exact staging failure)', () => {
	const formState = mapDescribeResponseToFormState( storeDescribeResponse() );
	const blueprint = mapFormStateToBlueprint( formState );

	// This is the assertion that would have failed against the old
	// submit handler: it built { site_type, business_name, description,
	// language, pages, features } and nothing else.
	assert.equal( blueprint.woocommerce, true, 'STORE_PLAN_WOOCOMMERCE' );
	assert.equal( blueprint.store.store_country, 'PL', 'STORE_PLAN_SETTINGS (country)' );
	assert.equal( blueprint.store.currency, 'PLN', 'STORE_PLAN_SETTINGS (currency)' );
	assert.equal( blueprint.store.categories.length, 2, 'STORE_PLAN_CATEGORIES' );
	assert.equal( blueprint.store.initial_products.length, 3, 'STORE_PLAN_PRODUCTS' );
	assert.deepEqual(
		blueprint.store.initial_products.map( ( p ) => p.regular_price ),
		[ '79.00', '119.00', '189.00' ],
		'STORE_PLAN_PRICES'
	);
} );

test( 'editing a price in the review form changes only that price in the proposed blueprint', () => {
	const formState = mapDescribeResponseToFormState( storeDescribeResponse() );

	// Simulates the user retyping the price input for "Koszulka Classic"
	// from 79 to 89 before clicking "Przygotuj plan".
	formState.store.products[ 0 ].regularPrice = '89.00';

	const blueprint = mapFormStateToBlueprint( formState );
	const prices = blueprint.store.initial_products.map( ( p ) => p.regular_price );

	assert.deepEqual( prices, [ '89.00', '119.00', '189.00' ] );

	const serialized = JSON.stringify( blueprint );
	assert.ok( ! serialized.includes( '79.00' ), 'the old price must not survive hidden anywhere in the payload' );
	assert.ok( serialized.includes( '89.00' ) );
} );

test( 'site_type=store with no store data fails closed, not a generic plan', () => {
	const formState = mapDescribeResponseToFormState( { site_type: 'store', business_name: 'X', pages: [ 'Start' ] } );

	assert.equal( formState.store, null );

	const check = validateFormState( formState );
	assert.equal( check.valid, false );
	assert.ok( check.message );

	// Even if validation were bypassed, the mapped blueprint must not
	// silently claim woocommerce -- SiteBlueprint::fromArray() is the
	// server-side backstop that refuses this outright.
	const blueprint = mapFormStateToBlueprint( formState );
	assert.equal( blueprint.woocommerce, false );
	assert.equal( blueprint.store, undefined );
} );

test( 'an ordinary business blueprint round-trips with no store/woocommerce steps', () => {
	const describeResponse = {
		site_type: 'service_business',
		business_name: 'HydroMax',
		description: 'Firma hydrauliczna',
		language: 'pl',
		pages: [ 'Start', 'Oferta', 'Kontakt' ],
		features: [ 'contact_form' ],
		brand_colors: { primary: '#0b2e59' },
		brand_style: 'modern_professional',
		page_builder: 'gutenberg',
		woocommerce: false,
		store: []
	};

	const formState = mapDescribeResponseToFormState( describeResponse );
	assert.equal( formState.store, null );

	const check = validateFormState( formState );
	assert.equal( check.valid, true );

	const blueprint = mapFormStateToBlueprint( formState );
	assert.equal( blueprint.site_type, 'service_business' );
	assert.equal( blueprint.woocommerce, false );
	assert.equal( blueprint.store, undefined );
	assert.deepEqual( blueprint.pages, [ 'Start', 'Oferta', 'Kontakt' ] );
} );

test( 'non-store fields (brand colours, brand style, page builder, language) survive the round trip unedited', () => {
	const describeResponse = storeDescribeResponse( {
		brand_colors: { primary: '#112233', secondary: '#ffffff' },
		brand_style: 'bold_playful',
		page_builder: 'gutenberg',
		language: 'pl'
	} );

	const formState = mapDescribeResponseToFormState( describeResponse );
	const blueprint = mapFormStateToBlueprint( formState );

	assert.deepEqual( blueprint.brand_colors, { primary: '#112233', secondary: '#ffffff' } );
	assert.equal( blueprint.brand_style, 'bold_playful' );
	assert.equal( blueprint.page_builder, 'gutenberg' );
	assert.equal( blueprint.language, 'pl' );
} );

test( 'a product missing a category assignment fails closed', () => {
	const describeResponse = storeDescribeResponse();
	const formState = mapDescribeResponseToFormState( describeResponse );

	formState.store.products[ 0 ].categoryRoles = [];

	const check = validateFormState( formState );
	assert.equal( check.valid, false );
	assert.match( check.message, /categor/i );
} );

test( 'a malformed price fails closed before the request is sent', () => {
	const describeResponse = storeDescribeResponse();
	const formState = mapDescribeResponseToFormState( describeResponse );

	formState.store.products[ 0 ].regularPrice = 'około 80 zl';

	const check = validateFormState( formState );
	assert.equal( check.valid, false );
} );

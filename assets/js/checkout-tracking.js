( function () {
	'use strict';

	if ( ! window.pwtCheckoutTracking ) {
		return;
	}

	const config = window.pwtCheckoutTracking;
	const storageKey = 'pwt_checkout_id';
	const timelineSelector = '#checkout_timeline';
	const formSelector = 'form.checkout, form.woocommerce-checkout';
	let activeStep = '';
	let activityTimer = null;

	function createCheckoutId() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return 'pwt_' + window.crypto.randomUUID().replace( /-/g, '' );
		}

		return 'pwt_' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 18 );
	}

	function getCheckoutId() {
		let id = '';

		try {
			id = window.sessionStorage.getItem( storageKey ) || '';
			if ( ! /^pwt_[a-z0-9_-]{12,80}$/.test( id ) ) {
				id = createCheckoutId();
				window.sessionStorage.setItem( storageKey, id );
			}
		} catch ( error ) {
			id = createCheckoutId();
		}

		return id;
	}

	const checkoutId = getCheckoutId();

	function ensureHiddenField() {
		const form = document.querySelector( formSelector );
		if ( ! form ) {
			return;
		}

		let input = form.querySelector( 'input[name="pwt_checkout_id"]' );
		if ( ! input ) {
			input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = 'pwt_checkout_id';
			form.appendChild( input );
		}

		input.value = checkoutId;
	}

	function getActiveStep() {
		const active = document.querySelector(
			timelineSelector + ' li.active, ' +
			timelineSelector + ' li.current, ' +
			timelineSelector + ' li.yith-wcms-current'
		);

		return active ? ( active.dataset.step || '' ) : '';
	}

	function collectFields() {
		const form = document.querySelector( formSelector );
		const fields = {};

		if ( ! form ) {
			return fields;
		}

		new FormData( form ).forEach( function ( value, key ) {
			if (
				typeof value !== 'string' ||
				/password|pass1|pass2|nonce|payment_token|card|cvv|cvc/i.test( key ) ||
				(
					! /^(billing_|shipping_|order_comments|customer_)/.test( key ) &&
					! /^(gn_billing_number|gn_billing_neighborhood)$/.test( key )
				)
			) {
				return;
			}

			fields[ key ] = value;
		} );

		return fields;
	}

	function sendEvent( eventName, step, useBeacon ) {
		const body = new URLSearchParams();
		body.set( 'action', 'pwt_track_checkout' );
		body.set( 'nonce', config.nonce );
		body.set( 'event', eventName );
		body.set( 'checkout_id', checkoutId );
		body.set( 'step', step || getActiveStep() );
		body.set( 'fields', JSON.stringify( collectFields() ) );

		if ( useBeacon && navigator.sendBeacon ) {
			navigator.sendBeacon( config.ajaxUrl, body );
			return;
		}

		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
			},
			body: body.toString(),
			keepalive: true
		} ).catch( function () {
			// Tracking must never interrupt checkout.
		} );
	}

	function handleStepChange() {
		const nextStep = getActiveStep();

		if ( ! nextStep || nextStep === activeStep ) {
			return;
		}

		const previousStep = activeStep;
		activeStep = nextStep;

		if ( previousStep === 'billing' && nextStep === 'shipping' ) {
			sendEvent( 'checkout.identification_completed', nextStep, false );
		}

		if ( previousStep === 'shipping' && nextStep === 'order' ) {
			sendEvent( 'checkout.contract_data_completed', nextStep, false );
			sendEvent( 'checkout.payment_started', nextStep, false );
		}

		if ( previousStep && previousStep !== nextStep ) {
			scheduleActivity();
		}
	}

	function scheduleActivity() {
		window.clearTimeout( activityTimer );
		activityTimer = window.setTimeout( function () {
			sendEvent( 'checkout.activity', getActiveStep(), false );
		}, 1500 );
	}

	function initialize() {
		ensureHiddenField();
		activeStep = getActiveStep();

		sendEvent( 'checkout.started', activeStep, false );

		const timeline = document.querySelector( timelineSelector );
		if ( timeline ) {
			new MutationObserver( handleStepChange ).observe( timeline, {
				attributes: true,
				subtree: true,
				attributeFilter: [ 'class', 'aria-current' ]
			} );
		}

		document.addEventListener( 'input', scheduleActivity, true );
		document.addEventListener( 'change', scheduleActivity, true );

		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '#place_order' ) ) {
				ensureHiddenField();
				sendEvent( 'checkout.submission_started', getActiveStep(), true );
			}
		}, true );

		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'updated_checkout', function () {
				ensureHiddenField();
				handleStepChange();
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initialize );
	} else {
		initialize();
	}
}() );

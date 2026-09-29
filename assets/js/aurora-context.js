( function () {
	'use strict';

	if ( ! window.pwtAuroraContext || ! window.pwtAuroraContext.aurora_lead_id ) {
		return;
	}

	const context = window.pwtAuroraContext;
	const selectors = {
		phone: '[id="yith-wapo-option-1-0"] .yith-wapo-option-value',
		meetings: '[id="yith-wapo-option-1-1"] .yith-wapo-option-value'
	};
	const desired = {
		phone: context.aurora_phone_recommended === 'sim',
		meetings: context.aurora_plan_recommended === 'mais_horas'
	};
	const userChanged = new Set();
	const automaticChange = new Set();
	let observerQueued = false;

	function dispatchOptionEvents( input ) {
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function synchronizeOption( target ) {
		if ( userChanged.has( target ) || ! desired[ target ] ) {
			return;
		}

		document.querySelectorAll( selectors[ target ] ).forEach( function ( input ) {
			if ( input.disabled || input.checked ) {
				return;
			}

			automaticChange.add( input );
			input.click();
			if ( ! input.checked ) {
				input.checked = true;
				dispatchOptionEvents( input );
			}
			automaticChange.delete( input );
		} );
	}

	function ensureContextFields() {
		document.querySelectorAll( 'form.cart, form.checkout, form.woocommerce-checkout' ).forEach( function ( form ) {
			Object.keys( context ).forEach( function ( key ) {
				if ( ! /^aurora_[a-z0-9_]+$/.test( key ) ) {
					return;
				}

				let input = form.querySelector( 'input[name="' + key + '"]' );
				if ( ! input ) {
					input = document.createElement( 'input' );
					input.type = 'hidden';
					input.name = key;
					form.appendChild( input );
				}
				input.value = String( context[ key ] || '' );
			} );
		} );
	}

	function synchronize() {
		ensureContextFields();
		Object.keys( selectors ).forEach( synchronizeOption );
	}

	function queueSynchronization() {
		if ( observerQueued ) {
			return;
		}

		observerQueued = true;
		window.requestAnimationFrame( function () {
			observerQueued = false;
			synchronize();
		} );
	}

	document.addEventListener( 'change', function ( event ) {
		Object.keys( selectors ).forEach( function ( target ) {
			if ( event.target.matches( selectors[ target ] ) && ! automaticChange.has( event.target ) && event.isTrusted ) {
				userChanged.add( target );
			}
		} );
	}, true );

	document.addEventListener( 'submit', synchronize, true );
	document.addEventListener( 'DOMContentLoaded', synchronize );
	window.addEventListener( 'load', synchronize );

	new MutationObserver( queueSynchronization ).observe( document.documentElement, {
		childList: true,
		subtree: true
	} );

	synchronize();
}() );

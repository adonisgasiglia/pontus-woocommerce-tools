( function () {
	'use strict';

	const campaignStorageKey = 'pwt_campaign_coupon';

	function bridgeCampaignToCheckout() {
		const config = window.pwtCampaignPrices || {};
		const queryArg = config.queryArg || 'pwt_coupon';
		let couponCode = config.couponCode || '';

		try {
			if ( couponCode ) {
				window.sessionStorage.setItem( campaignStorageKey, couponCode );
			} else {
				couponCode = window.sessionStorage.getItem( campaignStorageKey ) || '';
			}
		} catch ( error ) {
			// Continue with the server-provided code when storage is unavailable.
		}

		if ( ! config.isCheckout || ! couponCode ) {
			return false;
		}

		const checkoutUrl = new URL( window.location.href );
		if ( checkoutUrl.searchParams.has( queryArg ) ) {
			return false;
		}

		checkoutUrl.searchParams.set( queryArg, couponCode );
		window.location.replace( checkoutUrl.toString() );
		return true;
	}

	if ( bridgeCampaignToCheckout() ) {
		return;
	}

	if ( ! window.pwtCampaignPrices || ! window.pwtCampaignPrices.prices ) {
		return;
	}

	const selectors = {
		phone: '#yith-wapo-option-1-0',
		meetings: '#yith-wapo-option-1-1'
	};

	const autoSelectedTargets = new Set();
	const userChangedTargets = new Set();
	const synchronizingTargets = new Set();
	const synchronizationTimers = new Map();

	const formatter = new Intl.NumberFormat(
		window.pwtCampaignPrices.locale || 'pt-BR',
		{
			style: 'currency',
			currency: window.pwtCampaignPrices.currency || 'BRL'
		}
	);

	function getCampaignTargets() {
		if ( Array.isArray( window.pwtCampaignPrices.targets ) ) {
			return window.pwtCampaignPrices.targets;
		}

		const targets = Object.keys( window.pwtCampaignPrices.prices );
		const basePrice = window.pwtCampaignPrices.basePrice || {};

		if ( Number( basePrice.sale ) < Number( basePrice.original ) ) {
			targets.push( 'base' );
		}

		return targets;
	}

	function getOriginalPrice( target ) {
		if ( target === 'base' ) {
			return Number( window.pwtCampaignPrices.basePrice.original ) || 0;
		}

		const wrapper = document.querySelector( selectors[ target ] );
		const input = wrapper ? wrapper.querySelector( '.yith-wapo-option-value' ) : null;
		const configured = window.pwtCampaignPrices.prices[ target ];
		const defaultPrice = input ? Number.parseFloat( input.dataset.defaultPrice ) : NaN;
		const currentPrice = input ? Number.parseFloat( input.dataset.price ) : NaN;
		const fallback = configured ? Number( configured.original ) : 0;

		return Number.isFinite( defaultPrice ) && defaultPrice > 0
			? defaultPrice
			: ( Number.isFinite( currentPrice ) && currentPrice > 0 ? currentPrice : fallback );
	}

	function calculateDiscount( eligibleTotal ) {
		const amount = Math.max( Number( window.pwtCampaignPrices.amount ) || 0, 0 );

		if ( window.pwtCampaignPrices.mode === 'free' ) {
			return eligibleTotal;
		}

		if ( window.pwtCampaignPrices.mode === 'percent' ) {
			return Math.min( eligibleTotal * Math.min( amount, 100 ) / 100, eligibleTotal );
		}

		return Math.min( amount, eligibleTotal );
	}

	function getSalePrice( target, original ) {
		const targets = getCampaignTargets();

		if ( ! targets.includes( target ) ) {
			return original;
		}

		if ( window.pwtCampaignPrices.mode === 'free' ) {
			return 0;
		}

		if ( window.pwtCampaignPrices.mode === 'percent' ) {
			const percentage = Math.min( Number( window.pwtCampaignPrices.amount ) || 0, 100 );
			return Math.max( 0, original * ( 1 - percentage / 100 ) );
		}

		if ( Number( window.pwtCampaignPrices.targetCount ) === 1 ) {
			return Math.max( 0, original - ( Number( window.pwtCampaignPrices.amount ) || 0 ) );
		}

		const eligibleTotal = targets.reduce( function ( total, targetKey ) {
			return total + getOriginalPrice( targetKey );
		}, 0 );
		const allocatedDiscount = eligibleTotal > 0
			? calculateDiscount( eligibleTotal ) * original / eligibleTotal
			: 0;

		return Math.max( 0, original - allocatedDiscount );
	}

	function getOptionData( target ) {
		const wrapper = document.querySelector( selectors[ target ] );

		if ( ! wrapper ) {
			return null;
		}

		const input = wrapper.querySelector( '.yith-wapo-option-value' );
		if ( ! input ) {
			return null;
		}

		const original = getOriginalPrice( target );

		return {
			wrapper,
			input,
			original,
			sale: getSalePrice( target, original )
		};
	}

	function renderOptionPrice( target ) {
		const option = getOptionData( target );
		const configured = window.pwtCampaignPrices.prices[ target ];

		if ( ! option || ! configured ) {
			return;
		}

		const priceElement = option.wrapper.querySelector( '.option-price' );
		if ( ! priceElement ) {
			return;
		}

		const signature = option.original.toFixed( 4 ) + ':' + option.sale.toFixed( 4 );
		if ( priceElement.dataset.pwtCampaignSignature === signature ) {
			return;
		}

		priceElement.dataset.pwtCampaignSignature = signature;
		priceElement.classList.add( 'pwt-campaign-option-price' );
		priceElement.innerHTML =
			'<span class="brackets">(</span>' +
			'<del>' + formatter.format( option.original ) + '</del>' +
			'<ins><span class="sign positive">+</span>' + formatter.format( option.sale ) + '</ins>' +
			'<span class="brackets">)</span>';
	}

	function renderSummaryPrice() {
		const priceElements = document.querySelectorAll( '[data-pwt-plan-price]' );

		if ( ! priceElements.length || ! window.pwtCampaignPrices.basePrice ) {
			return;
		}

		let originalTotal = Number( window.pwtCampaignPrices.basePrice.original ) || 0;
		let eligibleTotal = getCampaignTargets().includes( 'base' ) ? originalTotal : 0;

		Object.keys( selectors ).forEach( function ( target ) {
			const option = getOptionData( target );

			if ( option && option.input.checked ) {
				originalTotal += option.original;
				if ( getCampaignTargets().includes( target ) ) {
					eligibleTotal += option.original;
				}
			}
		} );

		const saleTotal = Math.max( 0, originalTotal - calculateDiscount( eligibleTotal ) );

		const signature = originalTotal.toFixed( 4 ) + ':' + saleTotal.toFixed( 4 );

		priceElements.forEach( function ( priceElement ) {
			if ( priceElement.dataset.pwtCampaignSignature === signature ) {
				return;
			}

			priceElement.dataset.pwtCampaignSignature = signature;
			priceElement.classList.add( 'pwt-campaign-summary-price' );

			const periodHtml =
				'<span class="pwt-plan-price-period">' +
				( window.pwtCampaignPrices.period || '/mês' ) +
				'</span>';

			if ( saleTotal < originalTotal ) {
				priceElement.innerHTML =
					'<del>' + formatter.format( originalTotal ) + '</del>' +
					'<ins>' + formatter.format( saleTotal ) + '</ins>' +
					periodHtml;
			} else {
				priceElement.innerHTML =
					'<span class="woocommerce-Price-amount amount"><bdi>' +
					formatter.format( saleTotal ) +
					'</bdi></span>' +
					periodHtml;
			}
		} );
	}

	function getTargetForInput( input ) {
		return Object.keys( selectors ).find( function ( target ) {
			const wrapper = document.querySelector( selectors[ target ] );
			return wrapper && wrapper.contains( input );
		} ) || '';
	}

	function dispatchOptionEvents( input ) {
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function isOptionIncludedInForm( option ) {
		const form = option.input.closest( 'form.cart' );

		if (
			! form ||
			! option.input.name ||
			option.input.disabled ||
			! option.input.checked
		) {
			return false;
		}

		try {
			return new FormData( form ).getAll( option.input.name ).some( function ( value ) {
				return String( value ) === String( option.input.value );
			} );
		} catch ( error ) {
			return option.input.checked;
		}
	}

	function synchronizeCampaignOption( target, forceEvents ) {
		const option = getOptionData( target );

		if (
			! option ||
			option.input.disabled ||
			! option.input.isConnected ||
			userChangedTargets.has( target ) ||
			synchronizingTargets.has( target )
		) {
			return false;
		}

		const form = option.input.closest( 'form.cart' );
		if ( ! form || ! option.input.name ) {
			return false;
		}

		synchronizingTargets.add( target );

		try {
			const wasChecked = option.input.checked;

			if ( ! wasChecked ) {
				option.input.click();
			}

			if ( ! option.input.checked ) {
				option.input.checked = true;
				dispatchOptionEvents( option.input );
			} else if ( wasChecked || forceEvents ) {
				dispatchOptionEvents( option.input );
			}

			return isOptionIncludedInForm( option );
		} finally {
			synchronizingTargets.delete( target );
		}
	}

	function scheduleOptionResynchronization( target ) {
		if ( synchronizationTimers.has( target ) ) {
			return;
		}

		const delays = [ 100, 400, 900 ];
		const timers = delays.map( function ( delay, index ) {
			return window.setTimeout( function () {
				if ( ! userChangedTargets.has( target ) ) {
					synchronizeCampaignOption( target, true );
					renderSummaryPrice();
				}

				if ( index === delays.length - 1 ) {
					synchronizationTimers.delete( target );
				}
			}, delay );
		} );

		synchronizationTimers.set( target, timers );
	}

	function preselectCampaignOptions() {
		Object.keys( window.pwtCampaignPrices.prices ).forEach( function ( target ) {
			const configured = window.pwtCampaignPrices.prices[ target ];
			const option = getOptionData( target );

			if (
				! configured ||
				! option ||
				option.sale >= option.original ||
				option.input.disabled ||
				userChangedTargets.has( target ) ||
				synchronizingTargets.has( target ) ||
				( autoSelectedTargets.has( target ) && option.input.checked )
			) {
				return;
			}

			if ( synchronizeCampaignOption( target, true ) ) {
				autoSelectedTargets.add( target );
				scheduleOptionResynchronization( target );
			}
		} );
	}

	function synchronizeBeforeSubmission( form ) {
		autoSelectedTargets.forEach( function ( target ) {
			const option = getOptionData( target );

			if (
				option &&
				form.contains( option.input ) &&
				! userChangedTargets.has( target )
			) {
				synchronizeCampaignOption( target, true );
			}
		} );
	}

	function renderCampaignPrices() {
		preselectCampaignOptions();
		Object.keys( window.pwtCampaignPrices.prices ).forEach( renderOptionPrice );
		renderSummaryPrice();
	}

	document.addEventListener( 'DOMContentLoaded', renderCampaignPrices );
	document.addEventListener( 'click', function ( event ) {
		if ( event.isTrusted ) {
			Object.keys( selectors ).forEach( function ( target ) {
				const wrapper = document.querySelector( selectors[ target ] );

				if ( wrapper && wrapper.contains( event.target ) ) {
					userChangedTargets.add( target );
				}
			} );
		}

		const submitButton = event.target.closest(
			'form.cart .single_add_to_cart_button, form.cart button[type="submit"], form.cart input[type="submit"]'
		);

		if ( submitButton ) {
			const form = submitButton.closest( 'form.cart' );
			if ( form ) {
				synchronizeBeforeSubmission( form );
			}
		}
	}, true );
	document.addEventListener( 'change', function ( event ) {
		if ( event.target.matches( '.yith-wapo-option-value' ) ) {
			const target = getTargetForInput( event.target );

			if ( target && event.isTrusted ) {
				userChangedTargets.add( target );
			}

			renderCampaignPrices();
		}
	} );
	document.addEventListener( 'submit', function ( event ) {
		if ( event.target.matches( 'form.cart' ) ) {
			synchronizeBeforeSubmission( event.target );
		}
	}, true );
	window.addEventListener( 'load', renderCampaignPrices );

	if ( Object.keys( window.pwtCampaignPrices.prices ).length || document.querySelector( '[data-pwt-plan-price]' ) ) {
		const observer = new MutationObserver( renderCampaignPrices );
		observer.observe( document.documentElement, {
			childList: true,
			subtree: true
		} );
	}
}() );

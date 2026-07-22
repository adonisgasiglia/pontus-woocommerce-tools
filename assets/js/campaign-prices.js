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

	const formatter = new Intl.NumberFormat(
		window.pwtCampaignPrices.locale || 'pt-BR',
		{
			style: 'currency',
			currency: window.pwtCampaignPrices.currency || 'BRL'
		}
	);

	function getSalePrice( target, original ) {
		const configured = window.pwtCampaignPrices.prices[ target ];

		if ( ! configured ) {
			return original;
		}

		if ( window.pwtCampaignPrices.mode === 'free' ) {
			return 0;
		}

		if ( window.pwtCampaignPrices.mode === 'percent' ) {
			const percentage = Math.min( Number( window.pwtCampaignPrices.amount ) || 0, 100 );
			return Math.max( 0, original * ( 1 - percentage / 100 ) );
		}

		if ( window.pwtCampaignPrices.targetCount === 1 ) {
			return Math.max( 0, original - ( Number( window.pwtCampaignPrices.amount ) || 0 ) );
		}

		const ratio = configured.original > 0 ? configured.sale / configured.original : 1;
		return Math.max( 0, original * ratio );
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

		const configured = window.pwtCampaignPrices.prices[ target ];
		const defaultPrice = Number.parseFloat( input.dataset.defaultPrice );
		const currentPrice = Number.parseFloat( input.dataset.price );
		const fallback = configured ? Number( configured.original ) : 0;
		const original = Number.isFinite( defaultPrice ) && defaultPrice > 0
			? defaultPrice
			: ( Number.isFinite( currentPrice ) && currentPrice > 0 ? currentPrice : fallback );

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
		let saleTotal = Number( window.pwtCampaignPrices.basePrice.sale );
		if ( ! Number.isFinite( saleTotal ) ) {
			saleTotal = originalTotal;
		}

		Object.keys( selectors ).forEach( function ( target ) {
			const option = getOptionData( target );

			if ( option && option.input.checked ) {
				originalTotal += option.original;
				saleTotal += option.sale;
			}
		} );

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

	function preselectCampaignOptions() {
		Object.keys( window.pwtCampaignPrices.prices ).forEach( function ( target ) {
			const configured = window.pwtCampaignPrices.prices[ target ];
			const option = getOptionData( target );

			if (
				! configured ||
				Number( configured.sale ) >= Number( configured.original ) ||
				! option ||
				option.input.disabled ||
				autoSelectedTargets.has( target )
			) {
				return;
			}

			autoSelectedTargets.add( target );

			if ( option.input.checked ) {
				return;
			}

			option.input.click();

			if ( ! option.input.checked ) {
				option.input.checked = true;
				option.input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				option.input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
		} );
	}

	function renderCampaignPrices() {
		preselectCampaignOptions();
		Object.keys( window.pwtCampaignPrices.prices ).forEach( renderOptionPrice );
		renderSummaryPrice();
	}

	document.addEventListener( 'DOMContentLoaded', renderCampaignPrices );
	document.addEventListener( 'change', function ( event ) {
		if ( event.target.matches( '.yith-wapo-option-value' ) ) {
			renderCampaignPrices();
		}
	} );
	window.addEventListener( 'load', renderCampaignPrices );

	if ( Object.keys( window.pwtCampaignPrices.prices ).length || document.querySelector( '[data-pwt-plan-price]' ) ) {
		const observer = new MutationObserver( renderCampaignPrices );
		observer.observe( document.documentElement, {
			childList: true,
			subtree: true
		} );
	}
}() );

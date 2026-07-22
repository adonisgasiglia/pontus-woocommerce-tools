( function () {
	'use strict';

	if ( ! window.pwtCampaignPrices || ! window.pwtCampaignPrices.prices ) {
		return;
	}

	const selectors = {
		phone: '#yith-wapo-option-1-0',
		meetings: '#yith-wapo-option-1-1'
	};

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

	function renderOptionPrice( target ) {
		const wrapper = document.querySelector( selectors[ target ] );

		if ( ! wrapper ) {
			return;
		}

		const input = wrapper.querySelector( '.yith-wapo-option-value' );
		const priceElement = wrapper.querySelector( '.option-price' );
		const configured = window.pwtCampaignPrices.prices[ target ];

		if ( ! input || ! priceElement || ! configured ) {
			return;
		}

		const dataPrice = Number.parseFloat( input.dataset.price );
		const original = Number.isFinite( dataPrice ) && dataPrice > 0
			? dataPrice
			: Number( configured.original );

		const sale = getSalePrice( target, original );
		const signature = original.toFixed( 4 ) + ':' + sale.toFixed( 4 );

		if ( priceElement.dataset.pwtCampaignSignature === signature ) {
			return;
		}

		priceElement.dataset.pwtCampaignSignature = signature;
		priceElement.classList.add( 'pwt-campaign-option-price' );
		priceElement.innerHTML =
			'<span class="brackets">(</span>' +
			'<del><span class="sign positive">+</span>' + formatter.format( original ) + '</del>' +
			'<ins><span class="sign positive">+</span>' + formatter.format( sale ) + '</ins>' +
			'<span class="brackets">)</span>';
	}

	function renderCampaignPrices() {
		Object.keys( window.pwtCampaignPrices.prices ).forEach( renderOptionPrice );
	}

	document.addEventListener( 'DOMContentLoaded', renderCampaignPrices );
	window.addEventListener( 'load', renderCampaignPrices );

	const observer = new MutationObserver( renderCampaignPrices );
	observer.observe( document.documentElement, {
		childList: true,
		subtree: true
	} );
}() );

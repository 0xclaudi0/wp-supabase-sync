/**
 * Settings screen behaviour: the Test Connection button.
 *
 * No credentials ever reach this file. The button posts a nonce and nothing
 * else; the server reads the stored keys, runs the test and returns only
 * human-readable results.
 */
( function () {
	'use strict';

	var config = window.wpsbAdmin || {};

	function statusLabel( status ) {
		switch ( status ) {
			case 'pass':
				return '✓';
			case 'warn':
				return '!';
			case 'skip':
				return '–';
			default:
				return '✗';
		}
	}

	function render( target, results ) {
		target.textContent = '';

		var list = document.createElement( 'ul' );
		list.className = 'wpsb-results-list';

		results.forEach( function ( result ) {
			var item = document.createElement( 'li' );
			item.className = 'wpsb-result wpsb-result--' + result.status;

			var badge = document.createElement( 'span' );
			badge.className = 'wpsb-badge';
			badge.textContent = statusLabel( result.status );
			badge.setAttribute( 'aria-hidden', 'true' );

			var label = document.createElement( 'strong' );
			label.textContent = result.label;

			var detail = document.createElement( 'p' );
			// textContent, not innerHTML: these strings include server messages
			// and error bodies, which are not ours to trust as markup.
			detail.textContent = result.detail;

			item.appendChild( badge );
			item.appendChild( label );
			item.appendChild( detail );
			list.appendChild( item );
		} );

		target.appendChild( list );
	}

	function renderError( target, message ) {
		target.textContent = '';

		var paragraph = document.createElement( 'p' );
		paragraph.className = 'wpsb-result wpsb-result--fail';
		paragraph.textContent = message;

		target.appendChild( paragraph );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'wpsb-test-connection' );
		var target = document.getElementById( 'wpsb-test-results' );

		if ( ! button || ! target ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			button.textContent = config.i18n.testing;
			target.textContent = '';

			var body = new FormData();
			body.append( 'action', config.action );
			body.append( 'nonce', config.nonce );

			window
				.fetch( config.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body,
				} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					if ( payload && payload.success && payload.data && payload.data.results ) {
						render( target, payload.data.results );

						return;
					}

					var message =
						payload && payload.data && payload.data.message
							? payload.data.message
							: config.i18n.failed;

					renderError( target, message );
				} )
				.catch( function () {
					renderError( target, config.i18n.failed );
				} )
				.finally( function () {
					button.disabled = false;
					button.textContent = config.i18n.testAgain;
				} );
		} );
	} );
} )();

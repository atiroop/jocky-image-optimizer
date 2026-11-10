( function () {
	'use strict';

	const container = document.getElementById( 'jio-queue-stats' );
	if ( ! container || ! window.jioQueue ) {
		return;
	}

	const update = function () {
		const body = new URLSearchParams( {
			action: window.jioQueue.action,
			_ajax_nonce: window.jioQueue.nonce,
		} );
		fetch( window.jioQueue.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Queue statistics request failed.' );
				}
				return response.json();
			} )
			.then( function ( response ) {
				if ( ! response.success ) {
					return;
				}
				Object.keys( response.data.stats ).forEach( function ( key ) {
					const node = document.getElementById( 'jio-stat-' + key );
					if ( node ) {
						const value = response.data.stats[ key ];
						node.textContent = 'number' === typeof value ? new Intl.NumberFormat( undefined, { maximumFractionDigits: 2 } ).format( value ) : value;
					}
				} );
				const progress = document.getElementById( 'jio-stat-scan' );
				if ( progress ) {
					const scan = response.data.scan;
					progress.textContent = scan.status + ' — ' + scan.scanned + ' of ' + scan.total_images + ' attachments scanned; ' + scan.jobs_added + ' jobs queued.';
				}
			} )
			.catch( function () {} );
	};

	window.setInterval( update, 10000 );
} )();

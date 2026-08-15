/* Tender Gateway - watchlist toggle. */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.tg-save' );
		if ( ! button ) {
			return;
		}

		event.preventDefault();

		if ( button.disabled ) {
			return;
		}
		button.disabled = true;

		var body = new URLSearchParams();
		body.append( 'action', 'tg_toggle_saved' );
		body.append( 'nonce', window.TGConfig.nonce );
		body.append( 'tender', button.dataset.tender );

		fetch( window.TGConfig.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( response ) {
				return response.json().then( function ( payload ) {
					return { ok: response.ok, payload: payload };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					var data = result.payload && result.payload.data;
					if ( data && data.login_url ) {
						window.location.href = data.login_url;
						return;
					}
					throw new Error( data && data.message ? data.message : 'Request failed' );
				}

				var saved = result.payload.data.saved;
				button.classList.toggle( 'is-saved', saved );
				button.setAttribute( 'aria-pressed', saved ? 'true' : 'false' );
			} )
			.catch( function () {
				button.classList.add( 'tg-save--error' );
			} )
			.finally( function () {
				button.disabled = false;
			} );
	} );
}() );

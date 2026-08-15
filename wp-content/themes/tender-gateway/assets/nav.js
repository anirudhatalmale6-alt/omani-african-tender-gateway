/* Mobile navigation toggle. */
( function () {
	'use strict';

	var burger = document.querySelector( '.tgt-burger' );
	var nav    = document.getElementById( 'tgt-nav' );

	if ( ! burger || ! nav ) {
		return;
	}

	burger.addEventListener( 'click', function () {
		var open = burger.getAttribute( 'aria-expanded' ) === 'true';
		burger.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
		document.body.classList.toggle( 'tgt-nav-open', ! open );
	} );

	// Close the panel when a link inside it is followed.
	nav.addEventListener( 'click', function ( event ) {
		if ( event.target.closest( 'a' ) ) {
			burger.setAttribute( 'aria-expanded', 'false' );
			document.body.classList.remove( 'tgt-nav-open' );
		}
	} );
}() );

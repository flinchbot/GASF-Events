/**
 * GASF Events — progressive enhancement.
 * Registers Alpine components (signage carousel), renders QR codes, and turns
 * the month grid's prev/next links into fetch-swapped, animated navigation.
 * Everything degrades to working server HTML if JS is off.
 */
( function () {
	'use strict';

	/* ---- Alpine components (registered before Alpine boots) ---- */
	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'gasfTiles', function ( count ) {
			return {
				i: 0,
				count: count,
				timer: null,
				start: function () {
					if ( this.count > 1 ) {
						var self = this;
						this.timer = setInterval( function () { self.next(); }, 7000 );
					}
				},
				next: function () { this.i = ( this.i + 1 ) % this.count; },
				go: function ( n ) { this.i = n; clearInterval( this.timer ); this.start(); },
			};
		} );
	} );

	/* ---- QR codes (data-gasf-qr="<url>") ---- */
	function renderQR( root ) {
		if ( typeof window.qrcode !== 'function' ) { return; }
		( root || document ).querySelectorAll( '[data-gasf-qr]:not([data-gasf-qr-done])' ).forEach( function ( el ) {
			try {
				var qr = window.qrcode( 0, 'M' );
				qr.addData( el.getAttribute( 'data-gasf-qr' ) );
				qr.make();
				el.innerHTML = qr.createSvgTag( { cellSize: 3, margin: 1, scalable: true } );
				el.setAttribute( 'data-gasf-qr-done', '1' );
			} catch ( e ) { /* ignore */ }
		} );
	}

	/* ---- Month grid: fetch-swapped prev/next ---- */
	function swapCalendar( url ) {
		fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.text(); } )
			.then( function ( html ) {
				var doc = new DOMParser().parseFromString( html, 'text/html' );
				var next = doc.querySelector( '[data-gasf-cal]' );
				var cur = document.querySelector( '[data-gasf-cal]' );
				if ( ! next || ! cur ) { window.location.href = url; return; }
				var apply = function () { cur.replaceWith( next ); renderQR( document ); };
				if ( document.startViewTransition ) {
					document.startViewTransition( apply );
				} else {
					apply();
				}
				window.history.pushState( {}, '', url );
			} )
			.catch( function () { window.location.href = url; } );
	}

	document.addEventListener( 'click', function ( ev ) {
		var link = ev.target.closest ? ev.target.closest( '[data-gasf-cal] [data-gasf-nav]' ) : null;
		if ( ! link ) { return; }
		ev.preventDefault();
		swapCalendar( link.href );
	} );

	window.addEventListener( 'popstate', function () {
		if ( document.querySelector( '[data-gasf-cal]' ) ) { swapCalendar( window.location.href ); }
	} );

	if ( document.readyState !== 'loading' ) {
		renderQR( document );
	} else {
		document.addEventListener( 'DOMContentLoaded', function () { renderQR( document ); } );
	}

	/* ---- View-count beacon (single event pages) ---- */
	if ( window.GASF_VIEW && window.GASF_VIEW.id && navigator.sendBeacon ) {
		try {
			var fd = new FormData();
			fd.append( 'id', window.GASF_VIEW.id );
			fd.append( 'ctx', window.GASF_VIEW.ctx || 'web' );
			navigator.sendBeacon( window.GASF_VIEW.url, fd );
		} catch ( e ) { /* ignore */ }
	}
} )();

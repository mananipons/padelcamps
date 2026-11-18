/**
 * PadelCamps public views: live refresh, "find your pair" and TV rotation.
 * No dependencies. Works on every container with data-pc-live / .pc-find / data-pc-rotate.
 */
( function () {
	'use strict';

	var i18n = window.padelcampsPublic || { found: '%d', none: '', updated: '' };

	function store( key, value ) {
		try {
			if ( value === undefined ) {
				return window.localStorage.getItem( key ) || '';
			}
			window.localStorage.setItem( key, value );
		} catch ( e ) {}
		return '';
	}

	function normalize( text ) {
		return ( text || '' ).toString().toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).trim();
	}

	// --- Find your pair ---------------------------------------------------------
	function applyFind( root ) {
		var input = root.querySelector( '.pc-find' );
		if ( ! input ) {
			return;
		}
		var q = normalize( input.value );
		var active = q.length >= 3;
		var names = root.querySelectorAll( '.pc-m-name, .pc-standings .pc-name' );
		var found = 0;
		names.forEach( function ( el ) {
			el.classList.toggle( 'is-mine', active && normalize( el.textContent ).indexOf( q ) !== -1 );
		} );
		root.querySelectorAll( '.pc-m' ).forEach( function ( m ) {
			var mine = active && m.querySelector( '.is-mine' );
			m.classList.toggle( 'is-mine-match', !! mine );
			if ( mine ) {
				found++;
			}
		} );
		root.classList.toggle( 'pc-filtering', active );
		var count = root.querySelector( '.pc-find-count' );
		if ( count ) {
			count.textContent = active ? ( found ? i18n.found.replace( '%d', found ) : i18n.none ) : '';
		}
	}

	function initFind( root ) {
		var input = root.querySelector( '.pc-find' );
		if ( ! input ) {
			return;
		}
		var key = 'padelcamps-find-' + ( root.getAttribute( 'data-pc-tid' ) || '' );
		input.value = store( key );
		input.addEventListener( 'input', function () {
			store( key, input.value );
			applyFind( root );
		} );
		applyFind( root );
	}

	// --- TV rotation and clock ------------------------------------------------------
	function rotate( root, step ) {
		var panels = root.querySelectorAll( '.pc-tv-panel' );
		if ( ! panels.length ) {
			return;
		}
		var index = ( parseInt( root.getAttribute( 'data-pc-index' ) || '0', 10 ) + step ) % panels.length;
		root.setAttribute( 'data-pc-index', String( index ) );
		panels.forEach( function ( p, i ) {
			p.classList.toggle( 'is-active', i === index );
		} );
		marquee( root );
	}

	// Groups of the visible TV panel: one row, scrolling like a marquee when they do not fit.
	function marquee( root ) {
		if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			return;
		}
		root.querySelectorAll( '.pc-tv-panel.is-active .pc-groups' ).forEach( function ( box ) {
			if ( box.getAttribute( 'data-pc-marquee' ) ) {
				return;
			}
			box.setAttribute( 'data-pc-marquee', '1' );
			if ( box.scrollWidth <= box.clientWidth + 1 ) {
				return;
			}
			var items = Array.prototype.slice.call( box.children );
			var track = document.createElement( 'div' );
			track.className = 'pc-groups-track';
			items.forEach( function ( item ) {
				track.appendChild( item );
			} );
			box.appendChild( track );
			var gap = parseFloat( window.getComputedStyle( track ).columnGap ) || 0;
			var one = track.getBoundingClientRect().width + gap;
			items.forEach( function ( item ) {
				var copy = item.cloneNode( true );
				copy.setAttribute( 'aria-hidden', 'true' );
				track.appendChild( copy );
			} );
			track.style.setProperty( '--pc-shift', one + 'px' );
			track.style.setProperty( '--pc-dur', Math.max( 20, one / 60 ) + 's' );
			track.classList.add( 'is-scrolling' );
		} );
	}

	function initTv( root ) {
		var seconds = parseInt( root.getAttribute( 'data-pc-rotate' ) || '0', 10 );
		if ( ! seconds ) {
			return;
		}
		rotate( root, 0 );
		setInterval( function () {
			rotate( root, 1 );
		}, seconds * 1000 );
		var tick = function () {
			var clock = root.querySelector( '.pc-tv-clock' );
			if ( clock ) {
				clock.textContent = new Date().toLocaleTimeString( document.documentElement.lang || undefined, { hour: '2-digit', minute: '2-digit' } );
			}
		};
		tick();
		setInterval( tick, 10000 );
	}

	// --- Live refresh -----------------------------------------------------------------
	function initLive( root ) {
		var url = root.getAttribute( 'data-pc-live' );
		var body = root.querySelector( '.pc-live-body' );
		if ( ! url || ! body || ! window.fetch ) {
			return;
		}
		var every = Math.max( 10, parseInt( root.getAttribute( 'data-pc-interval' ) || '30', 10 ) ) * 1000;
		var busy = false;

		function refresh() {
			if ( busy || document.hidden ) {
				return;
			}
			busy = true;
			fetch( url, { credentials: 'omit', cache: 'no-store' } )
				.then( function ( r ) {
					return r.ok ? r.json() : null;
				} )
				.then( function ( data ) {
					if ( ! data || ! data.hash || data.hash === root.getAttribute( 'data-pc-hash' ) ) {
						return;
					}
					// Keep the horizontal position of each bracket (mobile swipe).
					var scrolls = Array.prototype.map.call( body.querySelectorAll( '.pc-bracket' ), function ( b ) {
						return b.scrollLeft;
					} );
					body.innerHTML = data.html;
					Array.prototype.forEach.call( body.querySelectorAll( '.pc-bracket' ), function ( b, i ) {
						if ( scrolls[ i ] ) {
							b.scrollLeft = scrolls[ i ];
						}
					} );
					root.setAttribute( 'data-pc-hash', data.hash );
					applyFind( root );
					rotate( root, 0 );
					var dot = root.querySelector( '.pc-live-dot' );
					if ( dot ) {
						dot.classList.remove( 'is-flash' );
						void dot.offsetWidth;
						dot.classList.add( 'is-flash' );
					}
				} )
				.catch( function () {} )
				.then( function () {
					busy = false;
				} );
		}

		setInterval( refresh, every );
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) {
				refresh();
			}
		} );
	}

	function init() {
		document.querySelectorAll( '.pc-draw[data-pc-tid]' ).forEach( function ( root ) {
			initFind( root );
			initTv( root );
			initLive( root );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

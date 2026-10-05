/**
 * Foliora document library: expand a grid card in place and lazy-bind
 * the existing Foliora viewer (window.FolioraViewer.bind).
 */
( function () {
	'use strict';

	function $( sel, root ) {
		return ( root || document ).querySelector( sel );
	}

	function closeItem( item ) {
		var card = $( '.foliora-library-card', item );
		var embed = $( '.foliora-library-embed', item );
		item.classList.remove( 'is-open' );
		if ( card ) {
			card.setAttribute( 'aria-expanded', 'false' );
		}
		if ( embed ) {
			embed.hidden = true;
		}
	}

	function openItem( item ) {
		var card = $( '.foliora-library-card', item );
		var embed = $( '.foliora-library-embed', item );
		var viewer = embed ? $( '.foliora-viewer', embed ) : null;

		item.classList.add( 'is-open' );
		if ( card ) {
			card.setAttribute( 'aria-expanded', 'true' );
		}
		if ( embed ) {
			embed.hidden = false;
		}

		if ( viewer && window.FolioraViewer && typeof window.FolioraViewer.bind === 'function' ) {
			viewer.removeAttribute( 'data-foliora-defer' );
			window.FolioraViewer.bind( viewer );
		}
	}

	function onCardClick( event ) {
		var card = event.currentTarget;
		var item = card.closest( '.foliora-library-item' );
		var library = card.closest( '.foliora-library' );
		if ( ! item || ! library ) {
			return;
		}

		var willOpen = ! item.classList.contains( 'is-open' );
		Array.prototype.forEach.call( library.querySelectorAll( '.foliora-library-item.is-open' ), function ( open ) {
			if ( open !== item ) {
				closeItem( open );
			}
		} );

		if ( willOpen ) {
			openItem( item );
		} else {
			closeItem( item );
		}
	}

	function fetchLibrary( library, targetUrl ) {
		library.classList.add( 'is-loading' );
		window
			.fetch( targetUrl, {
				headers: { 'X-Requested-With': 'XMLHttpRequest' },
			} )
			.then( function ( res ) {
				return res.text();
			} )
			.then( function ( html ) {
				var parser = new window.DOMParser();
				var doc = parser.parseFromString( html, 'text/html' );
				var id = library.id;
				var newLibrary = id ? doc.getElementById( id ) : doc.querySelector( '.foliora-library' );
				if ( newLibrary ) {
					library.innerHTML = newLibrary.innerHTML;
					bindLibrary( library );
					if ( window.history && window.history.pushState ) {
						window.history.pushState( null, '', targetUrl );
					}
				} else {
					window.location.href = targetUrl;
				}
			} )
			.catch( function () {
				window.location.href = targetUrl;
			} )
			.finally( function () {
				library.classList.remove( 'is-loading' );
			} );
	}

	function bindLibrary( library ) {
		if ( ! library ) {
			return;
		}

		Array.prototype.forEach.call( library.querySelectorAll( '.foliora-library-card' ), function ( card ) {
			card.removeEventListener( 'click', onCardClick );
			card.addEventListener( 'click', onCardClick );
		} );

		Array.prototype.forEach.call( library.querySelectorAll( '.foliora-library-pager a' ), function ( link ) {
			link.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				fetchLibrary( library, link.href );
			} );
		} );

		var searchForm = library.querySelector( '.foliora-library-search' );
		if ( searchForm ) {
			searchForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var formData = new window.FormData( searchForm );
				var url = new URL( window.location.href );
				formData.forEach( function ( val, key ) {
					if ( val ) {
						url.searchParams.set( key, val );
					} else {
						url.searchParams.delete( key );
					}
				} );
				fetchLibrary( library, url.toString() );
			} );
		}
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '.foliora-library' ), bindLibrary );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

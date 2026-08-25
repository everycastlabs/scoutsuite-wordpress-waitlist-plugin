/**
 * wpsl-leaflet.js
 *
 * Drop-in front-end replacement for WP Store Locator's wpsl-gmap.js.
 * Renders the [wpsl] map + search using Leaflet and OpenStreetMap tiles
 * instead of the Google Maps JavaScript API.
 *
 * Loaded via the 'wpsl_gmap_js' filter (see functions.php snippet).
 * Reads the same wpslSettings object WP Store Locator already localizes,
 * and talks to the same unchanged AJAX endpoint (?action=store_search)
 * that WPSL uses for its distance search - only lat/lng in, JSON stores out.
 *
 * Covers: map render, store markers + popups, postcode/address search
 * (geocoded via postcodes.io), "use my location", results list, autoload.
 *
 * NOT covered:
 * - Turn-by-turn directions panel
 * - Street View
 * - Marker dragging (admin editor map)
 *
 * Admin-side geocoding is handled in functions.php (postcodes.io intercepts
 * WPSL's Google Geocoding HTTP calls).
 */

( function () {
	'use strict';

	var map, markersLayer, markerClusterGroup;
	var storeIcon, startIcon;
	var settings   = window.wpslSettings || {};
	var labels     = window.wpslLabels || {};
	var lastSearch = null;

	/* ---------- Bootstrapping: load Leaflet from CDN, then init ---------- */

	function loadCss( href ) {
		var link = document.createElement( 'link' );
		link.rel  = 'stylesheet';
		link.href = href;
		document.head.appendChild( link );
	}

	function loadScript( src, callback ) {
		var script  = document.createElement( 'script' );
		script.src  = src;
		script.onload = callback;
		document.head.appendChild( script );
	}

	function boot() {
		if ( ! document.getElementById( 'wpsl-gmap' ) ) {
			return; // No map on this page.
		}

		// Leaflet is enqueued with the page. Do not wait on a second CDN fetch.
		if ( window.L ) {
			initWpslLeaflet();
			return;
		}

		loadCss( 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css' );
		loadCss( 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css' );
		loadCss( 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css' );

		loadScript( 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', function () {
			loadScript( 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js', initWpslLeaflet );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	/* ---------------------------- Map init ---------------------------- */

	function initWpslLeaflet() {

		var start = parseStartLatlng( settings.startLatlng );

		map = L.map( 'wpsl-gmap' ).setView( [ start.lat, start.lng ], parseInt( settings.zoomLevel, 10 ) || 12 );

		L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
		} ).addTo( map );

		storeIcon = buildIcon( resolveMarkerUrl( settings.storeMarker ) );
		startIcon = buildIcon( resolveMarkerUrl( settings.startMarker ) );

		ensureSideBySideLayout();

		markerClusterGroup = ( typeof L.markerClusterGroup === 'function' )
			? L.markerClusterGroup()
			: L.layerGroup();
		markersLayer = L.layerGroup();

		bindSearchForm();
		bindLocateMe();
		bindResetButton();
		bindDropdowns();

		var searchInput = document.getElementById( 'wpsl-search-input' );
		var prefilledQuery = searchInput ? searchInput.value.trim() : '';

		if ( prefilledQuery ) {
			// Search box already has a value (e.g. from a ?wpsl-search-input=
			// URL parameter set by a search box on another page) - run the
			// search automatically instead of waiting for a manual click.
			geocode( prefilledQuery, function ( latlng ) {
				if ( latlng ) {
					performSearch( latlng.lat, latlng.lng );
				} else {
					showMessage( labels.generalError || 'Location not found, please try again.' );
				}
			} );
		} else if ( settings.autoLoadStores && settings.autoLoadStores.length ) {
			lastSearch = { lat: start.lat, lng: start.lng };
			renderResults( settings.autoLoadStores );
		} else if ( settings.autoLoad ) {
			performSearch( start.lat, start.lng );
		}
	}

	function ensureSideBySideLayout() {
		var gmap = document.getElementById( 'wpsl-gmap' );
		var resultList = document.getElementById( 'wpsl-result-list' );

		if ( ! gmap || ! resultList || document.getElementById( 'wpsl-leaflet-layout' ) ) {
			return; // Already wrapped, or the expected elements aren't present.
		}

		var wrapper = document.createElement( 'div' );
		wrapper.id = 'wpsl-leaflet-layout';

		gmap.parentNode.insertBefore( wrapper, gmap );
		wrapper.appendChild( gmap );
		wrapper.appendChild( resultList );
	}

	function parseStartLatlng( raw ) {
		if ( ! raw ) {
			return { lat: 51.5074, lng: -0.1278 }; // Fallback: London.
		}
		var parts = String( raw ).split( ',' );
		return { lat: parseFloat( parts[0] ), lng: parseFloat( parts[1] ) };
	}

	function resolveMarkerUrl( filename ) {
		if ( ! filename ) {
			return null;
		}

		// Custom marker directory override, if the site defines WPSL_MARKER_URI.
		if ( settings.markerIconProps && settings.markerIconProps.url ) {
			return settings.markerIconProps.url + filename;
		}

		// Default: WPSL's own plugin folder + img/markers/, same as wpsl-gmap.js.
		return settings.url + 'img/markers/' + filename;
	}

	function buildIcon( url ) {
		if ( ! url ) {
			return null; // Leaflet default marker.
		}

		var size   = [ 24, 35 ];
		var anchor = [ 12, 35 ];

		if ( settings.markerIconProps && settings.markerIconProps.scaledSize ) {
			size = settings.markerIconProps.scaledSize.split( ',' ).map( Number );
		}
		if ( settings.markerIconProps && settings.markerIconProps.anchor ) {
			anchor = settings.markerIconProps.anchor.split( ',' ).map( Number );
		}

		return L.icon( {
			iconUrl: url,
			iconSize: size,
			iconAnchor: anchor,
			popupAnchor: [ 0, -anchor[1] ]
		} );
	}

	/* --------------------------- Search form --------------------------- */

	function bindSearchForm() {
		var input = document.getElementById( 'wpsl-search-input' );
		var btn   = document.getElementById( 'wpsl-search-btn' );

		if ( ! input || ! btn ) {
			return;
		}

		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var query = input.value.trim();
			if ( ! query ) {
				return;
			}
			geocode( query, function ( latlng ) {
				if ( latlng ) {
					performSearch( latlng.lat, latlng.lng );
				} else {
					showMessage( labels.generalError || 'Location not found, please try again.' );
				}
			} );
		} );

		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				btn.click();
			}
		} );
	}

	function bindLocateMe() {
		if ( ! navigator.geolocation ) {
			return;
		}

		var locateBtn = document.getElementById( 'wpsl-locate-me' );

		// No template edit required - if the button isn't already in the markup,
		// create it and drop it in next to the Search button.
		if ( ! locateBtn ) {
			var btnWrap = document.querySelector( '.wpsl-search-btn-wrap' );
			if ( ! btnWrap ) {
				return;
			}
			locateBtn = document.createElement( 'button' );
			locateBtn.id = 'wpsl-locate-me';
			locateBtn.type = 'button';
			locateBtn.className = 'wpsl-locate-me-btn';
			locateBtn.textContent = labels.useMyLocation || 'Use my location';
			btnWrap.appendChild( locateBtn );
		}

		locateBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			locateBtn.disabled = true;
			var originalText = locateBtn.textContent;
			locateBtn.textContent = labels.locating || 'Locating…';

			navigator.geolocation.getCurrentPosition(
				function ( pos ) {
					locateBtn.disabled = false;
					locateBtn.textContent = originalText;
					performSearch( pos.coords.latitude, pos.coords.longitude );
				},
				function () {
					locateBtn.disabled = false;
					locateBtn.textContent = originalText;
					showMessage( labels.generalError || 'Could not determine your location.' );
				},
				{ timeout: settings.geoLocationTimeout || 7500 }
			);
		} );
	}

	function bindResetButton() {
		// No template edit required, same auto-inject approach as locate-me.
		var wrap = document.querySelector( '.wpsl-search-btn-wrap' );
		if ( ! wrap || document.getElementById( 'wpsl-reset-btn' ) ) {
			return;
		}

		var resetBtn = document.createElement( 'button' );
		resetBtn.id = 'wpsl-reset-btn';
		resetBtn.type = 'button';
		resetBtn.className = 'wpsl-reset-btn';
		resetBtn.textContent = labels.back || 'Reset';
		wrap.appendChild( resetBtn );

		resetBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var input = document.getElementById( 'wpsl-search-input' );
			if ( input ) {
				input.value = '';
			}
			var start = parseStartLatlng( settings.startLatlng );
			map.setView( [ start.lat, start.lng ], parseInt( settings.zoomLevel, 10 ) || 12 );
			if ( settings.autoLoadStores && settings.autoLoadStores.length ) {
				lastSearch = { lat: start.lat, lng: start.lng };
				renderResults( settings.autoLoadStores );
			} else if ( settings.autoLoad ) {
				performSearch( start.lat, start.lng );
			} else {
				markersLayer.clearLayers();
				markerClusterGroup.clearLayers();
				map.removeLayer( markersLayer );
				map.removeLayer( markerClusterGroup );
				var listEl = document.getElementById( 'wpsl-stores' );
				if ( listEl ) {
					listEl.innerHTML = '';
				}
			}
		} );
	}

	function bindDropdowns() {
		// Radius / max-results dropdowns re-run the last search with new values.
		var radiusEl  = document.getElementById( 'wpsl-radius-dropdown' );
		var resultsEl = document.getElementById( 'wpsl-results-dropdown' );

		[ radiusEl, resultsEl ].forEach( function ( el ) {
			if ( ! el ) {
				return;
			}
			el.addEventListener( 'change', function () {
				if ( radiusEl ) {
					settings.searchRadius = radiusEl.value;
				}
				if ( resultsEl ) {
					settings.maxResults = resultsEl.value;
				}
				if ( lastSearch ) {
					performSearch( lastSearch.lat, lastSearch.lng );
				}
			} );
		} );
	}

	/* ----------------- Geocoding (postcodes.io, client-side) ----------------- */

	var UK_POSTCODE = /\b([A-Z]{1,2}\d[A-Z\d]?)\s*(\d[A-Z]{2})\b/i;
	var UK_OUTCODE  = /^[A-Z]{1,2}\d[A-Z\d]?$/i;

	function extractUkPostcode( text ) {
		var matches = String( text ).toUpperCase().match( new RegExp( UK_POSTCODE.source, 'gi' ) );
		if ( ! matches || ! matches.length ) {
			return null;
		}
		var last = matches[ matches.length - 1 ].replace( /\s+/g, '' );
		return last.slice( 0, -3 ) + ' ' + last.slice( -3 );
	}

	function postcodesIo( path ) {
		return fetch( 'https://api.postcodes.io' + path, {
			headers: { 'Accept': 'application/json' }
		} ).then( function ( res ) {
			if ( ! res.ok ) {
				return null;
			}
			return res.json();
		} );
	}

	function latlngFromResult( result ) {
		if ( ! result || typeof result.latitude !== 'number' || typeof result.longitude !== 'number' ) {
			return null;
		}
		return { lat: result.latitude, lng: result.longitude };
	}

	function geocode( query, callback ) {
		var trimmed = String( query || '' ).trim();
		if ( ! trimmed ) {
			callback( null );
			return;
		}

		var postcode = extractUkPostcode( trimmed );
		var request;

		if ( postcode ) {
			request = postcodesIo( '/postcodes/' + encodeURIComponent( postcode.replace( /\s+/g, '' ) ) )
				.then( function ( data ) { return data ? latlngFromResult( data.result ) : null; } );
		} else if ( UK_OUTCODE.test( trimmed ) ) {
			request = postcodesIo( '/outcodes/' + encodeURIComponent( trimmed.toUpperCase() ) )
				.then( function ( data ) { return data ? latlngFromResult( data.result ) : null; } );
		} else {
			var firstPart = trimmed.split( ',' )[0].trim();
			request = postcodesIo( '/places?q=' + encodeURIComponent( firstPart ) + '&limit=1' )
				.then( function ( data ) {
					var place = data && data.result && data.result[0];
					return latlngFromResult( place );
				} );
		}

		request.then( callback ).catch( function () { callback( null ); } );
	}

	/* --------------------- Talking to WPSL's own AJAX --------------------- */
	/* This endpoint and its SQL distance search are untouched - provider    */
	/* agnostic, always was. We just supply lat/lng however we obtained them.*/

	function performSearch( lat, lng ) {
		lastSearch = { lat: lat, lng: lng };

		var params = new URLSearchParams( {
			action: 'store_search',
			lat: lat,
			lng: lng,
			autoload: settings.autoLoad ? '1' : ''
		} );

		if ( settings.searchRadius ) {
			params.set( 'radius', settings.searchRadius );
		}
		if ( settings.maxResults ) {
			params.set( 'max_results', settings.maxResults );
		}

		fetch( settings.ajaxurl + '?' + params.toString() )
			.then( function ( res ) { return res.json(); } )
			.then( renderResults )
			.catch( function () {
				showMessage( labels.generalError || 'Something went wrong, please try again!' );
			} );
	}

	/* ------------------------- Rendering results ------------------------- */

	function renderResults( stores ) {
		markersLayer.clearLayers();
		markerClusterGroup.clearLayers();
		map.removeLayer( markersLayer );
		map.removeLayer( markerClusterGroup );

		var listEl = document.getElementById( 'wpsl-stores' );
		if ( listEl ) {
			listEl.innerHTML = '';
		}

		if ( ! stores || ! stores.length ) {
			showMessage( labels.noResults || 'No results found' );
			return;
		}

		var bounds = [];
		var useCluster = !! settings.clusterZoom || !! settings.clusterSize;
		var targetLayer = useCluster ? markerClusterGroup : markersLayer;

		stores.forEach( function ( store, index ) {
			var lat = parseFloat( store.lat );
			var lng = parseFloat( store.lng );

			if ( isNaN( lat ) || isNaN( lng ) ) {
				return;
			}

			bounds.push( [ lat, lng ] );

			var marker = L.marker( [ lat, lng ], { icon: storeIcon || undefined } );
			marker.bindPopup( buildPopupHtml( store ) );
			targetLayer.addLayer( marker );

			if ( listEl ) {
				listEl.appendChild( buildListItem( store, marker ) );
			}
		} );

		map.addLayer( targetLayer );

		if ( bounds.length ) {
			map.fitBounds( bounds, { padding: [ 40, 40 ], maxZoom: 15 } );
		}
	}

	function decodeHtmlEntities( str ) {
		if ( ! str ) {
			return '';
		}
		var textarea = document.createElement( 'textarea' );
		textarea.innerHTML = str;
		return textarea.value;
	}

	function buildPopupHtml( store ) {
		var html = '<div class="wpsl-leaflet-popup">';
		html += '<strong>' + escapeHtml( decodeHtmlEntities( store.store || '' ) ) + '</strong><br>';
		if ( store.address ) {
			html += escapeHtml( decodeHtmlEntities( store.address ) ) + '<br>';
		}
		if ( store.city ) {
			html += escapeHtml( decodeHtmlEntities( store.city ) ) + ' ' + escapeHtml( store.zip || '' ) + '<br>';
		}
		if ( store.phone ) {
			html += escapeHtml( store.phone ) + '<br>';
		}
		if ( store.permalink ) {
			html += '<a href="' + store.permalink + '">' + ( labels.moreInfo || 'More info' ) + '</a>';
		} else if ( store.url ) {
			html += '<a href="' + store.url + '" target="_blank" rel="noopener">' + ( labels.moreInfo || 'More info' ) + '</a>';
		}
		html += '</div>';
		return html;
	}

	function buildListItem( store, marker ) {
		var li = document.createElement( 'div' );
		li.className = 'wpsl-store';

		var title = document.createElement( 'div' );
		title.className = 'wpsl-store-title';
		title.textContent = decodeHtmlEntities( store.store || '' );
		li.appendChild( title );

		if ( store.address ) {
			var addr = document.createElement( 'div' );
			addr.className = 'wpsl-store-address';
			addr.textContent = decodeHtmlEntities( store.address ) + ( store.city ? ', ' + decodeHtmlEntities( store.city ) : '' );
			li.appendChild( addr );
		}

		if ( typeof store.distance !== 'undefined' ) {
			var dist = document.createElement( 'div' );
			dist.className = 'wpsl-store-distance';
			dist.textContent = parseFloat( store.distance ).toFixed( 1 ) + ' ' + ( settings.distanceUnit || 'km' );
			li.appendChild( dist );
		}

		li.addEventListener( 'click', function () {
			map.setView( marker.getLatLng(), 15 );
			marker.openPopup();
		} );

		return li;
	}

	function showMessage( text ) {
		var listEl = document.getElementById( 'wpsl-stores' );
		if ( listEl ) {
			listEl.innerHTML = '<div class="wpsl-no-results">' + escapeHtml( text ) + '</div>';
		}
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML;
	}

} )();
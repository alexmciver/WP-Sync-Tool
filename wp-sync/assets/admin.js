/* WP Sync admin screen */
( function () {
	'use strict';
	var cfg = window.WPSYNC || {};
	var $ = function ( sel, root ) { return ( root || document ).querySelector( sel ); };
	var $$ = function ( sel, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) ); };

	function post( action, data ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( '_ajax_nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) { fd.append( k, data[ k ] ); } );
		return fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.text().then( function ( t ) {
					try { return JSON.parse( t ); } catch ( e ) {
						throw new Error( 'The server returned an unexpected response (HTTP ' + r.status + '). ' + ( r.status >= 500 ? 'Check the PHP error log; a pull step may have run out of time or memory.' : '' ) );
					}
				} );
			} )
			.then( function ( j ) {
				if ( ! j || ! j.success ) {
					throw new Error( ( j && j.data && j.data.message ) || 'Request failed.' );
				}
				return j.data;
			} );
	}

	function el( tag, cls, text ) {
		var e = document.createElement( tag );
		if ( cls ) { e.className = cls; }
		if ( text !== undefined ) { e.textContent = text; }
		return e;
	}

	function notice( container, type, msg ) {
		var n = el( 'div', 'notice notice-' + type + ' inline' );
		n.appendChild( el( 'p', '', msg ) );
		container.appendChild( n );
		return n;
	}

	/* -------------------------------------------------------- Copy / reveal */

	$$( '.wpsync-copy-btn' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var input = b.parentNode.querySelector( 'input' );
			var val = input.value;
			( navigator.clipboard ? navigator.clipboard.writeText( val ) : Promise.reject() ).catch( function () {
				var t = input.type; input.type = 'text'; input.select(); document.execCommand( 'copy' ); input.type = t;
			} );
			b.textContent = 'Copied'; setTimeout( function () { b.textContent = 'Copy'; }, 1500 );
		} );
	} );
	$$( '.wpsync-reveal' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var input = b.parentNode.querySelector( 'input' );
			input.type = input.type === 'password' ? 'text' : 'password';
			b.textContent = input.type === 'password' ? 'Show' : 'Hide';
		} );
	} );

	if ( ! cfg.configured ) { return; }

	var statusBox = $( '#wpsync-status' );
	var notices = $( '#wpsync-notices' );
	var btnPreview = $( '#wpsync-preview' );
	var btnPull = $( '#wpsync-pull' );
	var progress = $( '#wpsync-progress' );
	var result = $( '#wpsync-result' );
	var running = false;

	/* ---------------------------------------------------------------- Status */

	function stat( label, value, cls ) {
		var d = el( 'div', 'wpsync-stat ' + ( cls || '' ) );
		d.appendChild( el( 'span', 'wpsync-stat-value', String( value ) ) );
		d.appendChild( el( 'span', 'wpsync-stat-label', label ) );
		return d;
	}

	function loadStatus() {
		statusBox.className = 'wpsync-status is-loading';
		statusBox.innerHTML = '<span class="spinner is-active"></span> Checking live site…';
		return post( 'wpsync_status' ).then( function ( d ) {
			statusBox.className = 'wpsync-status';
			statusBox.innerHTML = '';
			statusBox.appendChild( stat( 'changes waiting on live', d.waiting, d.waiting ? 'is-hot' : '' ) );
			statusBox.appendChild( stat( 'changed here since last pull', d.local ) );
			statusBox.appendChild( stat( 'conflicts', d.conflicts, d.conflicts ? 'is-warn' : '' ) );
			statusBox.appendChild( stat( 'last pull', d.last_pull ) );
			notices.innerHTML = '';
			( d.notices || [] ).forEach( function ( m ) { notice( notices, 'info', m ); } );
			btnPreview.disabled = btnPull.disabled = false;
		} ).catch( function ( e ) {
			statusBox.className = 'wpsync-status is-error';
			statusBox.textContent = e.message;
			btnPreview.disabled = btnPull.disabled = true;
		} );
	}

	/* ------------------------------------------------------------------ Pull */

	function n( count, one, many ) { return count + ' ' + ( count === 1 ? one : many ); }

	function list( title, items, cls, fmt ) {
		if ( ! items || ! items.length ) { return null; }
		var wrap = el( 'div', 'wpsync-list ' + ( cls || '' ) );
		var det = el( 'details' );
		if ( items.length <= 12 || cls === 'is-warn' || cls === 'is-error' ) { det.open = true; }
		det.appendChild( el( 'summary', '', title + ' (' + items.length + ')' ) );
		var ul = el( 'ul' );
		items.forEach( function ( i ) { ul.appendChild( el( 'li', '', fmt ? fmt( i ) : i ) ); } );
		det.appendChild( ul );
		wrap.appendChild( det );
		return wrap;
	}

	function renderReport( d ) {
		var r = d.report;
		result.innerHTML = '';
		var total = r.created.length + r.updated.length + r.deleted.length;
		var head = el( 'div', 'wpsync-summary ' + ( r.errors.length ? 'is-error' : 'is-ok' ) );
		var verb = d.dry ? 'Preview: ' : 'Done: ';
		head.appendChild( el( 'strong', '', verb + ( total || r.conflicts.length ? '' : 'nothing to pull. ' ) ) );
		head.appendChild( document.createTextNode(
			r.created.length + ' new, ' + r.updated.length + ' updated, ' + r.deleted.length + ' deleted, ' +
			n( r.conflicts.length, 'conflict', 'conflicts' ) + ( d.dry ? '' : ', ' + n( r.files, 'media file', 'media files' ) + ' downloaded' ) + '.'
		) );
		if ( d.backup ) { head.appendChild( el( 'div', 'description', 'Backup saved first: ' + d.backup ) ); }
		result.appendChild( head );

		[
			list( d.dry ? 'Would create' : 'Created', r.created ),
			list( d.dry ? 'Would update' : 'Updated', r.updated ),
			list( d.dry ? 'Would delete' : 'Deleted', r.deleted ),
			list( 'Conflicts, left as they are here', r.conflicts, 'is-warn', function ( c ) { return c.label + ' – ' + c.reason; } ),
			list( 'Skipped', r.skipped, 'is-warn' ),
			list( 'Errors', r.errors, 'is-error' )
		].forEach( function ( n ) { if ( n ) { result.appendChild( n ); } } );

		if ( d.dry && total ) {
			var p = el( 'p' );
			var b = el( 'button', 'button button-primary', 'Pull these changes now' );
			b.type = 'button';
			b.addEventListener( 'click', function () { run( false ); } );
			p.appendChild( b );
			result.appendChild( p );
		}
	}

	function setProgress( d ) {
		var bar = $( '.wpsync-bar span', progress );
		var text = $( '.wpsync-progress-text', progress );
		var pct = 0;
		if ( d && d.mode === 'changes' && d.last_id ) {
			pct = Math.min( 100, Math.round( ( d.after / d.last_id ) * 100 ) );
		} else if ( d ) {
			pct = d.done ? 100 : Math.min( 95, d.page * 10 );
		}
		if ( d && d.done ) { pct = 100; }
		bar.style.width = pct + '%';
		if ( d ) {
			var r = d.report;
			text.textContent = ( d.done ? 'Finished. ' : 'Working… ' ) + 'Batch ' + d.page + ' · ' + r.created.length + ' new · ' + r.updated.length + ' updated · ' + r.deleted.length + ' deleted · ' + n( r.conflicts.length, 'conflict', 'conflicts' );
		}
	}

	function run( dry ) {
		if ( running ) { return; }
		if ( ! dry && $( '#wpsync-theirs' ).checked && ! window.confirm( 'Live will overwrite your local edits wherever both changed. Continue?' ) ) { return; }
		running = true;
		btnPreview.disabled = btnPull.disabled = true;
		result.innerHTML = '';
		notices.innerHTML = '';
		progress.hidden = false;
		$( '.wpsync-bar span', progress ).style.width = '2%';
		$( '.wpsync-progress-text', progress ).textContent = dry ? 'Checking what would change…' : 'Backing up and starting…';
		window.onbeforeunload = function () { return 'A pull is running.'; };

		var params = {
			step: 'start',
			dry: dry ? 1 : '',
			theirs: $( '#wpsync-theirs' ).checked ? 1 : '',
			redownload: $( '#wpsync-redownload' ).checked ? 1 : '',
			since: $( '#wpsync-since' ).value || ''
		};

		function next( p ) {
			return post( 'wpsync_pull', p ).then( function ( d ) {
				( d.notices || [] ).forEach( function ( m ) { notice( notices, 'info', m ); } );
				setProgress( d );
				if ( ! d.done ) { return next( { step: 'next' } ); }
				return d;
			} );
		}

		next( params ).then( function ( d ) {
			renderReport( d );
			if ( ! d.dry ) {
				$( '#wpsync-conflicts' ).innerHTML = d.conflicts_html;
				$( '#wpsync-conflict-count' ).textContent = '(' + d.conflict_count + ')';
				$( '#wpsync-since' ).value = '';
				loadLocal();
			}
		} ).catch( function ( e ) {
			notice( result, 'error', e.message );
		} ).then( function () {
			running = false;
			window.onbeforeunload = null;
			setTimeout( function () { progress.hidden = true; }, 800 );
			loadStatus();
		} );
	}

	btnPreview.addEventListener( 'click', function () { run( true ); } );
	btnPull.addEventListener( 'click', function () { run( false ); } );

	/* ------------------------------------------------------------- Conflicts */

	document.addEventListener( 'click', function ( e ) {
		var take = e.target.closest( '.wpsync-take' );
		var keep = e.target.closest( '.wpsync-keep' );
		if ( ! take && ! keep ) { return; }
		var row = e.target.closest( 'tr' );
		if ( take && ! window.confirm( 'Replace your local version of this item with the live version? (A backup is made first.)' ) ) { return; }
		$$( 'button', row ).forEach( function ( b ) { b.disabled = true; } );
		var cell = $( '.wpsync-row-actions', row );
		post( 'wpsync_resolve', { key: row.getAttribute( 'data-key' ), mode: take ? 'take' : 'keep' } ).then( function ( d ) {
			cell.textContent = d.message;
			row.classList.add( 'is-resolved' );
			$( '#wpsync-conflict-count' ).textContent = '(' + d.conflict_count + ')';
			setTimeout( function () { row.parentNode && row.parentNode.removeChild( row ); }, 2500 );
			loadStatus();
			loadLocal();
		} ).catch( function ( err ) {
			$$( 'button', row ).forEach( function ( b ) { b.disabled = false; } );
			window.alert( err.message );
		} );
	} );

	/* --------------------------------------------------------- Local changes */

	var localLoaded = false;
	function loadLocal() {
		var box = $( '#wpsync-local' );
		if ( ! box ) { return; }
		post( 'wpsync_local_changes' ).then( function ( d ) { box.innerHTML = d.html; localLoaded = true; } )
			.catch( function ( e ) { box.textContent = e.message; } );
	}
	var det = $( '#wpsync-local-details' );
	if ( det ) {
		det.addEventListener( 'toggle', function () { if ( det.open && ! localLoaded ) { loadLocal(); } } );
	}

	loadStatus();
} )();

/* WP Sync admin screen */
( function () {
	'use strict';
	var cfg = window.WPSYNC || {};
	window.WPSYNC_LOADED = true;
	var $ = function ( sel, root ) { return ( root || document ).querySelector( sel ); };
	var $$ = function ( sel, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) ); };

	var controller = null;
	function post( action, data, timeoutMs ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( '_ajax_nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) { fd.append( k, data[ k ] ); } );
		var ctrl = window.AbortController ? new AbortController() : null;
		var timer = null;
		if ( ctrl && timeoutMs ) {
			controller = ctrl;
			timer = setTimeout( function () { ctrl.abort( 'timeout' ); }, timeoutMs );
		}
		return fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined } )
			.catch( function ( e ) {
				if ( ctrl && ctrl.signal.aborted ) {
					var err = new Error( ctrl.signal.reason === 'timeout'
						? 'This site stopped responding while pulling (no reply for ' + Math.round( timeoutMs / 1000 ) + ' seconds). Progress up to the last finished batch is saved. Try again; if it keeps happening, the live site may be slow or blocking requests.'
						: 'Cancelled.' );
					err.code = ctrl.signal.reason === 'timeout' ? 'timeout' : 'cancelled';
					throw err;
				}
				throw new Error( 'Network error: ' + e.message );
			} )
			.then( function ( r ) { clearTimeout( timer ); return r; } )
			.then( function ( r ) {
				return r.text().then( function ( t ) {
					try { return JSON.parse( t ); } catch ( e ) {
						throw new Error( 'The server returned an unexpected response (HTTP ' + r.status + '). ' + ( r.status >= 500 ? 'Check the PHP error log; a pull step may have run out of time or memory.' : '' ) );
					}
				} );
			} )
			.then( function ( j ) {
				if ( ! j || ! j.success ) {
					var err = new Error( ( j && j.data && j.data.message ) || 'Request failed.' );
					err.code = j && j.data && j.data.code;
					throw err;
				}
				return j.data;
			} );
	}

	function n( count, one, many ) { return count + ' ' + ( count === 1 ? one : many ); }

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
			var ls = d.last_summary;
			statusBox.appendChild( stat( 'last pull', d.last_pull ) );
			var sum = $( '#wpsync-last-summary' );
			if ( sum ) {
				sum.hidden = ! ls;
				if ( ls ) {
					sum.textContent = 'Last pull: ' + ls.created + ' new, ' + ls.updated + ' updated, ' + ls.deleted + ' deleted, ' + n( ls.conflicts, 'conflict', 'conflicts' ) + ( ls.files ? ', ' + n( ls.files, 'file', 'files' ) + ' downloaded' : '' ) + ( ls.errors ? ' – ' + n( ls.errors, 'error', 'errors' ) : '' ) + '.';
				}
			}
			notices.innerHTML = '';
			( d.notices || [] ).forEach( function ( m ) { notice( notices, 'info', m ); } );
			var rec = $( '#wpsync-recording' );
			if ( rec && d.recording_since && d.never_pulled ) {
				rec.hidden = false;
				rec.textContent = 'Live has been recording changes since ' + d.recording_since + '. Content that differed before then needs "Pull all content from live" (More options).';
			}
			btnPreview.disabled = btnPull.disabled = false;
		} ).catch( function ( e ) {
			statusBox.className = 'wpsync-status is-error';
			statusBox.textContent = e.message;
			if ( e.code === 'wpsync_bad_key' || e.code === 'wpsync_no_secret' ) {
				var conn = $( '#wpsync-connection' );
				if ( conn ) { conn.open = true; var k = $( '#wpsync-key' ); if ( k ) { k.focus(); } }
			}
			btnPreview.disabled = btnPull.disabled = true;
		} );
	}

	/* ------------------------------------------------------------------ Pull */

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

	var lastActivity = '', lastCounts = '', startedAt = 0, ticker = null;
	function paintProgress() {
		var secs = Math.round( ( Date.now() - startedAt ) / 1000 );
		var t = secs < 60 ? secs + 's' : Math.floor( secs / 60 ) + 'm ' + ( secs % 60 ) + 's';
		$( '.wpsync-progress-text', progress ).textContent = lastActivity + ( lastCounts ? ' · ' + lastCounts : '' ) + ' · ' + t;
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
			lastActivity = ( d.done ? 'Finished' : ( d.activity || 'Working…' ) );
			lastCounts = r.created.length + ' new · ' + r.updated.length + ' updated · ' + r.deleted.length + ' deleted · ' + n( r.conflicts.length, 'conflict', 'conflicts' ) + ( r.files ? ' · ' + n( r.files, 'file', 'files' ) + ' downloaded' : '' );
			paintProgress();
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
		startedAt = Date.now();
		lastActivity = dry ? 'Checking what would change…' : 'Connecting to live…';
		lastCounts = '';
		paintProgress();
		ticker = setInterval( paintProgress, 1000 );
		btnCancel.hidden = false;
		cancelled = false;
		window.onbeforeunload = function () { return 'A pull is running.'; };

		var params = {
			step: 'start',
			dry: dry ? 1 : '',
			theirs: $( '#wpsync-theirs' ).checked ? 1 : '',
			redownload: $( '#wpsync-redownload' ).checked ? 1 : '',
			since: $( '#wpsync-since' ).value || '',
			all: $( '#wpsync-all' ).checked ? 1 : ''
		};

		function next( p ) {
			if ( cancelled ) { var e = new Error( 'Cancelled.' ); e.code = 'cancelled'; return Promise.reject( e ); }
			return post( 'wpsync_pull', p, 180000 ).then( function ( d ) {
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
				$( '#wpsync-all' ).checked = false;
				loadLocal();
			}
		} ).catch( function ( e ) {
			if ( e.code === 'cancelled' ) {
				notice( result, 'warning', 'Stopped. Everything up to the last finished batch is kept; press Pull again to continue.' );
			} else if ( e.code === 'wpsync_locked' ) {
				var n2 = notice( result, 'warning', e.message + ' If you know nothing is running (for example the page was closed mid-pull), unlock it: ' );
				var ub = el( 'button', 'button', 'Unlock and pull again' );
				ub.type = 'button';
				ub.addEventListener( 'click', function () {
					post( 'wpsync_cancel' ).then( function () { result.innerHTML = ''; run( dry ); } );
				} );
				n2.querySelector( 'p' ).appendChild( ub );
			} else {
				notice( result, 'error', e.message );
				if ( e.code === 'timeout' ) { post( 'wpsync_cancel' ).catch( function () {} ); }
			}
		} ).then( function () {
			running = false;
			clearInterval( ticker );
			btnCancel.hidden = true;
			window.onbeforeunload = null;
			setTimeout( function () { progress.hidden = true; }, 800 );
			loadStatus();
		} );
	}

	var cancelled = false;
	var btnCancel = $( '#wpsync-cancel' );
	btnCancel.addEventListener( 'click', function () {
		cancelled = true;
		btnCancel.hidden = true;
		lastActivity = 'Stopping after the current step…';
		paintProgress();
		if ( controller ) { controller.abort( 'cancel' ); }
		post( 'wpsync_cancel' ).catch( function () {} );
	} );

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

	/* -------------------------------------------------- Copy everything from live */

	var fullBtn = $( '#wpsync-full-start' );
	if ( fullBtn ) {
		var fullCancel = $( '#wpsync-full-cancel' );
		var fullProg = $( '#wpsync-full-progress' );
		var fullRes = $( '#wpsync-full-result' );
		var fullStop = false;
		var setFull = function ( pct, text ) {
			$( '.wpsync-bar span', fullProg ).style.width = Math.max( 2, pct ) + '%';
			$( '.wpsync-progress-text', fullProg ).textContent = text;
		};
		fullCancel.addEventListener( 'click', function () {
			fullStop = true;
			fullCancel.hidden = true;
			setFull( 0, 'Cancelling…' );
			post( 'wpsync_full', { step: 'cancel' } ).catch( function () {} ); // Stops the running step on the server.
		} );
		fullBtn.addEventListener( 'click', function () {
			if ( running ) { return; }
			if ( ! window.confirm( 'Replace this site\'s WHOLE database with a copy of live?\n\nEverything here (content, settings, users) becomes what\'s on live. Your current database is backed up first and can be restored from "Undo a pull or copy".\n\nAfterwards, log in with your live site\'s username and password.' ) ) { return; }
			running = true; fullStop = false;
			fullBtn.disabled = btnPull.disabled = btnPreview.disabled = true;
			fullCancel.hidden = false; fullProg.hidden = false; fullRes.innerHTML = '';
			var t0 = Date.now();
			setFull( 2, 'Connecting to live…' );
			window.onbeforeunload = function () { return 'A copy is running.'; };
			var next = function ( p ) {
				if ( fullStop ) {
					var e = new Error( 'Cancelled. Nothing on this site was changed.' ); e.code = 'cancelled';
					return Promise.reject( e );
				}
				return post( 'wpsync_full', p, 300000 ).then( function ( d ) {
					( d.notices || [] ).forEach( function ( m ) { notice( fullRes, 'info', m ); } );
					var secs = Math.round( ( Date.now() - t0 ) / 1000 );
					setFull( d.pct, d.activity + ' · ' + d.rows.toLocaleString() + ' rows' + ( d.files_got ? ' · ' + d.files_got + ' files downloaded' : '' ) + ' · ' + ( secs < 60 ? secs + 's' : Math.floor( secs / 60 ) + 'm ' + ( secs % 60 ) + 's' ) );
					return d.done ? d : next( { step: 'next' } );
				} );
			};
			next( { step: 'start', files: $( '#wpsync-full-files' ).checked ? 1 : '' } ).then( function ( d ) {
				window.onbeforeunload = null;
				var n = notice( fullRes, 'success', 'Done: this site is now a copy of live (' + d.tables + ' tables, ' + d.rows.toLocaleString() + ' rows' + ( d.files_got ? ', ' + d.files_got + ' files downloaded' : '' ) + '). From now on, normal pulls bring across only what changes on live. ' );
				var a = el( 'a', 'button button-primary', 'Log in again (use your live username and password)' );
				a.href = d.login;
				n.querySelector( 'p' ).appendChild( a );
				if ( d.errors && d.errors.length ) { fullRes.appendChild( list( 'Warnings', d.errors, 'is-warn' ) ); }
			} ).catch( function ( e ) {
				window.onbeforeunload = null;
				var wasCancel = e.code === 'cancelled' || e.code === 'wpsync_cancelled';
				notice( fullRes, wasCancel ? 'warning' : 'error', e.message );
				fullBtn.disabled = btnPull.disabled = btnPreview.disabled = false;
			} ).then( function () {
				running = false;
				fullCancel.hidden = true;
				setTimeout( function () { fullProg.hidden = true; }, 600 );
			} );
		} );
	}

	/* --------------------------------------------------------------- Restore */

	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '.wpsync-restore' );
		if ( ! b ) { return; }
		var when = b.closest( 'tr' ).querySelector( 'td' ).textContent.trim();
		if ( ! window.confirm( 'Restore the backup from ' + when + '?\n\nEverything pulled or edited on this site since then will be undone. (Your current state is backed up first, so you can undo this too.)' ) ) { return; }
		$$( '.wpsync-restore' ).forEach( function ( x ) { x.disabled = true; } );
		b.textContent = 'Restoring…';
		post( 'wpsync_restore', { file: b.getAttribute( 'data-file' ) }, 600000 ).then( function ( d ) {
			window.onbeforeunload = null;
			window.location.href = d.redirect;
		} ).catch( function ( err ) {
			$$( '.wpsync-restore' ).forEach( function ( x ) { x.disabled = false; } );
			b.textContent = 'Restore';
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

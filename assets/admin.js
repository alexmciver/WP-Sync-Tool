/* LivePull admin screen */
( function () {
	'use strict';
	var __ = ( window.wp && window.wp.i18n ) ? window.wp.i18n.__ : function ( text ) { return text; };
	var cfg = window.LIVEPULL || {};
	window.LIVEPULL_LOADED = true;
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
						? __( 'This site stopped responding while pulling (no reply for ', 'livepull' ) + Math.round( timeoutMs / 1000 ) + __( ' seconds). Progress up to the last finished batch is saved. Try again; if it keeps happening, the live site may be slow or blocking requests.', 'livepull' )
						: __( 'Cancelled.', 'livepull' ) );
					err.code = ctrl.signal.reason === 'timeout' ? 'timeout' : 'cancelled';
					throw err;
				}
				throw new Error( __( 'Network error: ', 'livepull' ) + e.message );
			} )
			.then( function ( r ) { clearTimeout( timer ); return r; } )
			.then( function ( r ) {
				return r.text().then( function ( t ) {
					try { return JSON.parse( t ); } catch ( e ) {
						throw new Error( __( 'The server returned an unexpected response (HTTP ', 'livepull' ) + r.status + '). ' + ( r.status >= 500 ? __( 'Check the PHP error log; a pull step may have run out of time or memory.', 'livepull' ) : '' ) );
					}
				} );
			} )
			.then( function ( j ) {
				if ( ! j || ! j.success ) {
					var err = new Error( ( j && j.data && j.data.message ) || __( 'Request failed.', 'livepull' ) );
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

	$$( '.livepull-copy-btn' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var input = b.parentNode.querySelector( 'input' );
			var val = input.value;
			( navigator.clipboard ? navigator.clipboard.writeText( val ) : Promise.reject() ).catch( function () {
				var t = input.type; input.type = 'text'; input.select(); document.execCommand( 'copy' ); input.type = t;
			} );
			b.textContent = __( 'Copied', 'livepull' ); setTimeout( function () { b.textContent = __( 'Copy', 'livepull' ); }, 1500 );
		} );
	} );
	$$( '.livepull-reveal' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var input = b.parentNode.querySelector( 'input' );
			input.type = input.type === 'password' ? 'text' : 'password';
			b.textContent = input.type === 'password' ? __( 'Show', 'livepull' ) : __( 'Hide', 'livepull' );
		} );
	} );

	$$( '.livepull-copy-text' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var ta = b.closest( 'div' ).querySelector( 'textarea' );
			( navigator.clipboard ? navigator.clipboard.writeText( ta.value ) : Promise.reject() ).catch( function () { ta.select(); document.execCommand( 'copy' ); } );
			var was = b.textContent;
			b.textContent = __( 'Copied', 'livepull' ); setTimeout( function () { b.textContent = was; }, 1500 );
		} );
	} );

	if ( ! cfg.configured ) { return; }

	/* ------------------------------------------------------------------ Tabs */

	var tabs = $$( '.livepull-tabs [data-tab]' );
	function showTab( id, push ) {
		tabs.forEach( function ( t ) {
			var on = t.getAttribute( 'data-tab' ) === id;
			t.classList.toggle( 'nav-tab-active', on );
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
		} );
		$$( '.livepull-panel' ).forEach( function ( p ) { p.hidden = p.getAttribute( 'data-panel' ) !== id; } );
		if ( push && window.history && window.history.replaceState ) {
			var u = new URL( window.location.href );
			u.searchParams.set( 'tab', id );
			u.searchParams.delete( 'msg' );
			u.searchParams.delete( 'restored' );
			window.history.replaceState( null, '', u.toString() );
		}
		if ( id === 'conflicts' && ! localLoaded ) { loadLocal(); }
	}
	tabs.forEach( function ( t ) {
		t.addEventListener( 'click', function ( e ) { e.preventDefault(); showTab( t.getAttribute( 'data-tab' ), true ); } );
	} );

	/* ------------------------------------------------------ Connection check */

	function runHealth( box, btn ) {
		box.hidden = false;
		box.innerHTML = '<p><span class="spinner is-active"></span> ' + __( 'Checking each step of the connection…', 'livepull' ) + '</p>';
		if ( btn ) { btn.disabled = true; }
		return post( 'livepull_health', {}, 120000 ).then( function ( d ) {
			box.innerHTML = d.html; // Built and escaped on the server.
			var head = el( 'p', 'livepull-health-head', d.fails ? __( 'Found what\'s wrong. Follow the fix below, then press Check again.', 'livepull' ) : __( 'Everything checks out.', 'livepull' ) );
			box.insertBefore( head, box.firstChild );
		} ).catch( function ( e ) {
			box.textContent = e.message;
		} ).then( function () { if ( btn ) { btn.disabled = false; } } );
	}
	var fixBtn = $( '.livepull-fixit' );
	if ( fixBtn ) {
		fixBtn.addEventListener( 'click', function () {
			runHealth( $( '#livepull-health' ), fixBtn ).then( function () {
				var again = el( 'button', 'button', __( 'Check again', 'livepull' ) );
				again.type = 'button';
				again.addEventListener( 'click', function () { $( '#livepull-health' ).hidden = true; loadStatus(); } );
				$( '#livepull-health' ).appendChild( again );
			} );
		} );
	}
	$$( '.livepull-run-health' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () { runHealth( b.closest( '.livepull-card' ).querySelector( '.livepull-health-here' ), b ); } );
	} );

	/* --------------------------------------------------------- Report a problem */

	var reportBtn = $( '#livepull-report' );
	if ( reportBtn ) {
		reportBtn.addEventListener( 'click', function () {
			reportBtn.disabled = true;
			post( 'livepull_diagnostics', {}, 120000 ).then( function ( d ) {
				var box = $( '#livepull-report-box' );
				box.hidden = false;
				$( 'textarea', box ).value = d.text;
				$( '#livepull-issue' ).href = d.issue_url;
			} ).catch( function ( e ) { window.alert( e.message ); } ).then( function () { reportBtn.disabled = false; } );
		} );
	}

	var statusBox = $( '#livepull-status' );
	var notices = $( '#livepull-notices' );
	var btnPreview = $( '#livepull-preview' );
	var btnPull = $( '#livepull-pull' );
	var progress = $( '#livepull-progress' );
	var result = $( '#livepull-result' );
	var running = false;

	/* ---------------------------------------------------------------- Status */

	function setStatus( cls, text ) {
		statusBox.className = 'livepull-statusline ' + cls;
		$( '.livepull-status-text', statusBox ).textContent = text;
		var paste = $( '.livepull-paste', statusBox );
		if ( paste && cls !== 'is-error' ) { paste.parentNode.removeChild( paste ); }
	}

	function setConflictCount( c ) {
		var b = $( '#livepull-conflict-count' );
		if ( b ) { b.textContent = c; b.hidden = ! c; }
	}

	function loadStatus() {
		setStatus( 'is-loading', __( 'Checking live…', 'livepull' ) );
		if ( fixBtn ) { fixBtn.hidden = true; }
		return post( 'livepull_status' ).then( function ( d ) {
			var parts = [ __( 'Connected to ', 'livepull' ) + d.host ];
			parts.push( d.never ? __( 'never pulled', 'livepull' ) : __( 'last pulled ', 'livepull' ) + d.last_pull );
			parts.push( d.waiting ? n( d.waiting, __( 'change waiting', 'livepull' ), __( 'changes waiting', 'livepull' ) ) : __( 'nothing new on live', 'livepull' ) );
			if ( d.conflicts ) { parts.push( n( d.conflicts, __( 'conflict', 'livepull' ), __( 'conflicts', 'livepull' ) ) ); }
			if ( d.held ) { parts.push( d.held + __( ' held back', 'livepull' ) ); }
			setStatus( d.conflicts ? 'is-warn' : 'is-ok', parts.join( ' · ' ) );
			setConflictCount( d.conflicts );
			notices.innerHTML = '';
			( d.notices || [] ).forEach( function ( m ) { notice( notices, 'info', m ); } );
			var rec = $( '#livepull-recording' );
			if ( rec && d.recording_since && d.never_pulled ) {
				rec.hidden = false;
				rec.textContent = __( 'Live has been noting changes since ', 'livepull' ) + d.recording_since + __( '. If this copy is older than that, tick "Compare all content with live" under More options the first time.', 'livepull' );
			}
			btnPreview.disabled = btnPull.disabled = false;
		} ).catch( function ( e ) {
			setStatus( 'is-error', e.message );
			if ( fixBtn ) { fixBtn.hidden = false; }
			if ( e.code === 'livepull_bad_key' || e.code === 'livepull_no_secret' ) {
				var paste = el( 'button', 'button button-small button-primary livepull-paste', __( 'Paste a new code', 'livepull' ) );
				paste.type = 'button';
				paste.addEventListener( 'click', function () {
					showTab( 'settings', true );
					var conn = $( '#livepull-connection' );
					if ( conn ) { conn.open = true; }
					var code = $( '#livepull-code' ) || $( '#livepull-key' );
					if ( code ) { code.focus(); }
				} );
				var old = $( '.livepull-paste', statusBox );
				if ( old ) { old.parentNode.removeChild( old ); }
				statusBox.appendChild( paste );
			}
			btnPreview.disabled = btnPull.disabled = true;
		} );
	}

	/* ------------------------------------------------------------------ Pull */

	function list( title, items, cls, fmt ) {
		if ( ! items || ! items.length ) { return null; }
		var wrap = el( 'div', 'livepull-list ' + ( cls || '' ) );
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
		var head = el( 'div', 'livepull-summary ' + ( r.errors.length ? 'is-error' : 'is-ok' ) );
		var verb = d.dry ? __( 'Found on live: ', 'livepull' ) : __( 'Done: ', 'livepull' );
		var heldNow = ( d.preview || [] ).filter( function ( i ) { return i.held; } ).length;
		if ( d.dry && ! total && ! r.conflicts.length && ! heldNow ) {
			head.appendChild( el( 'strong', '', __( 'You\'re up to date. ', 'livepull' ) ) );
			head.appendChild( document.createTextNode( __( 'Nothing has changed on live since the last pull.', 'livepull' ) ) );
			result.appendChild( head );
			return;
		}
		head.appendChild( el( 'strong', '', verb + ( total || r.conflicts.length ? '' : __( 'nothing to pull. ', 'livepull' ) ) ) );
		head.appendChild( document.createTextNode(
			r.created.length + __( ' new, ', 'livepull' ) + r.updated.length + __( ' updated, ', 'livepull' ) + r.deleted.length + __( ' deleted, ', 'livepull' ) +
			n( r.conflicts.length, 'conflict', 'conflicts' ) + ( d.dry ? '' : ', ' + n( r.files, __( 'media file', 'livepull' ), __( 'media files', 'livepull' ) ) + __( ' downloaded', 'livepull' ) ) + '.'
		) );
		if ( d.backup ) { head.appendChild( el( 'div', 'description', __( 'Backup saved first: ', 'livepull' ) + d.backup ) ); }
		result.appendChild( head );

		[
			list( d.dry ? __( 'Would create', 'livepull' ) : __( 'Created', 'livepull' ), r.created ),
			list( d.dry ? __( 'Would update', 'livepull' ) : __( 'Updated', 'livepull' ), r.updated ),
			list( d.dry ? __( 'Would delete', 'livepull' ) : __( 'Deleted', 'livepull' ), r.deleted ),
			list( __( 'Conflicts, left as they are here', 'livepull' ), r.conflicts, 'is-warn', function ( c ) { return c.label + ' – ' + c.reason; } ),
			list( __( 'Skipped', 'livepull' ), r.skipped, 'is-warn' ),
			list( __( 'Errors', 'livepull' ), r.errors, 'is-error' )
		].forEach( function ( n ) { if ( n ) { result.appendChild( n ); } } );

		if ( d.dry && d.preview && d.preview.length ) {
			// Replace the plain lists with a pick list.
			Array.prototype.slice.call( result.querySelectorAll( '.livepull-list' ) ).forEach( function ( x ) {
				if ( ! x.classList.contains( 'is-error' ) ) { x.parentNode.removeChild( x ); }
			} );
			result.appendChild( picker( d.preview ) );
		} else if ( d.dry && total ) {
			var p = el( 'p' );
			var b = el( 'button', 'button button-primary', __( 'Pull these changes now', 'livepull' ) );
			b.type = 'button';
			b.addEventListener( 'click', function () { run( false ); } );
			p.appendChild( b );
			result.appendChild( p );
		}
		if ( ! d.dry && r.held && r.held.length ) {
			var hl = list( __( 'Held back for later (pull them from a preview whenever you like)', 'livepull' ), r.held, 'is-warn' );
			if ( hl ) { result.appendChild( hl ); }
		}
	}

	/**
	 * Preview pick list: every change with a tick box, grouped by what would happen, with
	 * one-click filters by kind (Pages, Products, Settings…). Unticked items are held back
	 * and offered again in later previews; items held back earlier start unticked.
	 */
	function picker( items ) {
		var wrap = el( 'div', 'livepull-picker' );
		var actions = [
			[ 'create', __( 'New on live', 'livepull' ) ],
			[ 'update', __( 'Changed on live', 'livepull' ) ],
			[ 'delete', __( 'Deleted on live', 'livepull' ) ],
			[ 'conflict', __( 'Changed here and on live: your version is kept, and you can decide under Conflicts', 'livepull' ) ]
		];
		var groups = {};
		items.forEach( function ( i ) { groups[ i.group ] = ( groups[ i.group ] || 0 ) + 1; } );

		var filters = el( 'p', 'livepull-picker-filters' );
		filters.appendChild( el( 'strong', '', __( 'Include:', 'livepull' ) + ' ' ) );
		Object.keys( groups ).sort().forEach( function ( g ) {
			var lab = el( 'label', 'livepull-chip' );
			var cb = el( 'input' );
			cb.type = 'checkbox';
			cb.checked = true;
			cb.addEventListener( 'change', function () {
				wrap.querySelectorAll( 'input[data-group]' ).forEach( function ( x ) {
					if ( x.getAttribute( 'data-group' ) === g && ! x.disabled ) { x.checked = cb.checked; }
				} );
				count();
			} );
			lab.appendChild( cb );
			lab.appendChild( document.createTextNode( ' ' + g + ' (' + groups[ g ] + ')' ) );
			filters.appendChild( lab );
		} );
		wrap.appendChild( filters );

		function section( title, list, held, fixed ) {
			if ( ! list.length ) { return; }
			var det = el( 'details', 'livepull-list' + ( held || fixed ? ' is-warn' : '' ) );
			det.open = list.length <= 30;
			var sum = el( 'summary' );
			if ( fixed ) {
				sum.textContent = title + ' (' + list.length + ')';
				det.appendChild( sum );
				var ul0 = el( 'ul' );
				list.forEach( function ( i ) { ul0.appendChild( el( 'li', '', i.label ) ); } );
				det.appendChild( ul0 );
				wrap.appendChild( det );
				return;
			}
			var all = el( 'input' );
			all.type = 'checkbox';
			all.checked = ! held;
			all.addEventListener( 'click', function ( e ) { e.stopPropagation(); } );
			all.addEventListener( 'change', function () {
				det.querySelectorAll( 'input[data-key]' ).forEach( function ( x ) { if ( ! x.disabled ) { x.checked = all.checked; } } );
				count();
			} );
			sum.appendChild( all );
			sum.appendChild( document.createTextNode( ' ' + title + ' (' + list.length + ')' ) );
			det.appendChild( sum );
			var ul = el( 'ul' );
			list.forEach( function ( i ) {
				var li = el( 'li' );
				var lab = el( 'label' );
				var cb = el( 'input' );
				cb.type = 'checkbox';
				cb.setAttribute( 'data-key', i.key );
				cb.setAttribute( 'data-group', i.group );
				if ( held ) { cb.setAttribute( 'data-held', '1' ); }
				cb.checked = ! held && i.action !== 'conflict';
				cb.disabled = i.action === 'conflict';
				cb.addEventListener( 'change', count );
				lab.appendChild( cb );
				lab.appendChild( document.createTextNode( ' ' + i.label + ' ' ) );
				lab.appendChild( el( 'span', 'livepull-tag', i.group ) );
				li.appendChild( lab );
				ul.appendChild( li );
			} );
			det.appendChild( ul );
			wrap.appendChild( det );
		}
		actions.forEach( function ( a ) {
			// Conflicts are listed whether or not they were held back earlier, so none go unseen.
			section( a[ 1 ], items.filter( function ( i ) { return i.action === a[ 0 ] && ( ! i.held || a[ 0 ] === 'conflict' ); } ), false, a[ 0 ] === 'conflict' );
		} );
		var held = items.filter( function ( i ) { return i.held && i.action !== 'conflict'; } );
		section( __( 'Held back from earlier pulls (tick to pull them now)', 'livepull' ), held, true );

		var bar = el( 'p', 'livepull-picker-actions' );
		var go = el( 'button', 'button button-primary button-hero' );
		go.type = 'button';
		bar.appendChild( go );
		if ( held.length ) {
			var forget = el( 'button', 'button-link', __( 'Forget the held-back items', 'livepull' ) );
			forget.type = 'button';
			forget.addEventListener( 'click', function () {
				post( 'livepull_forget_held', { keys: JSON.stringify( held.map( function ( i ) { return i.key; } ) ) } ).then( function () {
					forget.disabled = true;
					forget.textContent = __( 'Forgotten. They will only come back if they change on live again.', 'livepull' );
				} );
			} );
			bar.appendChild( document.createTextNode( ' ' ) );
			bar.appendChild( forget );
		}
		bar.appendChild( el( 'span', 'description', ' ' + __( 'Unticked items are held back, not lost: they are offered again next time. Anything a pulled item needs (such as its images) still comes across.', 'livepull' ) ) );
		wrap.appendChild( bar );

		function count() {
			var boxes = Array.prototype.slice.call( wrap.querySelectorAll( 'input[data-key]' ) ).filter( function ( x ) { return ! x.disabled; } );
			var picked = boxes.filter( function ( x ) { return x.checked; } ).length;
			go.textContent = __( 'Pull selected', 'livepull' ) + ' (' + picked + ')';
			go.disabled = picked === 0;
		}
		go.addEventListener( 'click', function () {
			var boxes = Array.prototype.slice.call( wrap.querySelectorAll( 'input[data-key]' ) ).filter( function ( x ) { return ! x.disabled; } );
			var exclude = boxes.filter( function ( x ) { return ! x.checked && ! x.getAttribute( 'data-held' ); } ).map( function ( x ) { return x.getAttribute( 'data-key' ); } );
			var include = boxes.filter( function ( x ) { return x.checked && x.getAttribute( 'data-held' ); } ).map( function ( x ) { return x.getAttribute( 'data-key' ); } );
			run( false, { exclude: JSON.stringify( exclude ), include_held: JSON.stringify( include ) } );
		} );
		count();
		return wrap;
	}

	var lastActivity = '', lastCounts = '', startedAt = 0, ticker = null;
	function paintProgress() {
		var secs = Math.round( ( Date.now() - startedAt ) / 1000 );
		var t = secs < 60 ? secs + 's' : Math.floor( secs / 60 ) + 'm ' + ( secs % 60 ) + 's';
		$( '.livepull-progress-text', progress ).textContent = lastActivity + ( lastCounts ? ' · ' + lastCounts : '' ) + ' · ' + t;
	}

	function setProgress( d ) {
		var bar = $( '.livepull-bar span', progress );
		var text = $( '.livepull-progress-text', progress );
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
			lastActivity = ( d.done ? __( 'Finished', 'livepull' ) : ( d.activity || __( 'Working…', 'livepull' ) ) );
			lastCounts = r.created.length + __( ' new · ', 'livepull' ) + r.updated.length + __( ' updated · ', 'livepull' ) + r.deleted.length + __( ' deleted · ', 'livepull' ) + n( r.conflicts.length, 'conflict', 'conflicts' ) + ( r.files ? ' · ' + n( r.files, 'file', 'files' ) + __( ' downloaded', 'livepull' ) : '' );
			paintProgress();
		}
	}

	function run( dry, extra ) {
		if ( running ) { return; }
		if ( ! dry && $( '#livepull-theirs' ).checked && ! window.confirm( __( 'Live will overwrite your local edits wherever both changed. Continue?', 'livepull' ) ) ) { return; }
		running = true;
		btnPreview.disabled = btnPull.disabled = true;
		result.innerHTML = '';
		notices.innerHTML = '';
		progress.hidden = false;
		$( '.livepull-bar span', progress ).style.width = '2%';
		startedAt = Date.now();
		lastActivity = dry ? __( 'Checking what would change…', 'livepull' ) : __( 'Connecting to live…', 'livepull' );
		lastCounts = '';
		paintProgress();
		ticker = setInterval( paintProgress, 1000 );
		btnCancel.hidden = false;
		cancelled = false;
		window.onbeforeunload = function () { return __( 'A pull is running.', 'livepull' ); };

		var params = {
			step: 'start',
			dry: dry ? 1 : '',
			theirs: $( '#livepull-theirs' ).checked ? 1 : '',
			redownload: $( '#livepull-redownload' ).checked ? 1 : '',
			since: $( '#livepull-since' ).value || '',
			all: $( '#livepull-all' ).checked ? 1 : ''
		};
		Object.keys( extra || {} ).forEach( function ( k ) { params[ k ] = extra[ k ]; } );

		function next( p ) {
			if ( cancelled ) { var e = new Error( __( 'Cancelled.', 'livepull' ) ); e.code = 'cancelled'; return Promise.reject( e ); }
			return post( 'livepull_pull', p, 180000 ).then( function ( d ) {
				( d.notices || [] ).forEach( function ( m ) { notice( notices, 'info', m ); } );
				setProgress( d );
				if ( ! d.done ) { return next( { step: 'next' } ); }
				return d;
			} );
		}

		next( params ).then( function ( d ) {
			renderReport( d );
			if ( ! d.dry ) {
				$( '#livepull-conflicts' ).innerHTML = d.conflicts_html;
				setConflictCount( d.conflict_count );
				if ( d.history_html ) { $( '#livepull-history' ).innerHTML = d.history_html; }
				if ( d.conflict_count ) {
					var go2 = el( 'button', 'button', __( 'Sort out the conflicts', 'livepull' ) );
					go2.type = 'button';
					go2.addEventListener( 'click', function () { showTab( 'conflicts', true ); } );
					var pc = el( 'p' ); pc.appendChild( go2 ); result.appendChild( pc );
				}
				$( '#livepull-since' ).value = '';
				$( '#livepull-all' ).checked = false;
				loadLocal();
			}
		} ).catch( function ( e ) {
			if ( e.code === 'cancelled' ) {
				notice( result, 'warning', __( 'Stopped. Everything up to the last finished batch is kept; press Pull again to continue.', 'livepull' ) );
			} else if ( e.code === 'livepull_locked' ) {
				var n2 = notice( result, 'warning', e.message + __( ' If you know nothing is running (for example the page was closed mid-pull), unlock it: ', 'livepull' ) );
				var ub = el( 'button', 'button', __( 'Unlock and pull again', 'livepull' ) );
				ub.type = 'button';
				ub.addEventListener( 'click', function () {
					ub.disabled = true;
					post( 'livepull_cancel' ).then( function () {
						result.innerHTML = '';
						run( dry, extra ); // Same choices as before (what was ticked or held back).
					} ).catch( function ( err ) {
						ub.disabled = false;
						notice( result, 'info', err.message );
					} );
				} );
				n2.querySelector( 'p' ).appendChild( ub );
			} else {
				notice( result, 'error', e.message );
				if ( e.code === 'timeout' ) { post( 'livepull_cancel' ).catch( function () {} ); }
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
	var btnCancel = $( '#livepull-cancel' );
	btnCancel.addEventListener( 'click', function () {
		cancelled = true;
		btnCancel.hidden = true;
		lastActivity = __( 'Stopping after the current step…', 'livepull' );
		paintProgress();
		if ( controller ) { controller.abort( 'cancel' ); }
		post( 'livepull_cancel' ).catch( function () {} );
	} );

	btnPreview.addEventListener( 'click', function () { run( true ); } );
	btnPull.addEventListener( 'click', function () { run( false ); } );

	/* ------------------------------------------------------------- Conflicts */

	// Side-by-side comparison under the conflict's row.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.livepull-compare-btn' );
		if ( ! btn ) { return; }
		var row = btn.closest( 'tr' );
		var next = row.nextElementSibling;
		if ( next && next.classList.contains( 'livepull-compare-row' ) ) {
			next.parentNode.removeChild( next );
			btn.textContent = __( 'Compare', 'livepull' );
			return;
		}
		btn.disabled = true;
		btn.textContent = __( 'Loading…', 'livepull' );
		post( 'livepull_compare', { key: row.getAttribute( 'data-key' ) } ).then( function ( d ) {
			var tr = el( 'tr', 'livepull-compare-row' );
			var td = el( 'td' );
			td.colSpan = row.children.length;
			td.innerHTML = d.html; // Built and sanitised (wp_kses_post) on the server.
			tr.appendChild( td );
			row.parentNode.insertBefore( tr, row.nextSibling );
			btn.textContent = __( 'Hide comparison', 'livepull' );
		} ).catch( function ( err ) {
			btn.textContent = __( 'Compare', 'livepull' );
			window.alert( err.message );
		} ).then( function () { btn.disabled = false; } );
	} );

	document.addEventListener( 'click', function ( e ) {
		var take = e.target.closest( '.livepull-take' );
		var keep = e.target.closest( '.livepull-keep' );
		if ( ! take && ! keep ) { return; }
		var row = e.target.closest( 'tr' );
		if ( take && ! window.confirm( __( 'Replace your local version of this item with the live version? (A backup is made first.)', 'livepull' ) ) ) { return; }
		$$( 'button', row ).forEach( function ( b ) { b.disabled = true; } );
		var cell = $( '.livepull-row-actions', row );
		post( 'livepull_resolve', { key: row.getAttribute( 'data-key' ), mode: take ? 'take' : 'keep' } ).then( function ( d ) {
			cell.textContent = d.message;
			row.classList.add( 'is-resolved' );
			setConflictCount( d.conflict_count );
			setTimeout( function () { row.parentNode && row.parentNode.removeChild( row ); }, 2500 );
			loadStatus();
			loadLocal();
		} ).catch( function ( err ) {
			$$( 'button', row ).forEach( function ( b ) { b.disabled = false; } );
			window.alert( err.message );
		} );
	} );

	/* -------------------------------------------------- Copy everything from live */

	var fullBtn = $( '#livepull-full-start' );
	if ( fullBtn ) {
		var fullCancel = $( '#livepull-full-cancel' );
		var fullProg = $( '#livepull-full-progress' );
		var fullRes = $( '#livepull-full-result' );
		var fullStop = false;
		var setFull = function ( pct, text ) {
			$( '.livepull-bar span', fullProg ).style.width = Math.max( 2, pct ) + '%';
			$( '.livepull-progress-text', fullProg ).textContent = text;
		};
		fullCancel.addEventListener( 'click', function () {
			fullStop = true;
			fullCancel.hidden = true;
			setFull( 0, __( 'Cancelling…', 'livepull' ) );
			post( 'livepull_full', { step: 'cancel' } ).catch( function () {} ); // Stops the running step on the server.
		} );
		fullBtn.addEventListener( 'click', function () {
			if ( running ) { return; }
			if ( ! window.confirm( __( 'Replace this site\'s WHOLE database with a copy of live?\n\nEverything here (content, settings, users) becomes what\'s on live. Your current database is backed up first and can be restored from the Backups tab.\n\nAfterwards, log in with your live site\'s username and password.', 'livepull' ) ) ) { return; }
			running = true; fullStop = false;
			fullBtn.disabled = btnPull.disabled = btnPreview.disabled = true;
			fullCancel.hidden = false; fullProg.hidden = false; fullRes.innerHTML = '';
			var t0 = Date.now();
			setFull( 2, __( 'Connecting to live…', 'livepull' ) );
			window.onbeforeunload = function () { return __( 'A copy is running.', 'livepull' ); };
			var next = function ( p ) {
				if ( fullStop ) {
					var e = new Error( __( 'Cancelled. Nothing on this site was changed.', 'livepull' ) ); e.code = 'cancelled';
					return Promise.reject( e );
				}
				return post( 'livepull_full', p, 300000 ).then( function ( d ) {
					( d.notices || [] ).forEach( function ( m ) { notice( fullRes, 'info', m ); } );
					var secs = Math.round( ( Date.now() - t0 ) / 1000 );
					setFull( d.pct, d.activity + ' · ' + d.rows.toLocaleString() + __( ' rows', 'livepull' ) + ( d.files_got ? ' · ' + d.files_got + __( ' files downloaded', 'livepull' ) : '' ) + ' · ' + ( secs < 60 ? secs + 's' : Math.floor( secs / 60 ) + 'm ' + ( secs % 60 ) + 's' ) );
					return d.done ? d : next( { step: 'next' } );
				} );
			};
			next( { step: 'start', files: $( '#livepull-full-files' ).checked ? 1 : '' } ).then( function ( d ) {
				window.onbeforeunload = null;
				var n = notice( fullRes, 'success', __( 'Done: this site is now a copy of live (', 'livepull' ) + d.tables + __( ' tables, ', 'livepull' ) + d.rows.toLocaleString() + __( ' rows', 'livepull' ) + ( d.files_got ? ', ' + d.files_got + __( ' files downloaded', 'livepull' ) : '' ) + __( '). From now on, normal pulls bring across only what changes on live. ', 'livepull' ) );
				var a = el( 'a', 'button button-primary', __( 'Log in again (use your live username and password)', 'livepull' ) );
				a.href = d.login;
				n.querySelector( 'p' ).appendChild( a );
				if ( d.errors && d.errors.length ) { fullRes.appendChild( list( __( 'Warnings', 'livepull' ), d.errors, 'is-warn' ) ); }
			} ).catch( function ( e ) {
				window.onbeforeunload = null;
				var wasCancel = e.code === 'cancelled' || e.code === 'livepull_cancelled';
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
		var b = e.target.closest( '.livepull-restore' );
		if ( ! b ) { return; }
		var when = b.closest( 'tr' ).querySelector( 'td' ).textContent.trim();
		if ( ! window.confirm( __( 'Go back to the backup from ', 'livepull' ) + when + __( '?\n\nEverything pulled or edited on this site since then will be undone. (Your current state is backed up first, so you can undo this too.)', 'livepull' ) ) ) { return; }
		$$( '.livepull-restore' ).forEach( function ( x ) { x.disabled = true; } );
		b.textContent = __( 'Restoring…', 'livepull' );
		post( 'livepull_restore', { file: b.getAttribute( 'data-file' ) }, 600000 ).then( function ( d ) {
			window.onbeforeunload = null;
			window.location.href = d.redirect;
		} ).catch( function ( err ) {
			$$( '.livepull-restore' ).forEach( function ( x ) { x.disabled = false; } );
			b.textContent = __( 'Restore', 'livepull' );
			window.alert( err.message );
		} );
	} );

	/* --------------------------------------------------------- Local changes */

	var localLoaded = false;
	function loadLocal() {
		var box = $( '#livepull-local' );
		if ( ! box ) { return; }
		post( 'livepull_local_changes' ).then( function ( d ) { box.innerHTML = d.html; localLoaded = true; } )
			.catch( function ( e ) { box.textContent = e.message; } );
	}
	var startTab = tabs.filter( function ( t ) { return t.classList.contains( 'nav-tab-active' ); } )[ 0 ];
	if ( startTab && startTab.getAttribute( 'data-tab' ) === 'conflicts' ) { loadLocal(); }

	loadStatus();
} )();

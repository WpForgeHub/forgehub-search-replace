( function () {
	'use strict';

	var cfg = window.wpfhBsr;
	if ( ! cfg ) {
		return;
	}
	var $ = function ( id ) {
		return document.getElementById( id );
	};

	var els = {
		search: $( 'wpfh-bsr-search' ),
		replace: $( 'wpfh-bsr-replace' ),
		ci: $( 'wpfh-bsr-ci' ),
		guid: $( 'wpfh-bsr-guid' ),
		dry: $( 'wpfh-bsr-dry' ),
		live: $( 'wpfh-bsr-live' ),
		progress: $( 'wpfh-bsr-progress' ),
		fill: $( 'wpfh-bsr-bar-fill' ),
		status: $( 'wpfh-bsr-status' ),
		results: $( 'wpfh-bsr-results' ),
		summary: $( 'wpfh-bsr-summary' ),
		samples: $( 'wpfh-bsr-samples' ),
		list: $( 'wpfh-bsr-table-list' )
	};
	var busy = false;
	var proofKey = null;
	var tableData = [];

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data ).forEach( function ( k ) {
			var v = data[ k ];
			if ( Array.isArray( v ) ) {
				v.forEach( function ( item ) {
					body.append( k + '[]', item );
				} );
			} else if ( v && typeof v === 'object' ) {
				Object.keys( v ).forEach( function ( t ) {
					v[ t ].forEach( function ( c ) {
						body.append( k + '[' + t + '][]', c );
					} );
				} );
			} else {
				body.append( k, v );
			}
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				if ( ! j.success ) {
					throw new Error( ( j.data && j.data.message ) || cfg.i18n.error );
				}
				return j.data;
			} );
	}

	function selection() {
		var tables = [];
		var columns = {};
		if ( ! cfg.isPro || ! els.list ) {
			return { tables: tables, columns: columns };
		}
		var all = els.list.querySelectorAll( 'input.wpfh-bsr-table' );
		var picked = 0;
		all.forEach( function ( box ) {
			if ( ! box.checked ) {
				return;
			}
			picked++;
			var name = box.value;
			tables.push( name );
			var cols = [];
			els.list.querySelectorAll( 'input.wpfh-bsr-col[data-table="' + name + '"]:checked' ).forEach( function ( c ) {
				cols.push( c.value );
			} );
			columns[ name ] = cols.length ? cols : [ '__none__' ];
		} );
		if ( picked === all.length ) {
			// Everything selected: let the server use its defaults, but keep explicit column limits.
			tables = [];
		}
		return { tables: tables, columns: columns };
	}

	function params() {
		var sel = selection();
		return {
			search: els.search.value,
			replace: els.replace.value,
			ci: els.ci.checked ? 1 : 0,
			guid: els.guid.checked ? 1 : 0,
			tables: sel.tables,
			columns: sel.columns
		};
	}

	function key() {
		return JSON.stringify( params() );
	}

	function setBusy( state ) {
		busy = state;
		els.dry.disabled = state;
		els.live.disabled = state || proofKey !== key();
	}

	function reset() {
		if ( ! busy ) {
			els.live.disabled = proofKey !== key();
		}
	}

	function esc( text ) {
		var d = document.createElement( 'div' );
		d.textContent = text;
		return d.innerHTML;
	}

	function run( dry ) {
		if ( busy ) {
			return;
		}
		if ( ! els.search.value ) {
			window.alert( cfg.i18n.needSearch );
			return;
		}
		if ( ! dry && ! window.confirm( cfg.i18n.confirmLive ) ) {
			return;
		}
		var base = params();
		var totals = { scanned: 0, rows: 0, cells: 0, replacements: 0, skipped: 0 };
		var samples = [];
		var state = { table_index: 0, offset: 0, run_id: '' };
		var startKey = key();

		setBusy( true );
		els.progress.hidden = false;
		els.results.hidden = true;
		els.fill.style.width = '0%';
		els.status.textContent = cfg.i18n.running;

		function step() {
			var data = Object.assign( {}, base, state, { dry: dry ? 1 : 0 } );
			post( 'wpfh_bsr_batch', data )
				.then( function ( r ) {
					totals.scanned += r.scanned;
					totals.rows += r.rows;
					totals.cells += r.cells;
					totals.replacements += r.replacements;
					totals.skipped += r.skipped_rows;
					r.samples.forEach( function ( s ) {
						if ( samples.length < 50 ) {
							samples.push( s );
						}
					} );
					state.table_index = r.next_table;
					state.offset = r.next_offset;
					state.run_id = r.run_id || '';
					var pct = r.total_tables ? Math.min( 100, Math.round( ( r.next_table / r.total_tables ) * 100 ) ) : 100;
					els.fill.style.width = ( r.done ? 100 : pct ) + '%';
					els.status.textContent = cfg.i18n.running + ' ' + ( r.table || '' ) + ' (' + pct + '%)';
					if ( ! r.done ) {
						step();
						return;
					}
					finish( dry, totals, samples, startKey );
				} )
				.catch( function ( e ) {
					els.status.textContent = e.message || cfg.i18n.error;
					setBusy( false );
				} );
		}
		step();
	}

	function finish( dry, totals, samples, startKey ) {
		els.status.textContent = dry ? cfg.i18n.dryDone : cfg.i18n.liveDone;
		els.results.hidden = false;
		els.summary.textContent =
			( dry ? 'DRY RUN: ' : '' ) +
			totals.replacements + ' replacements in ' + totals.cells + ' values / ' + totals.rows + ' rows (' + totals.scanned + ' rows scanned).' +
			( totals.skipped ? ' ' + totals.skipped + ' rows contained unsafe objects/corrupt serialized data and were left untouched.' : '' ) +
			( totals.cells === 0 ? ' ' + cfg.i18n.noMatches : '' );
		var tbody = els.samples.querySelector( 'tbody' );
		tbody.innerHTML = '';
		samples.forEach( function ( s ) {
			var tr = document.createElement( 'tr' );
			tr.innerHTML = '<td>' + esc( s.table ) + '</td><td>' + esc( s.column ) + ' #' + esc( String( s.id ) ) + '</td><td><code>' + esc( s.before ) + '</code></td><td><code>' + esc( s.after ) + '</code></td>';
			tbody.appendChild( tr );
		} );
		els.samples.hidden = samples.length === 0;
		if ( dry ) {
			proofKey = startKey;
		} else {
			proofKey = null;
			if ( cfg.isPro ) {
				window.location.reload();
				return;
			}
		}
		setBusy( false );
	}

	function loadTables() {
		if ( ! cfg.isPro || ! els.list ) {
			return;
		}
		post( 'wpfh_bsr_tables', {} ).then( function ( r ) {
			tableData = r.tables;
			var html = '';
			tableData.forEach( function ( t ) {
				html += '<details class="wpfh-bsr-table-row"><summary><label><input type="checkbox" class="wpfh-bsr-table" value="' + esc( t.name ) + '" checked> ' + esc( t.name ) + '</label> <span class="description">(' + t.columns.length + ' ' + esc( 'text columns' ) + ')</span></summary>';
				t.columns.forEach( function ( c ) {
					html += '<label class="wpfh-bsr-col-label"><input type="checkbox" class="wpfh-bsr-col" data-table="' + esc( t.name ) + '" value="' + esc( c ) + '" checked> ' + esc( c ) + '</label>';
				} );
				html += '</details>';
			} );
			els.list.innerHTML = html;
		} );
	}

	function bulk( on ) {
		els.list.querySelectorAll( 'input[type=checkbox]' ).forEach( function ( b ) {
			b.checked = on;
		} );
		reset();
	}

	function undo( runId, restoring ) {
		var status = $( 'wpfh-bsr-undo-status' );
		status.textContent = restoring ? '…' : '';
		post( 'wpfh_bsr_undo', { run_id: runId } )
			.then( function ( r ) {
				status.textContent = r.restored + ' restored, ' + r.left + ' left';
				if ( r.done ) {
					window.location.reload();
				} else {
					undo( runId, true );
				}
			} )
			.catch( function ( e ) {
				status.textContent = e.message;
			} );
	}

	els.dry.addEventListener( 'click', function () {
		run( true );
	} );
	els.live.addEventListener( 'click', function () {
		run( false );
	} );
	[ els.search, els.replace, els.ci, els.guid ].forEach( function ( el ) {
		el.addEventListener( 'input', reset );
		el.addEventListener( 'change', reset );
	} );
	if ( els.list ) {
		els.list.addEventListener( 'change', reset );
		$( 'wpfh-bsr-all' ).addEventListener( 'click', function () {
			bulk( true );
		} );
		$( 'wpfh-bsr-none' ).addEventListener( 'click', function () {
			bulk( false );
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var tr = e.target.closest ? e.target.closest( 'tr[data-run]' ) : null;
		if ( ! tr ) {
			return;
		}
		if ( e.target.classList.contains( 'wpfh-bsr-undo' ) ) {
			if ( window.confirm( 'Restore the original values of this run?' ) ) {
				undo( tr.getAttribute( 'data-run' ), false );
			}
		} else if ( e.target.classList.contains( 'wpfh-bsr-del-backup' ) ) {
			post( 'wpfh_bsr_delete_backup', { run_id: tr.getAttribute( 'data-run' ) } ).then( function () {
				window.location.reload();
			} );
		}
	} );

	loadTables();
}() );

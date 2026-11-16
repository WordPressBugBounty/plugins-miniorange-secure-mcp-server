/* global MOSMCPDeactivation, wp */
(function () {
	var __       = wp.i18n.__;
	var _n       = wp.i18n._n;
	var sprintf  = wp.i18n.sprintf;

	var deactivateUrl = '';
	var endpoint      = MOSMCPDeactivation.endpoint;
	var nonce         = MOSMCPDeactivation.nonce;
	var pluginSlug    = MOSMCPDeactivation.pluginSlug;
	var firstName     = ( MOSMCPDeactivation.firstName || '' ).trim();
	var adminUrl      = MOSMCPDeactivation.adminUrl || '';

	var DOCS_URL = 'https://plugins.miniorange.com/connect-ai-agents-to-wordpress-using-mcp-guide';

	// Reasons that ask a contextual follow-up question. `prefix` labels the answer
	// in the note to miniOrange support. It stays English because support reads it
	// and can't rely on reading the question in the admin's language; the typed
	// answer is the user's own words and is sent as written.
	var FOLLOWUP = {
		better_alternative: {
			label:       __( 'Which tool or plugin are you switching to?', 'miniorange-secure-mcp-server' ),
			placeholder: __( 'e.g. another MCP plugin', 'miniorange-secure-mcp-server' ),
			prefix:      'Which tool or plugin are you switching to?',
		},
		missing_features: {
			label:       __( 'What features were missing?', 'miniorange-secure-mcp-server' ),
			placeholder: __( 'Tell us what you needed', 'miniorange-secure-mcp-server' ),
			prefix:      'What features were missing?',
		},
	};

	// Empathetic, tailored response shown when a reason is picked. `keep` renders
	// a retention action that cancels the deactivation and takes the user back
	// into the plugin, where they can get help. `links` open in a new tab.
	var RESPONSES = {
		not_working: {
			message: __( 'Sorry that happened — most issues are quick to sort out.', 'miniorange-secure-mcp-server' ),
			test:    __( 'Open troubleshooting before you go', 'miniorange-secure-mcp-server' ),
			keep:    __( 'Keep it & get help', 'miniorange-secure-mcp-server' ),
			links:   [ { label: __( 'Setup guide', 'miniorange-secure-mcp-server' ), href: DOCS_URL } ],
		},
		missing_features: {
			message: __( 'We read every request and triage them weekly — what you note here goes on the list.', 'miniorange-secure-mcp-server' ),
			keep:    __( 'Keep it & talk to us', 'miniorange-secure-mcp-server' ),
		},
		better_alternative: {
			message: __( 'We’d genuinely like to know which — it’s how we get better.', 'miniorange-secure-mcp-server' ),
		},
		temporary: {
			message: __( 'No problem — your settings and connections stay saved for when you’re back.', 'miniorange-secure-mcp-server' ),
		},
		other: {
			message: __( 'We’re listening — tell us more below.', 'miniorange-secure-mcp-server' ),
		},
	};

	function $( id ) {
		return document.getElementById( id );
	}

	function openModal() {
		$( 'mosmcp-df-overlay' ).classList.add( 'mosmcp-df-open' );
		var first = document.querySelector( 'input[name="mosmcp-df-reason"]' );
		if ( first ) {
			first.focus();
		}
	}

	function closeModal() {
		$( 'mosmcp-df-overlay' ).classList.remove( 'mosmcp-df-open' );
	}

	function doDeactivate() {
		if ( deactivateUrl ) {
			window.location.href = deactivateUrl;
		}
	}

	function selectedReason() {
		var checked = document.querySelector( 'input[name="mosmcp-df-reason"]:checked' );
		return checked ? checked.value : '';
	}

	function greet() {
		if ( firstName ) {
			/* translators: %s: the user's first name. */
			$( 'mosmcp-df-title' ).textContent = sprintf( __( 'Before you go, %s…', 'miniorange-secure-mcp-server' ), firstName );
		}
	}

	// Fills `el` from a translation holding one <strong>%s</strong>, as text nodes, so
	// neither the translation nor `value` is ever parsed as HTML. A translation that
	// altered the placeholder would lose `value`, so it falls back to the English.
	function fillWithStrong( el, translated, english, value ) {
		var token = '<strong>%s</strong>';
		var parts = translated.split( token );
		if ( parts.length !== 2 ) {
			parts = english.split( token );
		}
		var strong = document.createElement( 'strong' );
		strong.textContent = value;
		el.textContent = '';
		el.appendChild( document.createTextNode( parts[ 0 ] ) );
		el.appendChild( strong );
		el.appendChild( document.createTextNode( parts[ 1 ] ) );
	}

	function tenurePhrase( days ) {
		if ( days < 14 ) {
			/* translators: %s: number of days. */
			return sprintf( _n( '%s day', '%s days', days, 'miniorange-secure-mcp-server' ), days );
		}
		if ( days < 60 ) {
			var weeks = Math.floor( days / 7 );
			/* translators: %s: number of weeks. */
			return sprintf( _n( '%s week', '%s weeks', weeks, 'miniorange-secure-mcp-server' ), weeks );
		}
		var months = Math.floor( days / 30 );
		/* translators: %s: number of months. */
		return sprintf( _n( '%s month', '%s months', months, 'miniorange-secure-mcp-server' ), months );
	}

	function fillTenure() {
		var days = parseInt( MOSMCPDeactivation.daysActive, 10 ) || 0;
		if ( days < 1 ) {
			return;
		}
		var el = $( 'mosmcp-df-tenure' );
		/* translators: %s: how long the plugin has been active, such as "3 weeks". Keep <strong>%s</strong> exactly as is. */
		fillWithStrong( el, __( 'You’ve had Secure MCP Server active for <strong>%s</strong>.', 'miniorange-secure-mcp-server' ), 'You’ve had Secure MCP Server active for <strong>%s</strong>.', tenurePhrase( days ) );
		el.removeAttribute( 'hidden' );
	}

	function fillConsent() {
		var email = ( MOSMCPDeactivation.email || '' ).trim();
		if ( email ) {
			/* translators: %s: the user's email address. Keep <strong>%s</strong> exactly as is. */
			fillWithStrong( $( 'mosmcp-df-consent-text' ), __( 'It’s okay to follow up with me at <strong>%s</strong> about this.', 'miniorange-secure-mcp-server' ), 'It’s okay to follow up with me at <strong>%s</strong> about this.', email );
		}
	}

	function syncFollowup() {
		var followup = $( 'mosmcp-df-followup' );
		var input    = $( 'mosmcp-df-followup-input' );
		var label    = $( 'mosmcp-df-followup-label' );
		var config   = FOLLOWUP[ selectedReason() ];

		if ( config ) {
			label.textContent = config.label;
			input.placeholder = config.placeholder;
			followup.removeAttribute( 'hidden' );
		} else {
			followup.setAttribute( 'hidden', '' );
			input.value = '';
		}
	}

	function renderResponse() {
		var box    = $( 'mosmcp-df-response' );
		var config = RESPONSES[ selectedReason() ];

		if ( ! config ) {
			box.setAttribute( 'hidden', '' );
			box.innerHTML = '';
			return;
		}

		var hasKeep = config.keep && adminUrl;
		var hasTest = config.test && adminUrl;
		var links   = config.links || [];

		// Built as elements: the text is translated, so it is never parsed as HTML.
		function el( tag, className, text ) {
			var node = document.createElement( tag );
			if ( className ) {
				node.className = className;
			}
			node.textContent = text;
			return node;
		}

		box.textContent = '';
		box.appendChild( el( 'span', '', config.message ) );

		if ( hasTest || hasKeep || links.length ) {
			var actions = el( 'div', 'mosmcp-df-response__actions', '' );
			if ( hasTest ) {
				var test = el( 'button', 'mosmcp-df-test', config.test );
				test.type = 'button';
				actions.appendChild( test );
			}
			if ( hasKeep ) {
				var keep = el( 'button', 'mosmcp-df-keep', config.keep );
				keep.type = 'button';
				actions.appendChild( keep );
			}
			links.forEach( function ( a ) {
				var link = el( 'a', '', a.label );
				link.href   = a.href;
				link.target = '_blank';
				link.rel    = 'noopener noreferrer';
				actions.appendChild( link );
			} );
			box.appendChild( actions );
		}
		box.removeAttribute( 'hidden' );

		if ( hasTest ) {
			box.querySelector( '.mosmcp-df-test' ).addEventListener( 'click', function () {
				// Leave the deactivation and open the plugin's Troubleshooting page. Nothing runs on arrival.
				window.location.href = adminUrl + '#/troubleshooting';
			} );
		}

		if ( hasKeep ) {
			box.querySelector( '.mosmcp-df-keep' ).addEventListener( 'click', function () {
				// Cancel the deactivation, return to the plugin, and signal the
				// app to open the Contact Support modal on arrival.
				var sep = adminUrl.indexOf( '?' ) >= 0 ? '&' : '?';
				window.location.href = adminUrl + sep + 'mosmcp_open=contact';
			} );
		}
	}

	function showThankyou( ok ) {
		var thankyou = $( 'mosmcp-df-thankyou' );
		var title    = thankyou.querySelector( '.mosmcp-df-ty-title' );
		var sub      = thankyou.querySelector( '.mosmcp-df-ty-sub' );

		if ( ok ) {
			/* translators: %s: the user's first name. */
			title.textContent = firstName ? sprintf( __( 'Thank you, %s!', 'miniorange-secure-mcp-server' ), firstName ) : __( 'Thank you!', 'miniorange-secure-mcp-server' );
			sub.textContent   = __( 'A real person reads every note. Deactivating now…', 'miniorange-secure-mcp-server' );
		} else {
			/* translators: %s: the user's first name. */
			title.textContent = firstName ? sprintf( __( 'Thanks, %s.', 'miniorange-secure-mcp-server' ), firstName ) : __( 'Thanks.', 'miniorange-secure-mcp-server' );
			sub.textContent   = __( 'We couldn’t reach our servers, but no worries — deactivating now…', 'miniorange-secure-mcp-server' );
		}

		$( 'mosmcp-df-form' ).style.display = 'none';
		thankyou.classList.add( 'is-visible' );
		setTimeout( doDeactivate, 1800 );
	}

	// Keep keyboard focus inside the modal while it is open.
	function trapFocus( e ) {
		if ( 'Tab' !== e.key ) {
			return;
		}
		var nodes = $( 'mosmcp-df-modal' ).querySelectorAll(
			'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled])'
		);
		var visible = Array.prototype.filter.call( nodes, function ( el ) {
			return null !== el.offsetParent;
		} );
		if ( ! visible.length ) {
			return;
		}

		var first = visible[ 0 ];
		var last  = visible[ visible.length - 1 ];

		if ( e.shiftKey && document.activeElement === first ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && document.activeElement === last ) {
			e.preventDefault();
			first.focus();
		}
	}

	// Comments with the contextual follow-up answer folded in.
	function foldedComments() {
		var comments = $( 'mosmcp-df-comments' ).value;
		var followup = $( 'mosmcp-df-followup' );
		var detail   = $( 'mosmcp-df-followup-input' ).value;

		var config   = FOLLOWUP[ selectedReason() ];

		// English prefix on purpose (see FOLLOWUP), so support can tell which question was asked.
		if ( ! followup.hasAttribute( 'hidden' ) && detail && config ) {
			comments = config.prefix + ' ' + detail + ( comments ? '\n\n' + comments : '' );
		}
		return comments;
	}

	// Fire the feedback request. keepalive lets it complete even if the page
	// navigates away immediately (as it does on skip / deactivate).
	function sendFeedback( reason, comments, contactOk ) {
		return fetch( endpoint, {
			method:    'POST',
			headers:   { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
			body:      JSON.stringify( { reason: reason, comments: comments, contact_ok: contactOk } ),
			keepalive: true,
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var row  = document.querySelector( 'tr[data-plugin="' + pluginSlug + '"]' );
		var link = row ? row.querySelector( '.deactivate a' ) : null;

		if ( ! link ) {
			return;
		}

		greet();
		fillTenure();
		fillConsent();

		link.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			deactivateUrl = this.href;
			openModal();
		} );

		$( 'mosmcp-df-close' ).addEventListener( 'click', closeModal );

		$( 'mosmcp-df-skip' ).addEventListener( 'click', function () {
			// Still capture feedback on skip: use whatever they picked, or a
			// "skipped" marker when nothing was selected. keepalive ensures the
			// request survives the immediate navigation.
			sendFeedback(
				selectedReason() || 'skipped',
				foldedComments(),
				$( 'mosmcp-df-consent-input' ).checked
			);
			doDeactivate();
		} );

		Array.prototype.forEach.call(
			document.querySelectorAll( 'input[name="mosmcp-df-reason"]' ),
			function ( radio ) {
				radio.addEventListener( 'change', function () {
					syncFollowup();
					renderResponse();
				} );
			}
		);

		$( 'mosmcp-df-submit' ).addEventListener( 'click', function () {
			var reason = selectedReason();
			if ( ! reason ) {
				return;
			}

			var btn = this;
			btn.disabled = true;
			btn.classList.add( 'is-loading' );

			sendFeedback( reason, foldedComments(), $( 'mosmcp-df-consent-input' ).checked )
				.then( function ( res ) {
					showThankyou( !! ( res && res.ok ) );
				} ).catch( function () {
					showThankyou( false );
				} );
		} );

		$( 'mosmcp-df-overlay' ).addEventListener( 'click', function ( e ) {
			if ( e.target === this ) {
				closeModal();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( ! $( 'mosmcp-df-overlay' ).classList.contains( 'mosmcp-df-open' ) ) {
				return;
			}
			if ( 'Escape' === e.key ) {
				closeModal();
				return;
			}
			trapFocus( e );
		} );
	} );
}() );

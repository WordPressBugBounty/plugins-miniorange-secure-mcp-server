/* global MOSMCPDeactivation */
(function () {
	var deactivateUrl = '';
	var endpoint      = MOSMCPDeactivation.endpoint;
	var nonce         = MOSMCPDeactivation.nonce;
	var pluginSlug    = MOSMCPDeactivation.pluginSlug;
	var firstName     = ( MOSMCPDeactivation.firstName || '' ).trim();
	var adminUrl      = MOSMCPDeactivation.adminUrl || '';

	var DOCS_URL = 'https://plugins.miniorange.com/connect-ai-agents-to-wordpress-using-mcp-guide';

	// Reasons that ask a contextual follow-up question.
	var FOLLOWUP = {
		better_alternative: {
			label:       'Which tool or plugin are you switching to?',
			placeholder: 'e.g. another MCP plugin',
		},
		missing_features: {
			label:       'What features were missing?',
			placeholder: 'Tell us what you needed',
		},
	};

	// Empathetic, tailored response shown when a reason is picked. `keep` renders
	// a retention action that cancels the deactivation and takes the user back
	// into the plugin, where they can get help. `links` open in a new tab.
	var RESPONSES = {
		not_working: {
			message: 'Sorry that happened — most issues are quick to sort out.',
			keep:    'Keep it & get help',
			links:   [ { label: 'Setup guide', href: DOCS_URL } ],
		},
		missing_features: {
			message: 'We read every request and triage them weekly — what you note here goes on the list.',
			keep:    'Keep it & talk to us',
		},
		better_alternative: {
			message: 'We’d genuinely like to know which — it’s how we get better.',
		},
		temporary: {
			message: 'No problem — your settings and connections stay saved for when you’re back.',
		},
		other: {
			message: 'We’re listening — tell us more below.',
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
			$( 'mosmcp-df-title' ).textContent = 'Before you go, ' + firstName + '…';
		}
	}

	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function tenurePhrase( days ) {
		if ( days < 14 ) {
			return days + ( 1 === days ? ' day' : ' days' );
		}
		if ( days < 60 ) {
			var weeks = Math.floor( days / 7 );
			return weeks + ( 1 === weeks ? ' week' : ' weeks' );
		}
		var months = Math.floor( days / 30 );
		return months + ( 1 === months ? ' month' : ' months' );
	}

	function fillTenure() {
		var days = parseInt( MOSMCPDeactivation.daysActive, 10 ) || 0;
		if ( days < 1 ) {
			return;
		}
		var el = $( 'mosmcp-df-tenure' );
		el.innerHTML = 'You’ve had Secure MCP Server active for <strong>' + tenurePhrase( days ) + '</strong>.';
		el.removeAttribute( 'hidden' );
	}

	function fillConsent() {
		var email = ( MOSMCPDeactivation.email || '' ).trim();
		if ( email ) {
			$( 'mosmcp-df-consent-text' ).innerHTML =
				'It’s okay to follow up with me at <strong>' + escapeHtml( email ) + '</strong> about this.';
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

		// Content is static and developer-controlled.
		var html    = '<span>' + config.message + '</span>';
		var hasKeep = config.keep && adminUrl;
		var links   = config.links || [];

		if ( hasKeep || links.length ) {
			html += '<div class="mosmcp-df-response__actions">';
			if ( hasKeep ) {
				html += '<button type="button" class="mosmcp-df-keep">' + config.keep + '</button>';
			}
			links.forEach( function ( a ) {
				html += '<a href="' + a.href + '" target="_blank" rel="noopener noreferrer">' + a.label + '</a>';
			} );
			html += '</div>';
		}
		box.innerHTML = html;
		box.removeAttribute( 'hidden' );

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
			title.textContent = firstName ? ( 'Thank you, ' + firstName + '!' ) : 'Thank you!';
			sub.textContent   = 'A real person reads every note. Deactivating now…';
		} else {
			title.textContent = firstName ? ( 'Thanks, ' + firstName + '.' ) : 'Thanks.';
			sub.textContent   = 'We couldn’t reach our servers, but no worries — deactivating now…';
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

		if ( ! followup.hasAttribute( 'hidden' ) && detail ) {
			var prefix = $( 'mosmcp-df-followup-label' ).textContent;
			comments   = prefix + ' ' + detail + ( comments ? '\n\n' + comments : '' );
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

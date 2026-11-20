<?php
/**
 * Deactivation feedback modal markup, included by Deactivation_Feedback::maybe_render().
 *
 * @package Miniorange_Secure_MCP_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="mosmcp-df-overlay" role="dialog" aria-modal="true" aria-labelledby="mosmcp-df-title">
	<div id="mosmcp-df-modal">
		<button type="button" id="mosmcp-df-close" aria-label="<?php esc_attr_e( 'Close', 'miniorange-secure-mcp-server' ); ?>">
			<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
		</button>

		<div id="mosmcp-df-form">
			<div class="mosmcp-df-head">
				<span class="mosmcp-df-head-icon">
					<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18v-6a9 9 0 0118 0v6"/><path d="M21 19a2 2 0 01-2 2h-1a2 2 0 01-2-2v-3a2 2 0 012-2h3z"/><path d="M3 19a2 2 0 002 2h1a2 2 0 002-2v-3a2 2 0 00-2-2H3z"/></svg>
				</span>
				<div class="mosmcp-df-head-text">
					<h3 id="mosmcp-df-title"><?php esc_html_e( 'Before you go…', 'miniorange-secure-mcp-server' ); ?></h3>
					<p class="mosmcp-df-sub"><?php esc_html_e( 'A quick note on why helps us do better — and we read every one.', 'miniorange-secure-mcp-server' ); ?></p>
					<p class="mosmcp-df-tenure" id="mosmcp-df-tenure" hidden></p>
				</div>
			</div>

			<div class="mosmcp-df-field">
				<span class="mosmcp-df-label"><?php esc_html_e( 'Why are you deactivating?', 'miniorange-secure-mcp-server' ); ?> <span class="mosmcp-df-req" aria-hidden="true">*</span></span>
				<div class="mosmcp-df-options" role="radiogroup" aria-label="<?php esc_attr_e( 'Reason for deactivating', 'miniorange-secure-mcp-server' ); ?>">
					<label class="mosmcp-df-option">
						<input type="radio" name="mosmcp-df-reason" value="not_working">
						<span class="mosmcp-df-option-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
						</span>
						<span class="mosmcp-df-option-label"><?php esc_html_e( 'Not working as expected', 'miniorange-secure-mcp-server' ); ?></span>
						<span class="mosmcp-df-option-check" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
						</span>
					</label>

					<label class="mosmcp-df-option">
						<input type="radio" name="mosmcp-df-reason" value="missing_features">
						<span class="mosmcp-df-option-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
						</span>
						<span class="mosmcp-df-option-label"><?php esc_html_e( 'Missing features I need', 'miniorange-secure-mcp-server' ); ?></span>
						<span class="mosmcp-df-option-check" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
						</span>
					</label>

					<label class="mosmcp-df-option">
						<input type="radio" name="mosmcp-df-reason" value="better_alternative">
						<span class="mosmcp-df-option-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
						</span>
						<span class="mosmcp-df-option-label"><?php esc_html_e( 'Found a better alternative', 'miniorange-secure-mcp-server' ); ?></span>
						<span class="mosmcp-df-option-check" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
						</span>
					</label>

					<label class="mosmcp-df-option">
						<input type="radio" name="mosmcp-df-reason" value="temporary">
						<span class="mosmcp-df-option-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a10 10 0 100-20 10 10 0 000 20z"/><path d="M12 6v6l4 2"/></svg>
						</span>
						<span class="mosmcp-df-option-label"><?php esc_html_e( 'Temporary deactivation', 'miniorange-secure-mcp-server' ); ?></span>
						<span class="mosmcp-df-option-check" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
						</span>
					</label>

					<label class="mosmcp-df-option">
						<input type="radio" name="mosmcp-df-reason" value="other">
						<span class="mosmcp-df-option-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 13a1 1 0 100-2 1 1 0 000 2z"/><path d="M19 13a1 1 0 100-2 1 1 0 000 2z"/><path d="M5 13a1 1 0 100-2 1 1 0 000 2z"/></svg>
						</span>
						<span class="mosmcp-df-option-label"><?php echo esc_html_x( 'Other', 'deactivation reason', 'miniorange-secure-mcp-server' ); ?></span>
						<span class="mosmcp-df-option-check" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
						</span>
					</label>
				</div>
			</div>

			<div id="mosmcp-df-response" class="mosmcp-df-response" role="status" aria-live="polite" hidden></div>

			<div class="mosmcp-df-field" id="mosmcp-df-followup" hidden>
				<label class="mosmcp-df-label" for="mosmcp-df-followup-input" id="mosmcp-df-followup-label"></label>
				<input type="text" id="mosmcp-df-followup-input" autocomplete="off">
			</div>

			<div class="mosmcp-df-field">
				<label class="mosmcp-df-label" for="mosmcp-df-comments"><?php esc_html_e( 'Anything else?', 'miniorange-secure-mcp-server' ); ?> <span class="mosmcp-df-optional"><?php esc_html_e( '(optional)', 'miniorange-secure-mcp-server' ); ?></span></label>
				<textarea id="mosmcp-df-comments" placeholder="<?php esc_attr_e( 'Tell us what we could have done better…', 'miniorange-secure-mcp-server' ); ?>"></textarea>
			</div>

			<div class="mosmcp-df-support">
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a10 10 0 100-20 10 10 0 000 20z"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
				<span>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: URL of miniOrange support. */
							__( 'Running into trouble? <a href="%s" target="_blank" rel="noopener noreferrer">Talk to support</a> — we can usually help.', 'miniorange-secure-mcp-server' ),
							esc_url( 'https://plugins.miniorange.com/' )
						),
						array(
							'a' => array(
								'href'   => true,
								'target' => true,
								'rel'    => true,
							),
						)
					);
					?>
				</span>
			</div>

			<label class="mosmcp-df-consent">
				<input type="checkbox" id="mosmcp-df-consent-input" checked>
				<span class="mosmcp-df-consent-box" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
				</span>
				<span class="mosmcp-df-consent-text" id="mosmcp-df-consent-text"><?php esc_html_e( 'It’s okay to follow up with me about this.', 'miniorange-secure-mcp-server' ); ?></span>
			</label>

			<div class="mosmcp-df-actions">
				<button type="button" id="mosmcp-df-skip"><?php esc_html_e( 'Skip & deactivate', 'miniorange-secure-mcp-server' ); ?></button>
				<button type="button" id="mosmcp-df-submit">
					<span class="mosmcp-df-submit-spinner" aria-hidden="true"></span>
					<span class="mosmcp-df-submit-label"><?php esc_html_e( 'Submit & deactivate', 'miniorange-secure-mcp-server' ); ?></span>
				</button>
			</div>
		</div>

		<div id="mosmcp-df-thankyou">
			<span class="mosmcp-df-ty-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
			</span>
			<p class="mosmcp-df-ty-title"><?php esc_html_e( 'Thanks for the feedback!', 'miniorange-secure-mcp-server' ); ?></p>
			<p class="mosmcp-df-ty-sub"><?php esc_html_e( 'Deactivating the plugin…', 'miniorange-secure-mcp-server' ); ?></p>
		</div>
	</div>
</div>

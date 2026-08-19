<?php
/**
 * dbaronald.nl — "Submit an execution plan" intake form.
 *
 * Private page for known consulting clients (not linked anywhere on the
 * site — share the direct URL only). Unlike stats-parser-handler.php, this
 * does NOT analyze anything — it just emails the uploaded .sqlplan file to
 * Ronald, landing in the existing Power Automate → OneDrive →
 * SQLPerformanceViewer pipeline exactly like a direct email attachment
 * would (the Power Automate flow triggers on "new email with attachment"
 * regardless of how that email was sent). Analysis and the reply with a
 * results link stay fully manual — see the "execution-plan-intake-pipeline"
 * memory / CLAUDE.md for the rest of that workflow.
 *
 * No Turnstile here (unlike Statistics Parser) — the destination address is
 * hardcoded to Ronald's own inbox, so there's no email-relay-to-a-stranger
 * abuse vector, just the ordinary "someone spams the form" risk, which the
 * nonce + rate limit + file-type validation below cover.
 *
 * Install via the WPCode (Code Snippets) plugin in wp-admin:
 *   Code Snippets → + Add Snippet → "Add Your Custom Code" → PHP Snippet
 *   Paste everything below this comment (without the opening <?php line),
 *   set Location: Run Everywhere, then Activate.
 * Then create a WP Page whose content is just the shortcode:
 *   [dbaronald_plan_submit]
 * Do NOT add it to any menu — share the page URL directly with clients.
 */

// ---------------------------------------------------------------------
// Shortcode: renders the form with a fresh nonce + admin-ajax URL.
// ---------------------------------------------------------------------

add_shortcode( 'dbaronald_plan_submit', function () {
	$nonce = wp_create_nonce( 'dbaronald_plan_submit' );
	$ajax  = admin_url( 'admin-ajax.php' );

	static $css_printed = false;
	if ( ! $css_printed ) {
		$css_printed = true;
		?>
		<style>
			.plan-submit { max-width:560px; margin:0 auto; }
			.plan-submit .ps-panel { background:#121927; border:1px solid #223047; border-radius:10px; padding:28px; }
			.plan-submit .ps-panel h1 { margin-top:0; font-size:1.3rem; color:#dfe7f1; }
			.plan-submit .ps-panel p.ps-intro { color:#93a1b5; font-size:.92rem; }
			.plan-submit .ps-field { margin-top:16px; }
			.plan-submit .ps-field label { display:block; margin-bottom:6px; font-size:.88rem; color:#dfe7f1; }
			.plan-submit .ps-field input[type="email"],
			.plan-submit .ps-field textarea { width:100%; box-sizing:border-box; background:#0e1420; color:#dfe7f1; border:1px solid #223047; border-radius:8px; padding:10px 12px; font-family:inherit; font-size:.92rem; }
			.plan-submit .ps-field textarea { min-height:90px; resize:vertical; }
			.plan-submit .ps-field input[type="file"] { color:#dfe7f1; font-size:.88rem; }
			.plan-submit .ps-submit { margin-top:20px; background:#38bdf8; color:#04121d; border:none; border-radius:8px; padding:10px 20px; font-size:.95rem; cursor:pointer; }
			.plan-submit .ps-submit:hover { background:#22d3ee; }
			.plan-submit .ps-submit:disabled { opacity:.6; cursor:default; }
			.plan-submit .ps-status { margin-top:14px; font-size:.88rem; color:#93a1b5; }
			.plan-submit .ps-status.ps-ok { color:#4ade80; }
			.plan-submit .ps-status.ps-err { color:#fbbf24; }
			.plan-submit .ps-privacy { margin-top:18px; padding-top:14px; border-top:1px solid #223047; font-size:.82rem; color:#93a1b5; }
		</style>
		<?php
	}

	wp_enqueue_script(
		'dbaronald-plan-submit',
		'https://dbaronald.com/assets/js/plan-submit.js',
		array(),
		null,
		true
	);

	ob_start();
	?>
	<div class="plan-submit" data-ajax-url="<?php echo esc_url( $ajax ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
		<div class="ps-panel">
			<h1>Stuur je executieplan</h1>
			<p class="ps-intro">Upload een .sqlplan-bestand — ik bekijk het persoonlijk en stuur je een link naar de analyse terug.</p>

			<form class="ps-form">
				<div class="ps-field">
					<label for="ps-file">Executieplan (.sqlplan)</label>
					<input type="file" id="ps-file" class="ps-file-input" accept=".sqlplan" required>
				</div>
				<div class="ps-field">
					<label for="ps-email">Jouw e-mailadres</label>
					<input type="email" id="ps-email" class="ps-email-input" placeholder="jouw@bedrijf.nl" required>
				</div>
				<div class="ps-field">
					<label for="ps-note">Toelichting (optioneel)</label>
					<textarea id="ps-note" class="ps-note-input" placeholder="Wat loopt er mis, wat verwacht je, iets anders wat handig is om te weten..."></textarea>
				</div>
				<button type="submit" class="ps-submit">Versturen</button>
				<div class="ps-status"></div>
			</form>

			<div class="ps-privacy">Dit formulier mailt je bestand rechtstreeks naar mij door — het wordt niet gepubliceerd of gedeeld met derden.</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
} );

// ---------------------------------------------------------------------
// AJAX endpoint: validate the upload and email it to Ronald.
// ---------------------------------------------------------------------

add_action( 'wp_ajax_dbaronald_plan_submit_email', 'dbaronald_plan_submit_email' );
add_action( 'wp_ajax_nopriv_dbaronald_plan_submit_email', 'dbaronald_plan_submit_email' );

function dbaronald_plan_submit_email() {
	check_ajax_referer( 'dbaronald_plan_submit', 'nonce' );

	$client_email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$note         = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

	if ( ! is_email( $client_email ) ) {
		wp_send_json( array( 'success' => false, 'error' => 'invalid_email' ) );
	}

	if ( empty( $_FILES['plan_file'] ) || $_FILES['plan_file']['error'] !== UPLOAD_ERR_OK ) {
		wp_send_json( array( 'success' => false, 'error' => 'invalid_file' ) );
	}

	$file = $_FILES['plan_file'];

	// ~8 MB is generous for even a large, warning-heavy showplan XML.
	if ( $file['size'] <= 0 || $file['size'] > 8 * 1024 * 1024 ) {
		wp_send_json( array( 'success' => false, 'error' => 'invalid_file' ) );
	}

	if ( strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== 'sqlplan' ) {
		wp_send_json( array( 'success' => false, 'error' => 'invalid_file' ) );
	}

	// Confirm it's actually showplan XML, not just a renamed random file.
	libxml_use_internal_errors( true );
	$doc = new DOMDocument();
	$loaded = $doc->load( $file['tmp_name'] );
	libxml_clear_errors();
	if ( ! $loaded || ! $doc->documentElement || $doc->documentElement->namespaceURI !== 'http://schemas.microsoft.com/sqlserver/2004/07/showplan' ) {
		wp_send_json( array( 'success' => false, 'error' => 'invalid_file' ) );
	}

	// Rate limit: max 10 submissions/hour per IP — generous since this is a
	// private, unlinked form for known clients, just a sanity backstop.
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
	$key = 'dbps_rl_' . md5( $ip );
	$count = (int) get_transient( $key );
	if ( $count >= 10 ) {
		wp_send_json( array( 'success' => false, 'error' => 'rate_limited' ) );
	}
	set_transient( $key, $count + 1, HOUR_IN_SECONDS );

	$safe_name = sanitize_file_name( $file['name'] );
	$subject   = 'Execution plan submission — ' . $client_email;
	$body      = "New execution plan submitted via dbaronald.nl.\n\n"
		. "From: " . $client_email . "\n"
		. "File: " . $safe_name . "\n\n"
		. "Note:\n" . ( $note !== '' ? $note : '(none)' );

	// Reply-To the client's own address so replying in Outlook goes straight
	// back to them, even though the email itself is sent by WordPress.
	$headers = array( 'Reply-To: ' . $client_email );

	// wp_mail's $attachments takes filesystem paths; $_FILES tmp_name is
	// still on disk at this point in the request.
	$sent = wp_mail( 'ronald.de.groot@opendata.nl', $subject, $body, $headers, array( $file['tmp_name'] ) );

	wp_send_json( array( 'success' => (bool) $sent ) );
}

<?php
/**
 * dbaronald.nl — Statistics Parser: WordPress page + "email me the report" handler.
 *
 * Renders the Statistics Parser tool via a shortcode (needed because the
 * page must embed a fresh WP nonce on every view — a static Custom HTML
 * block can't do that, and a hardcoded nonce would go stale within ~24h)
 * and implements the optional "email me the full report" AJAX endpoint.
 *
 * The client-side parsing/suggestions engine itself is NOT pasted here —
 * it lives at assets/js/stats-parser.js in the dbaronald.com repo and
 * deploys automatically via git push. This snippet loads it from there
 * with a plain <script src>, and re-implements the same rules in PHP
 * below for the emailed version (never trust the client's numbers for
 * something that gets emailed out).
 *
 * IMPORTANT: keep the rule set below in sync with assets/js/stats-parser.js
 * (dbaronald_stats_parser_build_findings mirrors buildFindings()) — see
 * CLAUDE.md "Adding the Statistics Parser tool".
 *
 * Requires Cloudflare Turnstile (free bot-check, gates the "email me the
 * report" AJAX endpoint — the WP nonce alone doesn't stop a scripted
 * attacker). Set up before activating this snippet:
 *   1. dash.cloudflare.com → Turnstile → Add site → domain dbaronald.nl,
 *      widget mode "Managed". Copy the Site Key and Secret Key.
 *   2. In wp-config.php (NOT in this repo — it holds a secret), above the
 *      "That's all, stop editing!" line, add:
 *        define( 'DBARONALD_TURNSTILE_SITE_KEY', 'your-site-key' );
 *        define( 'DBARONALD_TURNSTILE_SECRET_KEY', 'your-secret-key' );
 *      Without these, the widget renders with an empty sitekey and every
 *      "email me the report" submission is rejected server-side.
 *
 * Install via the WPCode (Code Snippets) plugin in wp-admin:
 *   Plugins → Add New → "WPCode" → install & activate
 *   Code Snippets → + Add Snippet → "Add Your Custom Code" → PHP Snippet
 *   Paste everything below this comment (without the opening <?php line),
 *   set Location: Run Everywhere, then Activate.
 * Then create a WP Page (e.g. slug "statistics-parser") whose content is
 * just the shortcode: [dbaronald_stats_parser]
 * Add that page to the site menu via Appearance → Menus.
 */

// ---------------------------------------------------------------------
// Shortcode: renders the tool with a fresh nonce + admin-ajax URL.
// ---------------------------------------------------------------------

add_shortcode( 'dbaronald_stats_parser', function ( $atts ) {
	$atts  = shortcode_atts( array( 'lang' => 'nl' ), $atts );
	$lang  = $atts['lang'] === 'en' ? 'en' : 'nl';
	$nonce = wp_create_nonce( 'dbaronald_stats_parser' );
	$ajax  = admin_url( 'admin-ajax.php' );

	wp_enqueue_script(
		'dbaronald-stats-parser',
		'https://dbaronald.com/assets/js/stats-parser.js',
		array(),
		null,
		true
	);
	wp_enqueue_script(
		'cf-turnstile',
		'https://challenges.cloudflare.com/turnstile/v0/api.js',
		array(),
		null,
		true
	);
	$turnstile_site_key = defined( 'DBARONALD_TURNSTILE_SITE_KEY' ) ? DBARONALD_TURNSTILE_SITE_KEY : '';

	// Print the scoped CSS only once, even if the shortcode is used twice
	// on the same page. This is NOT the theme's dark skin (blocksy-dark.css
	// handles that separately) — it's layout/component CSS for the tool
	// itself (panel grid, table, pills, buttons) that has no other home,
	// since the WordPress page has no build step to pull in a stylesheet
	// from this repo. Keep in sync with the <style> block in
	// stats-parser-page.html (the local test harness carries its own copy
	// because it also sets `body` background, which this scoped version
	// deliberately doesn't — that's the live theme's job).
	static $css_printed = false;
	if ( ! $css_printed ) {
		$css_printed = true;
		?>
		<style>
			.stats-parser { max-width:1000px; margin:0 auto; display:grid; grid-template-columns:280px 1fr; gap:28px; }
			@media (max-width: 720px) { .stats-parser { grid-template-columns:1fr; } }
			.stats-parser [hidden] { display:none !important; }
			.stats-parser .sp-panel { background:#121927; border:1px solid #223047; border-radius:10px; padding:20px; align-self:start; }
			.stats-parser .sp-panel h2 { margin-top:0; font-size:1.05rem; color:#dfe7f1; }
			.stats-parser .sp-panel code { font-family:"Cascadia Code",Consolas,"SF Mono",Menlo,monospace; color:#22d3ee; background:#0e1420; border-radius:4px; padding:.1em .35em; }
			.stats-parser .sp-panel pre { background:#0e1420; border:1px solid #223047; border-radius:8px; padding:12px; overflow-x:auto; font-size:.85rem; }
			.stats-parser .sp-privacy { margin-top:16px; padding-top:14px; border-top:1px solid #223047; font-size:.85rem; color:#93a1b5; }
			.stats-parser .sp-lang-switch { margin-bottom:16px; font-family:monospace; font-size:.85rem; }
			.stats-parser .sp-lang-switch a { color:#93a1b5; text-decoration:none; padding:2px 6px; }
			.stats-parser .sp-lang-switch a.active { color:#38bdf8; font-weight:bold; }
			.stats-parser .sp-main { display:flex; flex-direction:column; gap:14px; }
			.stats-parser .sp-input { width:100%; min-height:220px; box-sizing:border-box; background:#121927; color:#dfe7f1; border:1px solid #223047; border-radius:8px; padding:12px; font-family:"Cascadia Code",Consolas,"SF Mono",Menlo,monospace; font-size:.85rem; resize:vertical; }
			.stats-parser .sp-input:focus { border-color:#38bdf8; outline:none; }
			.stats-parser .sp-actions { display:flex; gap:10px; flex-wrap:wrap; }
			.stats-parser .sp-actions button { background:#38bdf8; color:#04121d; border:none; border-radius:8px; padding:9px 16px; font-size:.9rem; cursor:pointer; }
			.stats-parser .sp-actions button:hover { background:#22d3ee; }
			.stats-parser .sp-actions button.sp-secondary { background:transparent; color:#dfe7f1; border:1px solid #223047; }
			.stats-parser .sp-actions button.sp-secondary:hover { border-color:#38bdf8; color:#38bdf8; }
			.stats-parser .sp-results { min-height:20px; }
			.stats-parser .sp-empty { color:#93a1b5; font-style:italic; }
			.stats-parser .sp-table-wrap { overflow-x:auto; }
			.stats-parser .sp-table { width:100%; border-collapse:collapse; font-size:.88rem; }
			.stats-parser .sp-table th, .stats-parser .sp-table td { border:1px solid #223047; padding:7px 10px; text-align:right; white-space:nowrap; }
			.stats-parser .sp-table th:first-child, .stats-parser .sp-table td:first-child { text-align:left; }
			.stats-parser .sp-table th { background:#121927; color:#dfe7f1; }
			.stats-parser .sp-flag-row td { color:#fbbf24; }
			.stats-parser .sp-total-row td { font-weight:bold; background:#0e1420; }
			.stats-parser .sp-time { color:#93a1b5; font-size:.9rem; }
			.stats-parser .sp-findings-title { font-size:.95rem; margin-bottom:8px; }
			.stats-parser .sp-findings { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:8px; }
			.stats-parser .pill { display:inline-block; font-family:monospace; font-size:.82rem; padding:6px 12px; border-radius:8px; border:1px solid #223047; background:#121927; color:#93a1b5; }
			.stats-parser .pill-accent { color:#38bdf8; border-color:rgba(56,189,248,.4); background:rgba(56,189,248,.08); }
			.stats-parser .pill-amber { color:#fbbf24; border-color:rgba(251,191,36,.4); background:rgba(251,191,36,.08); }
			.stats-parser .sp-email-form { display:flex; gap:10px; flex-wrap:wrap; align-items:center; padding-top:10px; border-top:1px solid #223047; }
			.stats-parser .sp-email-form input[type="email"] { background:#121927; color:#dfe7f1; border:1px solid #223047; border-radius:8px; padding:8px 12px; min-width:220px; }
			.stats-parser .sp-hidden { display:none; }
			.stats-parser .sp-email-status { font-size:.85rem; color:#93a1b5; }
		</style>
		<?php
	}
	?>
	<div class="stats-parser" data-lang="<?php echo esc_attr( $lang ); ?>"
	     data-ajax-url="<?php echo esc_url( $ajax ); ?>"
	     data-nonce="<?php echo esc_attr( $nonce ); ?>">

		<aside class="sp-panel">
			<div class="sp-lang-switch">
				<a href="#" data-set-lang="nl">NL</a> | <a href="#" data-set-lang="en">EN</a>
			</div>

			<div data-lang-text="nl">
				<h2>Statistics Parser</h2>
				<p>Zet dit aan vóórdat je je query draait:</p>
				<pre>SET STATISTICS IO ON;
SET STATISTICS TIME ON;</pre>
				<p>Plak daarna de "Messages"-output hiernaast. De tool formatteert de cijfers per tabel en wijst je op opvallende signalen — physical reads, worktables, scheve CPU/verstreken-tijd, etc.</p>
				<div class="sp-privacy">100% client-side — wat je hier plakt verlaat nooit je browser, tenzij je zelf op "e-mail me het rapport" klikt.</div>
			</div>

			<div data-lang-text="en">
				<h2>Statistics Parser</h2>
				<p>Turn this on before running your query:</p>
				<pre>SET STATISTICS IO ON;
SET STATISTICS TIME ON;</pre>
				<p>Then paste the "Messages" output on the right. The tool formats the numbers per table and points out anything worth a second look — physical reads, worktables, skewed CPU/elapsed time, etc.</p>
				<div class="sp-privacy">100% client-side — anything you paste here never leaves your browser, unless you click "email me the report" yourself.</div>
			</div>
		</aside>

		<div class="sp-main">
			<textarea class="sp-input" placeholder="Plak hier je STATISTICS IO/TIME-output..."></textarea>

			<div class="sp-actions">
				<button type="button" class="sp-parse">Parsen</button>
				<button type="button" class="sp-clear sp-secondary">Wissen</button>
				<button type="button" class="sp-example sp-secondary">Voorbeeld laden</button>
				<button type="button" class="sp-copy sp-secondary">Resultaat kopiëren</button>
			</div>

			<div class="sp-results"></div>

			<form class="sp-email-form sp-hidden">
				<input type="email" class="sp-email-input" placeholder="jouw@email.nl" required>
				<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $turnstile_site_key ); ?>" data-theme="dark"></div>
				<button type="submit">E-mail me het volledige rapport</button>
				<span class="sp-email-status"></span>
			</form>
		</div>

	</div>
	<?php
	return ob_get_clean();
} );

// ---------------------------------------------------------------------
// AJAX endpoint: re-parse server-side and email the full report.
// ---------------------------------------------------------------------

add_action( 'wp_ajax_dbaronald_stats_parser_email', 'dbaronald_stats_parser_email' );
add_action( 'wp_ajax_nopriv_dbaronald_stats_parser_email', 'dbaronald_stats_parser_email' );

function dbaronald_stats_parser_email() {
	check_ajax_referer( 'dbaronald_stats_parser', 'nonce' );

	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$raw   = isset( $_POST['raw'] ) ? wp_unslash( $_POST['raw'] ) : '';
	$lang  = ( isset( $_POST['lang'] ) && $_POST['lang'] === 'en' ) ? 'en' : 'nl';

	if ( ! is_email( $email ) ) {
		wp_send_json( array( 'success' => false, 'error' => 'invalid_email' ) );
	}
	if ( strlen( $raw ) === 0 || strlen( $raw ) > 300000 ) { // ~300 KB is generous for pasted text
		wp_send_json( array( 'success' => false, 'error' => 'invalid_payload' ) );
	}

	// Rate limit: max 5 emails/hour per IP. Checked (not yet incremented)
	// before the Turnstile network call so a client that's already over
	// the cap can't be used to hammer Cloudflare's siteverify endpoint.
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
	$key = 'dbsp_rl_' . md5( $ip );
	$count = (int) get_transient( $key );
	if ( $count >= 5 ) {
		wp_send_json( array( 'success' => false, 'error' => 'rate_limited' ) );
	}

	$turnstile_token = isset( $_POST['turnstile_token'] ) ? sanitize_text_field( wp_unslash( $_POST['turnstile_token'] ) ) : '';
	if ( ! dbaronald_stats_parser_verify_turnstile( $turnstile_token, $ip ) ) {
		wp_send_json( array( 'success' => false, 'error' => 'captcha_failed' ) );
	}

	set_transient( $key, $count + 1, HOUR_IN_SECONDS );

	$parsed = dbaronald_stats_parser_parse( $raw );
	if ( empty( $parsed['tables'] ) && ! $parsed['sawTime'] ) {
		wp_send_json( array( 'success' => false, 'error' => 'no_match' ) );
	}

	$findings = dbaronald_stats_parser_build_findings( $parsed, $lang );
	$body     = dbaronald_stats_parser_render_email( $parsed, $findings, $lang );

	$subject = $lang === 'en'
		? 'Your Statistics Parser report — dbaronald.nl'
		: 'Je Statistics Parser-rapport — dbaronald.nl';

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );
	$sent    = wp_mail( $email, $subject, $body, $headers );

	wp_send_json( array( 'success' => (bool) $sent ) );
}

// ---------------------------------------------------------------------
// Cloudflare Turnstile — server-side verification of the widget token.
// The WP nonce alone only stops naive drive-by POSTs, not a scripted
// attacker who first loads the page to scrape a valid nonce; Turnstile is
// the actual bot/abuse gate.
// ---------------------------------------------------------------------

function dbaronald_stats_parser_verify_turnstile( $token, $ip ) {
	if ( empty( $token ) || ! defined( 'DBARONALD_TURNSTILE_SECRET_KEY' ) ) {
		return false;
	}

	$response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
		'timeout' => 8,
		'body'    => array(
			'secret'   => DBARONALD_TURNSTILE_SECRET_KEY,
			'response' => $token,
			'remoteip' => $ip,
		),
	) );

	if ( is_wp_error( $response ) ) {
		return false;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return ! empty( $body['success'] );
}

// ---------------------------------------------------------------------
// Parsing + rule set — mirrors assets/js/stats-parser.js exactly.
// ---------------------------------------------------------------------

function dbaronald_stats_parser_parse( $text ) {
	$table_re = '/Table\s+\'([^\']+)\'\.\s*Scan count (\d+),\s*logical reads (\d+),\s*physical reads (\d+),\s*(?:page server reads \d+,\s*)?read-ahead reads (\d+),\s*(?:page server read-ahead reads \d+,\s*)?lob logical reads (\d+),\s*lob physical reads (\d+)/i';
	$time_re  = '/CPU time\s*=\s*(\d+)\s*ms,\s*elapsed time\s*=\s*(\d+)\s*ms/i';

	preg_match_all( $table_re, $text, $table_matches, PREG_SET_ORDER );
	$raw_tables = array();
	foreach ( $table_matches as $m ) {
		$raw_tables[] = array(
			'name'       => $m[1],
			'scans'      => (int) $m[2],
			'logical'    => (int) $m[3],
			'physical'   => (int) $m[4],
			'readAhead'  => (int) $m[5],
			'lobLogical' => (int) $m[6],
			'lobPhysical' => (int) $m[7],
		);
	}

	preg_match_all( $time_re, $text, $time_matches, PREG_SET_ORDER );
	$cpu = 0;
	$elapsed = 0;
	$saw_time = ! empty( $time_matches );
	foreach ( $time_matches as $m ) {
		$cpu     += (int) $m[1];
		$elapsed += (int) $m[2];
	}

	// Sum same-named tables (repeated statement batches), like the JS engine.
	$merged = array();
	foreach ( $raw_tables as $t ) {
		if ( ! isset( $merged[ $t['name'] ] ) ) {
			$merged[ $t['name'] ] = array(
				'name' => $t['name'], 'scans' => 0, 'logical' => 0, 'physical' => 0,
				'readAhead' => 0, 'lobLogical' => 0, 'lobPhysical' => 0,
			);
		}
		foreach ( array( 'scans', 'logical', 'physical', 'readAhead', 'lobLogical', 'lobPhysical' ) as $k ) {
			$merged[ $t['name'] ][ $k ] += $t[ $k ];
		}
	}

	return array(
		'tables'  => array_values( $merged ),
		'cpu'     => $cpu,
		'elapsed' => $elapsed,
		'sawTime' => $saw_time,
	);
}

function dbaronald_stats_parser_build_findings( $parsed, $lang ) {
	$strings = dbaronald_stats_parser_strings( $lang );
	$findings = array();
	$max_scans = 0;
	foreach ( $parsed['tables'] as $t ) {
		$max_scans = max( $max_scans, $t['scans'] );
	}

	foreach ( $parsed['tables'] as $t ) {
		if ( $t['physical'] > 0 ) {
			$findings[] = array( 'level' => 'amber', 'text' => str_replace( '{table}', $t['name'], $strings['msgPhysical'] ) );
		}
		if ( $t['lobLogical'] > 0 ) {
			$findings[] = array( 'level' => 'accent', 'text' => str_replace( '{table}', $t['name'], $strings['msgLob'] ) );
		}
		if ( preg_match( '/^#|work(table|file)/i', $t['name'] ) ) {
			$findings[] = array( 'level' => 'amber', 'text' => str_replace( '{table}', $t['name'], $strings['msgWork'] ) );
		}
		if ( $t['scans'] > 1 && count( $parsed['tables'] ) > 1 && $t['scans'] === $max_scans ) {
			$text = str_replace( array( '{table}', '{count}' ), array( $t['name'], $t['scans'] ), $strings['msgScan'] );
			$findings[] = array( 'level' => 'accent', 'text' => $text );
		}
	}

	if ( $parsed['sawTime'] && $parsed['elapsed'] > 0 ) {
		if ( $parsed['cpu'] > $parsed['elapsed'] * 1.5 ) {
			$findings[] = array( 'level' => 'accent', 'text' => $strings['msgCpuHigh'] );
		} elseif ( $parsed['elapsed'] > $parsed['cpu'] * 3 && ( $parsed['elapsed'] - $parsed['cpu'] ) > 50 ) {
			$findings[] = array( 'level' => 'amber', 'text' => $strings['msgElapsedHigh'] );
		}
	}

	return $findings;
}

function dbaronald_stats_parser_strings( $lang ) {
	if ( $lang === 'en' ) {
		return array(
			'table' => 'Table', 'scans' => 'Scans', 'logical' => 'Logical reads',
			'physical' => 'Physical reads', 'readAhead' => 'Read-ahead', 'lob' => 'LOB logical',
			'total' => 'TOTAL', 'findings' => 'Findings', 'noFindings' => 'No red flags found in this output.',
			'cpuElapsed' => 'CPU time = %s ms, elapsed time = %s ms',
			'msgPhysical' => '{table}: physical reads present — cold cache or memory pressure, worth a second look.',
			'msgLob' => '{table}: LOB logical reads present — are large columns (varchar(max)/nvarchar(max)/varbinary(max)) actually needed by this query?',
			'msgWork' => '{table}: work table/file — spool, sort or hash work is happening in tempdb.',
			'msgScan' => '{table}: scan count {count} — possibly a nested loop hitting this table repeatedly. Check the execution plan to confirm.',
			'msgCpuHigh' => 'CPU time is much higher than elapsed time — possible parallelism overhead for this batch.',
			'msgElapsedHigh' => 'Elapsed time is much higher than CPU time — the query likely waited on something (locks, I/O, resources).',
		);
	}
	return array(
		'table' => 'Tabel', 'scans' => 'Scans', 'logical' => 'Logical reads',
		'physical' => 'Physical reads', 'readAhead' => 'Read-ahead', 'lob' => 'LOB logical',
		'total' => 'TOTAAL', 'findings' => 'Signalen', 'noFindings' => 'Geen bijzonderheden gevonden in deze output.',
		'cpuElapsed' => 'CPU-tijd = %s ms, verstreken tijd = %s ms',
		'msgPhysical' => '{table}: physical reads aanwezig — koude cache of geheugendruk, de moeite van een tweede blik waard.',
		'msgLob' => '{table}: LOB logical reads aanwezig — worden grote kolommen (varchar(max)/nvarchar(max)/varbinary(max)) echt gebruikt door deze query?',
		'msgWork' => '{table}: work table/file — er gebeurt spool-, sort- of hash-werk in tempdb.',
		'msgScan' => '{table}: scan count {count} — mogelijk een nested loop die deze tabel herhaaldelijk raakt. Het executieplan bevestigt dit.',
		'msgCpuHigh' => 'CPU-tijd is veel hoger dan de verstreken tijd — mogelijk parallellisme-overhead voor deze batch.',
		'msgElapsedHigh' => 'Verstreken tijd is veel hoger dan de CPU-tijd — de query heeft waarschijnlijk gewacht (locks, I/O, resources).',
	);
}

function dbaronald_stats_parser_render_email( $parsed, $findings, $lang ) {
	$s = dbaronald_stats_parser_strings( $lang );
	$intro = $lang === 'en'
		? 'You requested this analysis on dbaronald.nl. Nobody else can see what you pasted — it was only used to generate this email.'
		: 'Je hebt deze analyse aangevraagd op dbaronald.nl. Niemand anders ziet wat je hebt geplakt — het is alleen gebruikt om deze e-mail te genereren.';

	ob_start();
	?>
	<div style="font-family:Consolas,monospace;background:#0a0e14;color:#dfe7f1;padding:24px;">
		<p style="color:#93a1b5;"><?php echo esc_html( $intro ); ?></p>

		<?php if ( ! empty( $parsed['tables'] ) ) : ?>
		<table style="border-collapse:collapse;width:100%;">
			<thead>
				<tr>
					<th style="text-align:left;border:1px solid #223047;padding:6px 10px;"><?php echo esc_html( $s['table'] ); ?></th>
					<th style="border:1px solid #223047;padding:6px 10px;"><?php echo esc_html( $s['scans'] ); ?></th>
					<th style="border:1px solid #223047;padding:6px 10px;"><?php echo esc_html( $s['logical'] ); ?></th>
					<th style="border:1px solid #223047;padding:6px 10px;"><?php echo esc_html( $s['physical'] ); ?></th>
					<th style="border:1px solid #223047;padding:6px 10px;"><?php echo esc_html( $s['readAhead'] ); ?></th>
					<th style="border:1px solid #223047;padding:6px 10px;"><?php echo esc_html( $s['lob'] ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $parsed['tables'] as $t ) : ?>
				<tr>
					<td style="border:1px solid #223047;padding:6px 10px;"><?php echo esc_html( $t['name'] ); ?></td>
					<td style="border:1px solid #223047;padding:6px 10px;text-align:right;"><?php echo number_format_i18n( $t['scans'] ); ?></td>
					<td style="border:1px solid #223047;padding:6px 10px;text-align:right;"><?php echo number_format_i18n( $t['logical'] ); ?></td>
					<td style="border:1px solid #223047;padding:6px 10px;text-align:right;"><?php echo number_format_i18n( $t['physical'] ); ?></td>
					<td style="border:1px solid #223047;padding:6px 10px;text-align:right;"><?php echo number_format_i18n( $t['readAhead'] ); ?></td>
					<td style="border:1px solid #223047;padding:6px 10px;text-align:right;"><?php echo number_format_i18n( $t['lobLogical'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<?php if ( $parsed['sawTime'] ) : ?>
			<p><?php echo esc_html( sprintf( $s['cpuElapsed'], number_format_i18n( $parsed['cpu'] ), number_format_i18n( $parsed['elapsed'] ) ) ); ?></p>
		<?php endif; ?>

		<h3><?php echo esc_html( $s['findings'] ); ?></h3>
		<?php if ( empty( $findings ) ) : ?>
			<p style="color:#93a1b5;font-style:italic;"><?php echo esc_html( $s['noFindings'] ); ?></p>
		<?php else : ?>
			<ul style="list-style:none;padding:0;">
			<?php foreach ( $findings as $f ) :
				$color = $f['level'] === 'amber' ? '#fbbf24' : '#38bdf8';
				?>
				<li style="border:1px solid <?php echo esc_attr( $color ); ?>;color:<?php echo esc_attr( $color ); ?>;border-radius:8px;padding:8px 12px;margin-bottom:8px;">
					<?php echo esc_html( $f['text'] ); ?>
				</li>
			<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<p style="color:#93a1b5;font-size:.85em;">dbaronald.nl — Statistics Parser</p>
	</div>
	<?php
	return ob_get_clean();
}

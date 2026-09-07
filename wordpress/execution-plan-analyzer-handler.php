<?php
/**
 * dbaronald.nl — Execution Plan Analyzer: WordPress page + "email me the report" handler.
 *
 * Renders the Execution Plan Analyzer tool via a shortcode (needed because
 * the page must embed a fresh WP nonce on every view — a static Custom HTML
 * block can't do that, and a hardcoded nonce would go stale within ~24h)
 * and implements the optional "email me the full report" AJAX endpoint.
 *
 * The client-side parsing/findings engine itself is NOT pasted here — it
 * lives at assets/js/execution-plan-analyzer.js in the dbaronald.com repo
 * and deploys automatically via git push. This snippet loads it from there
 * with a plain <script src>, and re-implements the same rules in PHP below
 * for the emailed version (never trust the client's parsed results for
 * something that gets emailed out).
 *
 * IMPORTANT: keep the rule set below in sync with
 * assets/js/execution-plan-analyzer.js — dbaronald_plan_analyzer_build_findings()
 * mirrors buildFindings() and dbaronald_plan_analyzer_parse() mirrors
 * parsePlan(). See CLAUDE.md "Adding the Execution Plan Analyzer tool".
 *
 * This is the same shape as stats-parser-handler.php (client-side analysis
 * + optional emailed report), NOT plan-submit-handler.php (which just
 * forwards an uploaded .sqlplan to Ronald and analyzes nothing).
 *
 * Requires Cloudflare Turnstile (free bot-check, gates the "email me the
 * report" AJAX endpoint — the WP nonce alone doesn't stop a scripted
 * attacker, and the endpoint will email whatever address is typed in). Set
 * up before activating this snippet:
 *   1. dash.cloudflare.com → Turnstile → Add site → domain dbaronald.nl,
 *      widget mode "Managed". Copy the Site Key and Secret Key.
 *   2. In wp-config.php (NOT in this repo — it holds a secret), above the
 *      "That's all, stop editing!" line, add (shared with Statistics
 *      Parser — if that snippet is already installed these are already set):
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
 * Then create a WP Page (e.g. slug "execution-plan-analyzer") whose content
 * is just the shortcode: [dbaronald_plan_analyzer]
 * Add that page to the site menu via Appearance → Menus.
 */

define( 'DBARONALD_SHOWPLAN_NS', 'http://schemas.microsoft.com/sqlserver/2004/07/showplan' );

// ---------------------------------------------------------------------
// Shortcode: renders the tool with a fresh nonce + admin-ajax URL.
// ---------------------------------------------------------------------

add_shortcode( 'dbaronald_plan_analyzer', function ( $atts ) {
	$atts  = shortcode_atts( array( 'lang' => 'nl' ), $atts );
	$lang  = $atts['lang'] === 'en' ? 'en' : 'nl';
	$nonce = wp_create_nonce( 'dbaronald_plan_analyzer' );
	$ajax  = admin_url( 'admin-ajax.php' );

	wp_enqueue_script(
		'dbaronald-plan-analyzer',
		'https://dbaronald.com/assets/js/execution-plan-analyzer.js',
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
	// plan-analyzer-page.html (the local test harness carries its own copy
	// because it also sets `body` background, which this scoped version
	// deliberately doesn't — that's the live theme's job).
	static $css_printed = false;
	if ( ! $css_printed ) {
		$css_printed = true;
		?>
		<style>
			.plan-analyzer { max-width:1000px; margin:0 auto; display:grid; grid-template-columns:280px 1fr; gap:28px; }
			@media (max-width: 720px) { .plan-analyzer { grid-template-columns:1fr; } }
			.plan-analyzer [hidden] { display:none !important; }
			.plan-analyzer .epa-panel { background:#121927; border:1px solid #223047; border-radius:10px; padding:20px; align-self:start; }
			.plan-analyzer .epa-panel h2 { margin-top:0; font-size:1.05rem; color:#dfe7f1; }
			.plan-analyzer .epa-panel code { font-family:"Cascadia Code",Consolas,"SF Mono",Menlo,monospace; color:#22d3ee; background:#0e1420; border-radius:4px; padding:.1em .35em; }
			.plan-analyzer .epa-privacy { margin-top:16px; padding-top:14px; border-top:1px solid #223047; font-size:.85rem; color:#93a1b5; }
			.plan-analyzer .epa-lang-switch { margin-bottom:16px; font-family:monospace; font-size:.85rem; }
			.plan-analyzer .epa-lang-switch a { color:#93a1b5; text-decoration:none; padding:2px 6px; }
			.plan-analyzer .epa-lang-switch a.active { color:#38bdf8; font-weight:bold; }
			.plan-analyzer .epa-main { display:flex; flex-direction:column; gap:14px; }
			.plan-analyzer .epa-input { width:100%; min-height:220px; box-sizing:border-box; background:#121927; color:#dfe7f1; border:1px solid #223047; border-radius:8px; padding:12px; font-family:"Cascadia Code",Consolas,"SF Mono",Menlo,monospace; font-size:.85rem; resize:vertical; }
			.plan-analyzer .epa-input:focus { border-color:#38bdf8; outline:none; }
			.plan-analyzer .epa-upload { font-size:.85rem; color:#93a1b5; }
			.plan-analyzer .epa-upload input[type="file"] { color:#dfe7f1; font-size:.85rem; }
			.plan-analyzer .epa-actions { display:flex; gap:10px; flex-wrap:wrap; }
			.plan-analyzer .epa-actions button { background:#38bdf8; color:#04121d; border:none; border-radius:8px; padding:9px 16px; font-size:.9rem; cursor:pointer; }
			.plan-analyzer .epa-actions button:hover { background:#22d3ee; }
			.plan-analyzer .epa-actions button.epa-secondary { background:transparent; color:#dfe7f1; border:1px solid #223047; }
			.plan-analyzer .epa-actions button.epa-secondary:hover { border-color:#38bdf8; color:#38bdf8; }
			.plan-analyzer .epa-results { min-height:20px; }
			.plan-analyzer .epa-empty { color:#93a1b5; font-style:italic; }
			.plan-analyzer .epa-op-title, .plan-analyzer .epa-findings-title { font-size:.95rem; margin-bottom:8px; }
			.plan-analyzer .epa-table-wrap { overflow-x:auto; }
			.plan-analyzer .epa-table { width:100%; border-collapse:collapse; font-size:.88rem; }
			.plan-analyzer .epa-table th, .plan-analyzer .epa-table td { border:1px solid #223047; padding:7px 10px; text-align:right; white-space:nowrap; }
			.plan-analyzer .epa-table th:first-child, .plan-analyzer .epa-table td:first-child { text-align:left; }
			.plan-analyzer .epa-table th { background:#121927; color:#dfe7f1; }
			.plan-analyzer .epa-node-id { color:#93a1b5; }
			.plan-analyzer .epa-findings { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:8px; }
			.plan-analyzer .pill { display:inline-block; font-family:monospace; font-size:.82rem; padding:6px 12px; border-radius:8px; border:1px solid #223047; background:#121927; color:#93a1b5; }
			.plan-analyzer .pill-accent { color:#38bdf8; border-color:rgba(56,189,248,.4); background:rgba(56,189,248,.08); }
			.plan-analyzer .pill-amber { color:#fbbf24; border-color:rgba(251,191,36,.4); background:rgba(251,191,36,.08); }
			.plan-analyzer .epa-email-form { display:flex; gap:10px; flex-wrap:wrap; align-items:center; padding-top:10px; border-top:1px solid #223047; }
			.plan-analyzer .epa-email-form input[type="email"] { background:#121927; color:#dfe7f1; border:1px solid #223047; border-radius:8px; padding:8px 12px; min-width:220px; }
			.plan-analyzer .epa-hidden { display:none; }
			.plan-analyzer .epa-email-status { font-size:.85rem; color:#93a1b5; }
		</style>
		<?php
	}
	?>
	<div class="plan-analyzer" data-lang="<?php echo esc_attr( $lang ); ?>"
	     data-ajax-url="<?php echo esc_url( $ajax ); ?>"
	     data-nonce="<?php echo esc_attr( $nonce ); ?>">

		<aside class="epa-panel">
			<div class="epa-lang-switch">
				<a href="#" data-set-lang="nl">NL</a> | <a href="#" data-set-lang="en">EN</a>
			</div>

			<div data-lang-text="nl">
				<h2>Execution Plan Analyzer</h2>
				<p>Plak de <code>Show Execution Plan XML</code> (rechtsklik op het plan in SSMS &rarr; "Show Execution Plan XML"), of upload een <code>.sqlplan</code>-bestand. De tool leest het plan uit en wijst je op bekende signalen — spills naar tempdb, impliciete conversies, scheve schattingen, key lookups, ontbrekende indexen en overbodige parallellie.</p>
				<div class="epa-privacy">100% client-side — wat je hier plakt verlaat nooit je browser, tenzij je zelf op "e-mail me het rapport" klikt.</div>
			</div>

			<div data-lang-text="en">
				<h2>Execution Plan Analyzer</h2>
				<p>Paste the <code>Show Execution Plan XML</code> (right-click the plan in SSMS &rarr; "Show Execution Plan XML"), or upload a <code>.sqlplan</code> file. The tool reads the plan and points out known red flags — spills to tempdb, implicit conversions, skewed estimates, key lookups, missing indexes and needless parallelism.</p>
				<div class="epa-privacy">100% client-side — anything you paste here never leaves your browser, unless you click "email me the report" yourself.</div>
			</div>
		</aside>

		<div class="epa-main">
			<textarea class="epa-input" placeholder="Plak hier de Show Execution Plan XML, of upload een .sqlplan-bestand..."></textarea>

			<label class="epa-upload">
				<span class="epa-upload-label">Of upload een .sqlplan-bestand</span>
				<input type="file" class="epa-file-input" accept=".sqlplan,.xml,text/xml">
			</label>

			<div class="epa-actions">
				<button type="button" class="epa-analyze">Analyseer</button>
				<button type="button" class="epa-clear epa-secondary">Wissen</button>
				<button type="button" class="epa-example epa-secondary">Voorbeeld laden</button>
				<button type="button" class="epa-copy epa-secondary">Resultaat kopiëren</button>
			</div>

			<div class="epa-results"></div>

			<form class="epa-email-form epa-hidden">
				<input type="email" class="epa-email-input" placeholder="jouw@email.nl" required>
				<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $turnstile_site_key ); ?>" data-theme="dark"></div>
				<button type="submit">E-mail me het volledige rapport</button>
				<span class="epa-email-status"></span>
			</form>
		</div>

	</div>
	<?php
	return ob_get_clean();
} );

// ---------------------------------------------------------------------
// AJAX endpoint: re-parse server-side and email the full report.
// ---------------------------------------------------------------------

add_action( 'wp_ajax_dbaronald_plan_analyzer_email', 'dbaronald_plan_analyzer_email' );
add_action( 'wp_ajax_nopriv_dbaronald_plan_analyzer_email', 'dbaronald_plan_analyzer_email' );

function dbaronald_plan_analyzer_email() {
	check_ajax_referer( 'dbaronald_plan_analyzer', 'nonce' );

	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$raw   = isset( $_POST['raw'] ) ? wp_unslash( $_POST['raw'] ) : '';
	$lang  = ( isset( $_POST['lang'] ) && $_POST['lang'] === 'en' ) ? 'en' : 'nl';

	if ( ! is_email( $email ) ) {
		wp_send_json( array( 'success' => false, 'error' => 'invalid_email' ) );
	}
	// Showplan XML gets large; 4 MB is generous but still bounded.
	if ( strlen( $raw ) === 0 || strlen( $raw ) > 4000000 ) {
		wp_send_json( array( 'success' => false, 'error' => 'invalid_payload' ) );
	}

	// Rate limit: max 5 emails/hour per IP. Checked (not yet incremented)
	// before the Turnstile network call so a client that's already over the
	// cap can't be used to hammer Cloudflare's siteverify endpoint.
	$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
	$key   = 'dbepa_rl_' . md5( $ip );
	$count = (int) get_transient( $key );
	if ( $count >= 5 ) {
		wp_send_json( array( 'success' => false, 'error' => 'rate_limited' ) );
	}

	$turnstile_token = isset( $_POST['turnstile_token'] ) ? sanitize_text_field( wp_unslash( $_POST['turnstile_token'] ) ) : '';
	if ( ! dbaronald_plan_analyzer_verify_turnstile( $turnstile_token, $ip ) ) {
		wp_send_json( array( 'success' => false, 'error' => 'captcha_failed' ) );
	}

	set_transient( $key, $count + 1, HOUR_IN_SECONDS );

	$plan = dbaronald_plan_analyzer_parse( $raw );
	if ( $plan === null ) {
		wp_send_json( array( 'success' => false, 'error' => 'no_match' ) );
	}

	$findings = dbaronald_plan_analyzer_build_findings( $plan, $lang );
	$body     = dbaronald_plan_analyzer_render_email( $plan, $findings, $lang );

	$subject = $lang === 'en'
		? 'Your Execution Plan Analyzer report — dbaronald.nl'
		: 'Je Execution Plan Analyzer-rapport — dbaronald.nl';

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );
	$sent    = wp_mail( $email, $subject, $body, $headers );

	wp_send_json( array( 'success' => (bool) $sent ) );
}

// ---------------------------------------------------------------------
// Cloudflare Turnstile — server-side verification of the widget token.
// The WP nonce alone only stops naive drive-by POSTs, not a scripted
// attacker who first loads the page to scrape a valid nonce; Turnstile is
// the actual bot/abuse gate (this endpoint emails whatever address is
// typed in).
// ---------------------------------------------------------------------

function dbaronald_plan_analyzer_verify_turnstile( $token, $ip ) {
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
// Parsing + rule set — mirrors assets/js/execution-plan-analyzer.js exactly.
// parsePlan() -> dbaronald_plan_analyzer_parse()
// buildFindings() -> dbaronald_plan_analyzer_build_findings()
// ---------------------------------------------------------------------

function dbaronald_plan_analyzer_parse( $xml_text ) {
	$ns = DBARONALD_SHOWPLAN_NS;

	libxml_use_internal_errors( true );
	$doc    = new DOMDocument();
	$loaded = $doc->loadXML( $xml_text, LIBXML_NONET );
	libxml_clear_errors();

	if ( ! $loaded || ! $doc->documentElement ) {
		return null;
	}
	if ( $doc->documentElement->namespaceURI !== $ns ) {
		return null;
	}

	$xp = new DOMXPath( $doc );
	$xp->registerNamespace( 's', $ns );

	$relops = $xp->query( '//s:RelOp' );
	if ( ! $relops || $relops->length === 0 ) {
		return null;
	}

	$dop      = 1;
	$dop_node = $xp->query( '(//s:QueryPlan/@DegreeOfParallelism)[1]' );
	if ( $dop_node && $dop_node->length > 0 ) {
		$dop = (int) $dop_node->item( 0 )->nodeValue;
	}

	$operators = array();
	foreach ( $relops as $relop ) {
		$physical = $relop->getAttribute( 'PhysicalOp' );
		$logical  = $relop->getAttribute( 'LogicalOp' );
		if ( $physical === '' ) {
			$physical = '?';
		}

		$runtime_info = null;
		foreach ( $xp->query( './s:RunTimeInformation', $relop ) as $ri ) {
			$runtime_info = $ri;
			break;
		}

		$warnings = null;
		foreach ( $xp->query( './s:Warnings', $relop ) as $w ) {
			$warnings = $w;
			break;
		}

		$est_attr    = $relop->getAttribute( 'EstimateRows' );
		$estimate    = ( $est_attr !== '' ) ? (float) $est_attr : null;

		$actual_rows = dbaronald_plan_analyzer_sum_threads( $xp, $runtime_info, 'ActualRows' );
		$actual_exec = dbaronald_plan_analyzer_sum_threads( $xp, $runtime_info, 'ActualExecutions' );

		$parent      = $relop->parentNode;
		$is_top_level = ( $parent && $parent->localName === 'QueryPlan' );

		$operators[] = array(
			'nodeId'           => $relop->getAttribute( 'NodeId' ),
			'physicalOp'       => $physical,
			'logicalOp'        => $logical,
			'label'            => dbaronald_plan_analyzer_op_label( $physical, $logical ),
			'estimateRows'     => $estimate,
			'actualRows'       => $actual_rows,
			'actualExecutions' => $actual_exec,
			'warnings'         => $warnings,
			'isTopLevel'       => $is_top_level,
		);
	}

	$missing_index_groups = array();
	foreach ( $xp->query( '//s:MissingIndexGroup' ) as $group ) {
		$mi = null;
		foreach ( $xp->query( './/s:MissingIndex', $group ) as $node ) {
			$mi = $node;
			break;
		}
		if ( ! $mi ) {
			continue;
		}
		$table = str_replace( array( '[', ']' ), '', $mi->getAttribute( 'Table' ) );
		$cols  = array();
		foreach ( $xp->query( './/s:ColumnGroup', $mi ) as $cg ) {
			$usage     = $cg->getAttribute( 'Usage' );
			$col_names = array();
			foreach ( $xp->query( './/s:Column', $cg ) as $col ) {
				$col_names[] = str_replace( array( '[', ']' ), '', $col->getAttribute( 'Name' ) );
			}
			$cols[] = $usage . ': ' . implode( ', ', $col_names );
		}
		$missing_index_groups[] = array(
			'table'  => $table,
			'impact' => (float) $group->getAttribute( 'Impact' ),
			'cols'   => implode( ' · ', $cols ),
		);
	}

	return array(
		'operators'          => $operators,
		'missingIndexGroups' => $missing_index_groups,
		'dop'                => $dop,
	);
}

function dbaronald_plan_analyzer_op_label( $physical, $logical ) {
	return ( $logical !== '' && $logical !== $physical ) ? $physical . ' (' . $logical . ')' : $physical;
}

function dbaronald_plan_analyzer_sum_threads( $xp, $runtime_info, $attr ) {
	if ( ! $runtime_info ) {
		return null;
	}
	$threads = $xp->query( './/s:RunTimeCountersPerThread', $runtime_info );
	if ( ! $threads || $threads->length === 0 ) {
		return null;
	}
	$total = 0.0;
	foreach ( $threads as $t ) {
		$v = $t->getAttribute( $attr );
		if ( $v !== '' ) {
			$total += (float) $v;
		}
	}
	return $total;
}

function dbaronald_plan_analyzer_build_findings( $plan, $lang ) {
	$s        = dbaronald_plan_analyzer_strings( $lang );
	$ns       = DBARONALD_SHOWPLAN_NS;
	$findings = array();

	foreach ( $plan['operators'] as $op ) {
		$w = $op['warnings'];
		if ( $w ) {
			$no_join = ( $w->getAttribute( 'NoJoinPredicate' ) === 'true' )
				|| ( $w->getElementsByTagNameNS( $ns, 'NoJoinPredicate' )->length > 0 );
			if ( $no_join ) {
				$findings[] = array(
					'level' => 'amber',
					'text'  => dbaronald_plan_analyzer_fill( $s['msgWarnNoJoinPredicate'], array( '{op}' => $op['label'], '{id}' => $op['nodeId'] ) ),
				);
			}

			if ( $w->getElementsByTagNameNS( $ns, 'SpillToTempDb' )->length > 0 ) {
				$findings[] = array(
					'level' => 'amber',
					'text'  => dbaronald_plan_analyzer_fill( $s['msgWarnSpill'], array( '{op}' => $op['label'], '{id}' => $op['nodeId'] ) ),
				);
			}

			foreach ( $w->getElementsByTagNameNS( $ns, 'PlanAffectingConvert' ) as $conv ) {
				$expr = $conv->getAttribute( 'Expression' );
				if ( $expr === '' ) {
					$expr = '?';
				}
				$findings[] = array(
					'level' => 'amber',
					'text'  => dbaronald_plan_analyzer_fill( $s['msgWarnConvert'], array( '{op}' => $op['label'], '{id}' => $op['nodeId'], '{expr}' => $expr ) ),
				);
			}

			$no_stats = $w->getElementsByTagNameNS( $ns, 'ColumnsWithNoStatistics' );
			if ( $no_stats->length > 0 ) {
				$names = array();
				foreach ( $no_stats->item( 0 )->getElementsByTagNameNS( $ns, 'ColumnReference' ) as $cr ) {
					$col = $cr->getAttribute( 'Column' );
					$names[] = ( $col === '' ) ? '?' : $col;
				}
				$findings[] = array(
					'level' => 'accent',
					'text'  => dbaronald_plan_analyzer_fill( $s['msgWarnNoStats'], array( '{op}' => $op['label'], '{id}' => $op['nodeId'], '{cols}' => implode( ', ', $names ) ) ),
				);
			}
		}

		if ( $op['estimateRows'] !== null && $op['actualRows'] !== null && $op['estimateRows'] > 0 && $op['actualRows'] > 0 ) {
			$ratio = $op['actualRows'] / $op['estimateRows'];
			$inv   = $op['estimateRows'] / $op['actualRows'];
			$worst = max( $ratio, $inv );
			if ( $worst >= 10 ) {
				$findings[] = array(
					'level' => 'accent',
					'text'  => dbaronald_plan_analyzer_fill( $s['msgSkewHigh'], array(
						'{op}'    => $op['label'],
						'{id}'    => $op['nodeId'],
						'{est}'   => number_format_i18n( round( $op['estimateRows'] ) ),
						'{act}'   => number_format_i18n( round( $op['actualRows'] ) ),
						'{ratio}' => number_format_i18n( round( $worst ) ),
					) ),
				);
			}
		}

		if ( ( $op['logicalOp'] === 'Key Lookup' || $op['physicalOp'] === 'RID Lookup' )
			&& $op['actualExecutions'] !== null && $op['actualExecutions'] > 100 ) {
			$findings[] = array(
				'level' => 'amber',
				'text'  => dbaronald_plan_analyzer_fill( $s['msgLookup'], array(
					'{op}'    => $op['label'],
					'{id}'    => $op['nodeId'],
					'{count}' => number_format_i18n( round( $op['actualExecutions'] ) ),
				) ),
			);
		}
	}

	foreach ( $plan['missingIndexGroups'] as $mi ) {
		$findings[] = array(
			'level' => 'accent',
			'text'  => dbaronald_plan_analyzer_fill( $s['msgMissingIndex'], array(
				'{table}'  => $mi['table'],
				'{impact}' => round( $mi['impact'] ),
				'{cols}'   => $mi['cols'],
			) ),
		);
	}

	if ( $plan['dop'] > 1 ) {
		$top_op = null;
		foreach ( $plan['operators'] as $o ) {
			if ( $o['isTopLevel'] ) {
				$top_op = $o;
				break;
			}
		}
		if ( ! $top_op && ! empty( $plan['operators'] ) ) {
			$top_op = $plan['operators'][0];
		}
		if ( $top_op && $top_op['actualRows'] !== null && $top_op['actualRows'] < 100 ) {
			$findings[] = array(
				'level' => 'amber',
				'text'  => dbaronald_plan_analyzer_fill( $s['msgParallelSmall'], array(
					'{dop}'  => $plan['dop'],
					'{rows}' => number_format_i18n( round( $top_op['actualRows'] ) ),
				) ),
			);
		}
	}

	return $findings;
}

function dbaronald_plan_analyzer_fill( $template, $map ) {
	return str_replace( array_keys( $map ), array_values( $map ), $template );
}

function dbaronald_plan_analyzer_strings( $lang ) {
	if ( $lang === 'en' ) {
		return array(
			'opSummary'              => 'Operators',
			'opCol'                  => 'Operator',
			'estCol'                 => 'Estimated',
			'actCol'                 => 'Actual',
			'findings'               => 'Findings',
			'noFindings'             => 'No red flags found in this plan.',
			'noMatch'                => "This doesn't look like valid SQL Server showplan XML — make sure you pasted the XML (not the graphical view).",
			'msgWarnSpill'           => '{op} (node {id}): spilled to tempdb — the memory grant was too small for this sort/hash. Consider the memory grant or improving the cardinality estimate.',
			'msgWarnNoJoinPredicate' => '{op} (node {id}): join with no join predicate (cross join) — check whether this is intentional.',
			'msgWarnConvert'         => '{op} (node {id}): implicit conversion ({expr}) — this can make an index unusable or skew the cardinality estimate.',
			'msgWarnNoStats'         => '{op} (node {id}): columns with no statistics ({cols}) — the optimizer is guessing here.',
			'msgSkewHigh'            => '{op} (node {id}): estimated {est} rows, actual {act} — a {ratio}x deviation. The plan may have made a wrong join or operator choice because of this.',
			'msgLookup'              => '{op} (node {id}): executed {count}x — a covering index that removes these lookups may be missing.',
			'msgMissingIndex'        => "Missing index suggested on {table} (impact {impact}%): {cols}. Verify before applying — the DTA suggestion isn't always optimal.",
			'msgParallelSmall'       => 'Parallel plan (DOP {dop}) for a small result set ({rows} rows) — parallelism overhead may outweigh the benefit here.',
			'intro'                  => 'You requested this analysis on dbaronald.nl. Nobody else can see what you pasted — it was only used to generate this email.',
		);
	}
	return array(
		'opSummary'              => 'Operators',
		'opCol'                  => 'Operator',
		'estCol'                 => 'Geschat',
		'actCol'                 => 'Actueel',
		'findings'               => 'Signalen',
		'noFindings'             => 'Geen bijzonderheden gevonden in dit plan.',
		'noMatch'                => 'Dit lijkt geen geldige SQL Server showplan XML — controleer of je de XML (niet de grafische weergave) hebt geplakt.',
		'msgWarnSpill'           => '{op} (node {id}): spill naar tempdb — het geheugengrant was te klein voor deze sort/hash. Overweeg de geheugengrant of de cardinaliteitsschatting te verbeteren.',
		'msgWarnNoJoinPredicate' => '{op} (node {id}): join zonder join-predicaat (cross join) — controleer of dit bedoeld is.',
		'msgWarnConvert'         => '{op} (node {id}): impliciete conversie ({expr}) — dit kan een index onbruikbaar maken of de cardinaliteitsschatting verstoren.',
		'msgWarnNoStats'         => '{op} (node {id}): kolommen zonder statistieken ({cols}) — de optimizer gokt hier.',
		'msgSkewHigh'            => '{op} (node {id}): geschat {est} rijen, actueel {act} — een afwijking van {ratio}x. Het plan kan hierdoor een verkeerde join- of operatorkeuze hebben gemaakt.',
		'msgLookup'              => '{op} (node {id}): {count}x uitgevoerd — mogelijk ontbreekt een covering index die deze lookups overbodig maakt.',
		'msgMissingIndex'        => 'Ontbrekende index gesuggereerd op {table} (impact {impact}%): {cols}. Controleer dit voordat je het toepast — de DTA-suggestie is niet altijd optimaal.',
		'msgParallelSmall'       => 'Parallel plan (DOP {dop}) voor een kleine resultset ({rows} rijen) — de overhead van parallellisme kan hier groter zijn dan de winst.',
		'intro'                  => 'Je hebt deze analyse aangevraagd op dbaronald.nl. Niemand anders ziet wat je hebt geplakt — het is alleen gebruikt om deze e-mail te genereren.',
	);
}

function dbaronald_plan_analyzer_render_email( $plan, $findings, $lang ) {
	$s = dbaronald_plan_analyzer_strings( $lang );

	ob_start();
	?>
	<div style="font-family:Consolas,monospace;background:#0a0e14;color:#dfe7f1;padding:24px;">
		<p style="color:#93a1b5;"><?php echo esc_html( $s['intro'] ); ?></p>

		<h3><?php echo esc_html( $s['opSummary'] ); ?></h3>
		<table style="border-collapse:collapse;width:100%;">
			<thead>
				<tr>
					<th style="text-align:left;border:1px solid #223047;padding:6px 10px;"><?php echo esc_html( $s['opCol'] ); ?></th>
					<th style="border:1px solid #223047;padding:6px 10px;"><?php echo esc_html( $s['estCol'] ); ?></th>
					<th style="border:1px solid #223047;padding:6px 10px;"><?php echo esc_html( $s['actCol'] ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $plan['operators'] as $op ) : ?>
				<tr>
					<td style="border:1px solid #223047;padding:6px 10px;"><?php echo esc_html( $op['label'] ); ?> #<?php echo esc_html( $op['nodeId'] ); ?></td>
					<td style="border:1px solid #223047;padding:6px 10px;text-align:right;"><?php echo $op['estimateRows'] !== null ? esc_html( number_format_i18n( round( $op['estimateRows'] ) ) ) : '—'; ?></td>
					<td style="border:1px solid #223047;padding:6px 10px;text-align:right;"><?php echo $op['actualRows'] !== null ? esc_html( number_format_i18n( round( $op['actualRows'] ) ) ) : '—'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

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

		<p style="color:#93a1b5;font-size:.85em;">dbaronald.nl — Execution Plan Analyzer</p>
	</div>
	<?php
	return ob_get_clean();
}

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Bilingual (NL/EN) static portfolio site for Ronald de Groot, senior (Azure) SQL Server DBA — served at dbaronald.com. Plain HTML/CSS/JS: **no build step, no framework, no package.json, no tests**. Hosted on Hostinger shared hosting (Apache/LiteSpeed). The companion domain dbaronald.nl runs a separate WordPress blog (see "Adding a blog article") and, since 2026-08-19, two interactive PHP-backed features hand-installed as WPCode snippets: a public **Statistics Parser** community tool and a private **execution plan submission form** (see their respective sections below) — both share their client-side JS engines with this static repo but are otherwise entirely outside the normal git-deploy pipeline.

## Commands

Local preview (must serve from repo root — pages use root-relative paths like `/assets/...`, so opening files directly from disk breaks them):

```sh
python -m http.server 8123
```

**Deploying: `git push` to `main` = live site.** A GitHub webhook triggers Hostinger auto-deployment into `public_html`. Don't push half-finished work to `main`.

## Architecture

- `index.html` — language detector only. Picks NL/EN from `localStorage["dbaronald-lang"]`, the `dbaronald.nl` hostname, or `navigator.languages`, then redirects to `/nl/` or `/en/`. Also serves as the 404 page (see `.htaccess`).
- `nl/` and `en/` — **parallel mirror trees**. `nl/index.html` and `en/index.html` are the one-page profiles; `nl/blog/` and `en/blog/` hold "SQL Tips" articles as standalone HTML pages. Every content change must be applied to both languages, and each page carries `canonical` + `hreflang` links to its counterpart. Dutch section anchors differ (`#over`, `#ervaring`, `#certificeringen`, `#vliegen` vs `#about`, `#experience`, `#certifications`, `#flying`). The profile pages' section order is: hero → About → Skills → Certifications → Experience → SQL Tips (blog cards) → GitHub → Flying → Contact. The header nav (`.site-nav`) mirrors that order as uppercase tab-style links; `assets/js/main.js` highlights the active tab via scrollspy as the user scrolls.
- `assets/css/style.css` — single shared stylesheet (dark terminal theme, `--accent`/`--green` variables, `.cards` grid, `.card-tag` "SQL comment" labels like `-- performance tuning`). `.site-nav a.active` drives the tab-nav underline; `.pill`/`.pill-accent`/`.pill-amber` is the shared rounded-badge component (used by the client-name cloud and the `.cert-list`/`.cert-row` Certifications section — keep new badge-style UI on this one class rather than inventing another).
- `assets/js/main.js` — all interactivity, keyed off `<html lang>`: language-switch persistence (`.lang-switch` needs `data-lang`), scroll reveal (`.reveal`), scrollspy active-nav-tab highlighting (matches `.site-nav a[href*='#']` hrefs to section ids — a separate `IntersectionObserver` from the reveal one since it must keep re-firing on scroll), hero terminal typing animation, day-of-year "SQL tip" rotator (bilingual `tips` object — extend both arrays), live GitHub repos widget (`#gh-repos`, falls back to static card on API failure), footer year.
- `.htaccess` — forces HTTPS, returns 404 for `/docs/`, sets caching and security headers.
- `sitemap.xml` — maintained by hand; add new page URLs here.
- `docs/` — **private, git-ignored** (CV source). Never commit or deploy it.

## Adding a blog article

1. Copy an existing article (e.g. `nl/blog/index-tuning.html`) to a new filename; write the English twin in `en/blog/`.
2. Replace title, meta description, canonical/hreflang URLs and body.
3. Add a card in `nl/blog/index.html` **and** `en/blog/index.html` (and optionally the homepage `#blog` sections).
4. Add both URLs to `sitemap.xml`, commit, push.

Some posts live on the separate WordPress blog at `dbaronald.nl/<slug>/` (Blocksy theme); those get a card here linking out (with `target="_blank" rel="noopener"`) rather than a local page — added to both blog overviews and both homepage `#blog` sections, no `sitemap.xml` entry. Move the "nieuw"/"new" card-tag suffix from the previous newest card to the new one, and mark the NL card "(Engelstalig)" or the EN card "(In Dutch)" when the post's language differs. That blog's dark styling is maintained in `wordpress/blocksy-dark.css` — after editing it, paste the full contents into dbaronald.nl's wp-admin → Appearance → Customize → Additional CSS. The matching dark canvas for the Gutenberg post editor is `wordpress/blocksy-dark-editor.php`, installed as a WPCode snippet (instructions in the file's header comment) — Additional CSS alone leaves the editor white-on-white. Code blocks in posts use the **Code Block Pro** plugin (Gutenberg block, Shiki-based, free) — set a dark Shiki theme per block; `blocksy-dark.css` only frames it (border, off-column width, slim scrollbar). Superseded SyntaxHighlighter Evolved on 2026-08-31; convert any remaining `[code]`/SyntaxHighlighter blocks by hand, then deactivate + delete that plugin.

## Adding the Statistics Parser tool

A free community tool at `dbaronald.nl/statistics-parser/`: paste `SET STATISTICS IO/TIME ON` output, get it formatted with rule-based suggestions (physical reads, worktables, LOB reads, skewed CPU/elapsed time), plus an optional "email me the full report" button. Three files, two very different deployment paths:

- `assets/js/stats-parser.js` — the parsing/suggestions engine (pure client-side, no network calls). Lives in the **main static repo**, not under `wordpress/`, so it deploys automatically via the normal `git push` pipeline like everything else. The live WordPress page loads it straight from `https://dbaronald.com/assets/js/stats-parser.js` via a plain cross-origin `<script src>` (script tags don't need CORS; only `fetch`/XHR do) — this avoids maintaining a second pasted copy in wp-admin.
- `wordpress/stats-parser-page.html` — a standalone copy of the page markup for **local testing only** (`python -m http.server 8123`, open `/wordpress/stats-parser-page.html`). Not what's actually served on WordPress — its "email me" button has no real backend behind it.
- `wordpress/stats-parser-handler.php` — the real thing: a WPCode "Run Everywhere" PHP snippet (install steps in its header comment) that registers the `[dbaronald_stats_parser]` shortcode (rendering the same markup but with a real WP nonce baked in — a static Custom HTML block can't do that, since the nonce goes stale within ~24h) and the `wp_ajax_dbaronald_stats_parser_email` AJAX handler. The handler re-implements the **same rule set as `stats-parser.js`** independently in PHP (`dbaronald_stats_parser_build_findings()`) — never trust client-computed results for something that gets emailed out — then sends via `wp_mail()`. Keep the two rule sets in sync when adding/changing a suggestion.
- Abuse protection on the email path: a WP nonce, a 5-emails/hour-per-IP rate limit (`get_transient`/`set_transient`), and Cloudflare Turnstile (the real bot gate — the nonce alone doesn't stop a scripted attacker). Turnstile needs two secrets set in `wp-config.php` (never in this repo): `DBARONALD_TURNSTILE_SITE_KEY` and `DBARONALD_TURNSTILE_SECRET_KEY` — see the header comment in `stats-parser-handler.php` for where to get them. Known accepted risk: the endpoint will email whatever address is typed in, not necessarily the submitter's own — mitigated by rate limiting, not eliminated.
- To create the live page: paste `stats-parser-handler.php` into a WPCode PHP snippet, then create a WP Page whose entire content is the shortcode `[dbaronald_stats_parser]`, then add it to the site nav via Appearance → Menus.

## Adding the execution plan submission form

A **private** page (never linked in any nav/card — share the URL directly) for
known consulting clients at a wp-admin-chosen slug on dbaronald.nl: they
upload a `.sqlplan` file, it gets emailed straight to
`ronald.de.groot@opendata.nl`, which lands in the existing Power Automate →
OneDrive → SQLPerformanceViewer intake pipeline (see the
`execution-plan-intake-pipeline` memory) exactly like a direct email
attachment would. Unlike Statistics Parser, **nothing here analyzes
anything** — analysis stays fully manual in SQLPerformanceViewer, and the
reply with a results link is sent by Ronald himself. Same two-path structure
as Statistics Parser:

- `assets/js/plan-submit.js` — form wiring only (no rule engine to speak of),
  deploys via the normal git-push pipeline, loaded cross-origin from
  `https://dbaronald.com/assets/js/plan-submit.js`.
- `wordpress/plan-submit-page.html` — local test harness only.
- `wordpress/plan-submit-handler.php` — the real thing: WPCode "Run
  Everywhere" PHP snippet registering the `[dbaronald_plan_submit]` shortcode
  (fresh nonce per view, same reasoning as Statistics Parser) and the
  `wp_ajax_dbaronald_plan_submit_email` handler, which validates the upload
  (extension, ≤8MB, and a real showplan-XML-namespace check via
  `DOMDocument`) and forwards it with `wp_mail()`'s `$attachments` param —
  with `Reply-To` set to the client's own address so replying in Outlook goes
  straight back to them even though WordPress sent the email.
- No Turnstile here (unlike Statistics Parser) — the destination address is
  hardcoded to Ronald's own inbox, so there's no email-relay-to-a-stranger
  abuse vector, just a plain nonce + 10-submissions/hour-per-IP rate limit as
  a backstop. **Note:** Ronald's stated preference (2026-08-19, see the
  `feedback-default-to-abuse-protection` memory) is to default new
  internet-facing forms to abuse-resistant rather than reasoning per-feature
  about whether a specific vector applies — this endpoint predates that
  feedback and may be worth revisiting.

**Known issue (both email-sending features above):** `wp_mail()` on this
Hostinger/WordPress install is unreliable — Statistics Parser's report email
has landed in spam, and a plan-submit test reported success but the email
never arrived anywhere (not even spam), consistent with PHP's bare `mail()`
fire-and-forget behavior with no SPF/DKIM. Fix in progress: WP Mail SMTP +
Microsoft 365 OAuth (paused mid-Azure-app-registration as of 2026-08-19) —
full status in the `execution-plan-intake-pipeline` memory. Don't trust
`wp_mail()`'s return value as proof of delivery until that's resolved.

## Privacy rule

The public site deliberately shows **no home address or phone number** — contact is via email, LinkedIn and GitHub only. Never add personal contact details to any page.

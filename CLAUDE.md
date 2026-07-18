# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Bilingual (NL/EN) static portfolio site for Ronald de Groot, senior (Azure) SQL Server DBA — served at dbaronald.com. Plain HTML/CSS/JS: **no build step, no framework, no package.json, no tests**. Hosted on Hostinger shared hosting (Apache/LiteSpeed). The companion domain dbaronald.nl runs a separate WordPress blog (see "Adding a blog article").

## Commands

Local preview (must serve from repo root — pages use root-relative paths like `/assets/...`, so opening files directly from disk breaks them):

```sh
python -m http.server 8123
```

**Deploying: `git push` to `main` = live site.** A GitHub webhook triggers Hostinger auto-deployment into `public_html`. Don't push half-finished work to `main`.

## Architecture

- `index.html` — language detector only. Picks NL/EN from `localStorage["dbaronald-lang"]`, the `dbaronald.nl` hostname, or `navigator.languages`, then redirects to `/nl/` or `/en/`. Also serves as the 404 page (see `.htaccess`).
- `nl/` and `en/` — **parallel mirror trees**. `nl/index.html` and `en/index.html` are the one-page profiles; `nl/blog/` and `en/blog/` hold "SQL Tips" articles as standalone HTML pages. Every content change must be applied to both languages, and each page carries `canonical` + `hreflang` links to its counterpart. Dutch section anchors differ (`#over`, `#ervaring` vs `#about`, `#experience`).
- `assets/css/style.css` — single shared stylesheet (dark terminal theme, `--accent`/`--green` variables, `.cards` grid, `.card-tag` "SQL comment" labels like `-- performance tuning`).
- `assets/js/main.js` — all interactivity, keyed off `<html lang>`: language-switch persistence (`.lang-switch` needs `data-lang`), scroll reveal (`.reveal`), hero terminal typing animation, day-of-year "SQL tip" rotator (bilingual `tips` object — extend both arrays), live GitHub repos widget (`#gh-repos`, falls back to static card on API failure), footer year.
- `.htaccess` — forces HTTPS, returns 404 for `/docs/`, sets caching and security headers.
- `sitemap.xml` — maintained by hand; add new page URLs here.
- `docs/` — **private, git-ignored** (CV source). Never commit or deploy it.

## Adding a blog article

1. Copy an existing article (e.g. `nl/blog/index-tuning.html`) to a new filename; write the English twin in `en/blog/`.
2. Replace title, meta description, canonical/hreflang URLs and body.
3. Add a card in `nl/blog/index.html` **and** `en/blog/index.html` (and optionally the homepage `#blog` sections).
4. Add both URLs to `sitemap.xml`, commit, push.

Some posts live on the separate WordPress blog at `dbaronald.nl/<slug>/` (Blocksy theme); those get a card here linking out (with `target="_blank" rel="noopener"`) rather than a local page — added to both blog overviews and both homepage `#blog` sections, no `sitemap.xml` entry. Move the "nieuw"/"new" card-tag suffix from the previous newest card to the new one, and mark the NL card "(Engelstalig)" or the EN card "(In Dutch)" when the post's language differs. That blog's dark styling is maintained in `wordpress/blocksy-dark.css` — after editing it, paste the full contents into dbaronald.nl's wp-admin → Appearance → Customize → Additional CSS.

## Privacy rule

The public site deliberately shows **no home address or phone number** — contact is via email, LinkedIn and GitHub only. Never add personal contact details to any page.

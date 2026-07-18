# dbaronald.com — personal website of Ronald de Groot

Bilingual (NL/EN) static portfolio site for a senior (Azure) SQL Server DBA / BI consultant.
Plain HTML/CSS/JS — no build step, no CMS. Made for Hostinger shared hosting.

The companion domain **dbaronald.nl** runs a separate WordPress blog (Blocksy theme);
this repo only holds its dark-theme CSS (see `wordpress/`). Posts published there get a
card on this site linking out.

## Structure

| Path | What it is |
|---|---|
| `index.html` | Language detector — redirects to `/nl/` or `/en/` |
| `nl/`, `en/` | The one-page profile per language |
| `nl/blog/`, `en/blog/` | "SQL Tips" articles (plain HTML pages) |
| `assets/` | Shared CSS, JS and images |
| `.htaccess` | HTTPS redirect, caching, blocks `/docs/` |
| `wordpress/` | Dark Blocksy CSS for the WordPress blog on dbaronald.nl |
| `docs/` | **Private** (git-ignored) — CV source, never deployed |

## Local preview

```
python -m http.server 8123
```

Then open http://localhost:8123/ — root-relative links require serving from the repo root
(don't open the HTML files directly from disk).

## Deploying to Hostinger (one-time setup)

1. Log in to **hPanel** → *Websites* → select **dbaronald.com** → *Dashboard*.
2. Go to **Advanced → GIT**.
3. Under *Create a New Repository*:
   - **Repository**: `https://github.com/ronaldgithub/website.git` (use the SSH URL if the repo is private — hPanel shows the deploy key to add on GitHub under *Settings → Deploy keys*)
   - **Branch**: `main`
   - **Directory**: leave empty (deploys into `public_html`)
4. Click **Create** — Hostinger clones the repo into `public_html`.
5. Enable **Auto deployment** in the same screen and copy the **Webhook URL**.
6. On GitHub: repo → *Settings → Webhooks → Add webhook*, paste the URL, content type `application/json`, event: *Just the push event*.

From then on: `git push` = live site.

### The dbaronald.nl WordPress blog

**dbaronald.nl** is not an alias of this site: it runs its own WordPress install (Blocksy
theme) where some posts are published at `dbaronald.nl/<slug>/`. Its dark styling is
maintained in this repo as `wordpress/blocksy-dark.css` — after editing that file, paste
the full contents into dbaronald.nl's wp-admin → *Appearance → Customize → Additional CSS*.
`wordpress/blocksy-dark-editor.php` darkens the Gutenberg post editor to match (the skin's
white text otherwise sits on the editor's white canvas); it is installed as a WPCode
snippet — see the file's header comment.
(The language detector in `index.html` still defaults to Dutch for the dbaronald.nl
hostname, from when the domain pointed at this site.)

## Adding a blog article

1. Copy an existing article, e.g. `nl/blog/index-tuning.html`, to a new filename.
2. Replace title, meta description, canonical/hreflang URLs and the body content.
3. Do the same for the English version in `en/blog/`.
4. Add a card for it in `nl/blog/index.html` and `en/blog/index.html`.
5. Add both URLs to `sitemap.xml`.
6. Commit and push.

Posts published on the dbaronald.nl WordPress blog instead get a card here (blog
overviews + homepage `#blog` sections, both languages) linking out with
`target="_blank" rel="noopener"` — no local page and no `sitemap.xml` entry.

## Privacy

The public site deliberately shows **no home address or phone number** — contact goes via
email, LinkedIn and GitHub. The CV in `docs/` is git-ignored for the same reason.

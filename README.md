# dbaronald.com — personal website of Ronald de Groot

Bilingual (NL/EN) static portfolio site for a senior (Azure) SQL Server DBA / BI consultant.
Plain HTML/CSS/JS — no build step, no CMS. Made for Hostinger shared hosting.

## Structure

| Path | What it is |
|---|---|
| `index.html` | Language detector — redirects to `/nl/` or `/en/` |
| `nl/`, `en/` | The one-page profile per language |
| `nl/blog/`, `en/blog/` | "SQL Tips" articles (plain HTML pages) |
| `assets/` | Shared CSS, JS and images |
| `.htaccess` | HTTPS redirect, caching, blocks `/docs/` |
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

### Pointing dbaronald.nl at the same site

In hPanel: *Domains* → **dbaronald.nl** → point it to the same hosting plan (as an alias/parked
domain on the dbaronald.com website). The site automatically serves Dutch as the default
language on dbaronald.nl (see `index.html`). Enable the free SSL certificate for both domains
under *Websites → SSL*.

## Adding a blog article

1. Copy an existing article, e.g. `nl/blog/index-tuning.html`, to a new filename.
2. Replace title, meta description, canonical/hreflang URLs and the body content.
3. Do the same for the English version in `en/blog/`.
4. Add a card for it in `nl/blog/index.html` and `en/blog/index.html`.
5. Add both URLs to `sitemap.xml`.
6. Commit and push.

## Privacy

The public site deliberately shows **no home address or phone number** — contact goes via
email, LinkedIn and GitHub. The CV in `docs/` is git-ignored for the same reason.

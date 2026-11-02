# Agent guide: SEO Framework

Use this guide when integrating the framework into a website or maintaining this
repository. Read [README.md](README.md) for the full product reference and
[docs/index.html](docs/index.html) for the documentation website.

## Product and requirements

SEO Framework is a standalone PHP companion for single-page applications. It
packages existing frontend assets and serves public semantic HTML, metadata,
language alternates, JSON-LD and a sitemap. The SPA takes over interaction after
the initial response. Optional anonymous HTTPS JSON sources provide dynamic
pages through cached snapshots.

- Require PHP 8.1+ with JSON support. Live API fetching also requires PHP cURL
  and a working CA trust store.
- Composer and npm installation are not required to use the runtime or examples.
- Keep everything portable: no machine-specific paths, parent repositories,
  sibling applications or unbundled adapter dependencies.
- The application runtime needs PHP hosting. GitHub Pages serves the static
  `docs/` website; it does not run the PHP gateway.
- Commands below run from the repository root unless stated otherwise. Use
  paths appropriate to the current checkout rather than assuming its location.

## Find the relevant files

| File or directory | Responsibility |
| --- | --- |
| `bootstrap.php` | Dependency-free autoloader for the `SeoFramework` namespace. |
| `src/Config.php` | Configuration, route and dataset validation. |
| `src/Application.php` | Runtime wiring, request handling and status. |
| `src/Renderer.php` | Public HTML, metadata, localized routing and sitemap. |
| `src/JsonFetcher.php` | Anonymous HTTPS JSON fetching and response extraction. |
| `src/SnapshotCache.php` | TTL, locks, backoff and atomic snapshot replacement. |
| `src/Builder.php` | Portable deployment packaging and Apache policy. |
| `bin/seo.php` | Public command-line interface. |
| `assets/seo-client.mjs` | Browser helpers `updateSeo()` and `markPrivate()`. |
| `examples/basic/` | Complete English/Arabic SPA demonstration. |
| `examples/catalog/` | Independent template, product adapter and JSON seed. |
| `tools/build-example.php` | Builds either included example. |
| `tools/router.php` | PHP development router; not a production server config. |
| `tests/` | Runtime, HTTP and package relocation checks. |
| `docs/` | Static documentation website, including all its assets. |

## Run an example first

```sh
php --version
php bin/seo.php validate examples/basic/site.php
php tools/build-example.php
php -S 127.0.0.1:8088 -t examples/basic/dist tools/router.php
```

Open `/en`, `/ar/about` and `/sitemap.xml` on that server. Inspect View Page
Source, not just the JavaScript-modified DOM, to verify the initial HTML.

For data-driven pages, use the catalog's bundled fixture:

```sh
php bin/seo.php validate examples/catalog/site.php
php tools/build-example.php catalog
php -S 127.0.0.1:8089 -t examples/catalog/dist tools/router.php
```

Open `/en/products/desk-lamp`. The catalog builder packages offline; the running
application still attempts refreshes when its seed is stale. Replace its
placeholder API URL before relying on live refreshes.

## Integrate into a user's SPA

1. Inspect the frontend's build output, entry HTML, mount element, asset base,
   router basename and hosting target. Identify public pages, private prefixes,
   supported languages and the real HTTPS origin.
2. Preserve a clean entry template outside the generated deployment output.
   Match `rootId` to the mount element. Use an empty mount element or put exactly
   one `<!-- SEO:BODY -->` inside it. The optional `<!-- SEO:HEAD -->` belongs
   inside `<head>`. Preserve built script and stylesheet URLs.
3. Create a PHP site config returning an array. Adapt `examples/basic/site.php`
   for static pages or `examples/catalog/site.php` for public JSON data. Paths
   such as `template`, `seed` and `includeFiles` resolve relative to the config.
4. Set `siteUrl` to a clean HTTPS origin without a path, query or credentials.
   Use `basePath` for a hosting subdirectory and match the frontend asset base
   and router basename to it.
5. Define unique public paths and default-language titles in `translations`.
   Put private prefixes in `spaRoutes`. Language prefixes default to enabled;
   set `prefixLanguages` to false only for a single-language site.
6. Run the frontend's existing build process. Its output directory must already
   exist before packaging. Validate and package it, for example:

   ```sh
   php bin/seo.php validate site.php
   php bin/seo.php build site.php dist
   ```

7. After client-side public navigation, call `updateSeo()` from the packaged
   `/__seo/seo-client.mjs`. Call `markPrivate()` for account/login routes.
   Include the hosting base path in that import and in metadata URLs when needed.
8. Verify the staged output and deploy the complete directory, including
   dotfiles, to PHP hosting. Package again after every frontend build.

The fallback is generic semantic HTML, not framework-specific hydration markup.
Hydrated applications need an intentional fallback handoff or a separate mount
strategy. Keep the SPA and fallback content consistent.

## Add public JSON sources

- A source has an HTTPS `url`, optional dot-separated `dataPath`, `type` (`list`
  or `object`) and `allowEmpty` (default true).
- A seed is JSON with `generatedAt` in Unix seconds and a `data` map keyed by
  source name. Store extracted records, not the API response envelope.
- `pageProvider($data, $site)` returns additional page definitions. Tolerate an
  empty initial dataset when no seed is supplied, or provide a valid seed.
- Use `validateData($data)` to reject incomplete or invalid records by throwing.
  Normalize slugs to safe paths and avoid duplicate or private routes.
- Import adapters with `require __DIR__ . '/adapters/name.php'`. List every
  required PHP/JSON dependency in `includeFiles`, relative to the config.
- Pair each adapter with its actual seed schema. The included catalog has
  localized `name` and `description` maps, numeric `price` and `currency`.
- Use `--offline` for fixture-based packaging without fetching. It selects the
  configured seed, not an existing refreshed cache, and does not disable later
  runtime API refreshes.
- API URLs must come from trusted configuration. Keep cookies, credentials,
  personal data and account data out of sources and seeds. Preserve TLS
  verification, redirect refusal, timeouts and response-size limits.

## Cache and deployment behavior

Default TTL is 3600 seconds; retry backoff is 300 seconds. Concurrent requests
reuse the previous snapshot while a writer refreshes. Only validated data may
replace the last good snapshot through an atomic rename. Preserve those
guarantees when editing fetching, caching or adapters.

Source-side commands:

```sh
php bin/seo.php status site.php
php bin/seo.php refresh site.php
```

From a deployed build directory:

```sh
php seo.php status
php seo.php refresh
```

`status` is read-only. `refresh` forces a fetch and exits nonzero on failure or
lock contention. The packaged entry with no CLI argument also refreshes.

Packaging writes the supplied config to `.seo/config-source.php` and creates
`.seo/config.php` to use the bundled template and seed. Source `cacheDirectory`
values are reset. For persistent target-host storage, set `SEO_CACHE_DIRECTORY`
to an absolute writable private directory outside the web root, consistently
for web PHP and CLI/cron. Otherwise the runtime uses a host-local temp directory.

- Work in a staging build directory. The builder modifies its output.
- Preserve custom `.htaccess` rules. Use `--replace-htaccess` only when replacing
  them is intentional, after reviewing redirects and security settings.
- Include both `.htaccess` files. Deny HTTP access to the entire `.seo/` tree.
- Apache/LiteSpeed needs rewrites and overrides; Nginx needs equivalent private
  directory denial, asset handling and PHP fallback rules.
- Verify a public page, a private route with `noindex, nofollow`, an unknown
  route returning 404, missing assets returning 404, and the sitemap.
- Confirm `/.seo/config.php` returns 403 and HTTP `/seo.php` returns 404.
- Keep authentication in the application and render only public content.

## Maintain and verify changes

Follow the existing PHP style: strict types, the `SeoFramework` namespace and
PHP 8.1-compatible syntax. Keep the dependency-free bootstrap usable. Edit
source files rather than generated `examples/*/dist/` or packaged `.seo/` files.
Keep fixtures self-contained and tests independent of external APIs.

For runtime, adapter or packaging changes, run:

```sh
php tests/run.php
php tests/http.php
php tests/standalone.php
```

`composer test` runs the same suites when Composer is available. Lint changed PHP
files with `php -l path/to/file.php`. Validate and rebuild any affected example.
Changes to packaging must preserve operation after relocating the output and
removing the build inputs; `tests/standalone.php` checks this behavior.

For documentation-only edits, verify the affected examples, links and behavior
without unnecessarily rerunning unrelated runtime suites. Update `README.md`
and `docs/index.html` when public behavior or commands change.

## Maintain the documentation website

Edit `docs/index.html`, `docs/assets/styles.css` and `docs/assets/docs.js`.
Keep all essential content and navigation usable without JavaScript. Preserve
mobile navigation, keyboard access, search, copy controls and readable code.
Use relative asset URLs so the site works at a GitHub repository subpath. Bundle
all assets; do not introduce CDN, font-service or build-tool requirements.
Keep `docs/.nojekyll` present.

Preview from the project root:

```sh
php -S 127.0.0.1:8090 -t docs
```

GitHub Pages publishes the selected branch's `/docs` folder. The website itself
documents the publishing steps. Do not put generated PHP deployments, private
configurations or runtime caches inside `docs/`.

## Report completed work

State what changed, which commands actually passed and any remaining limits.
Distinguish creating a local package or documentation website from publishing
it. Do not claim a live deployment or successful API refresh without evidence.

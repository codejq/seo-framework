# SEO Framework for single-page apps

A standalone PHP library that serves crawlable public HTML alongside an existing
single-page application. It packages your SPA's built assets, adds public page
content and metadata to the initial response, and refreshes public JSON data
without rebuilding the frontend.

Free and open source under the [MIT License](LICENSE), including for personal
and commercial use.

The project is self-contained: all runtime code, browser helpers, example
templates and seed data are included. Copy or extract this directory anywhere;
no parent repository, sibling application, machine-specific path, Composer
install or npm install is needed. It requires PHP **8.1 or later** with JSON
support. Live public API sources also require the PHP **cURL** extension and a
working CA trust store. Composer autoloading is also supported.

For coding agents, [AGENTS.md](AGENTS.md) describes the integration workflow,
repository structure, commands, validation checks and deployment requirements.

## Documentation website

The complete guide is in [docs/index.html](docs/index.html). It covers setup,
SPA integration, configuration, languages, JSON adapters, cache refreshes and
deployment, with search and copyable code examples. Preview it from the project
root:

```sh
php -S 127.0.0.1:8090 -t docs
```

Open `http://127.0.0.1:8090`. To publish on GitHub Pages, push `docs/` (including
`.nojekyll`), then open **Settings → Pages**, choose **Deploy from a branch**,
select the branch containing the website and the **/docs** folder, and save.
See [GitHub's publishing-source guide](https://docs.github.com/en/pages/getting-started-with-github-pages/configuring-a-publishing-source-for-your-github-pages-site)
for details. No build command or dependency installation is required.

The documentation is static and works on GitHub Pages. The framework's PHP
application runtime requires a PHP-capable host.

## Project layout

| Path | Contents |
| --- | --- |
| `src/`, `bootstrap.php` | PHP runtime and dependency-free autoloader. |
| `bin/seo.php` | Validation, packaging, refresh and status commands. |
| `assets/seo-client.mjs` | Optional browser metadata helper. |
| `examples/basic/` | Complete bilingual SPA demo. |
| `examples/catalog/` | Independent catalog template, adapter and JSON seed. |
| `tools/` | Example builder and PHP development router. |
| `tests/` | Runtime, HTTP and deployment relocation checks. |
| `docs/` | Self-contained static documentation website for GitHub Pages. |

## Try the working example

Run these commands from the project root, wherever you extracted it. Confirm
that `php --version` reports PHP 8.1 or later:

```sh
php bin/seo.php validate examples/basic/site.php
php tools/build-example.php
php -S 127.0.0.1:8088 -t examples/basic/dist tools/router.php
```

Open `http://127.0.0.1:8088/en` or `/ar`. View Source already contains page
content, canonical links, social metadata and JSON-LD. JavaScript then replaces
the fallback content with the interactive demo. Disable JavaScript to inspect
the fallback on its own. `/sitemap.xml` contains the four localized public pages.

The example uses `https://example.com` for canonical URLs. Change `siteUrl` when
adapting it to a real site. The development router simulates the generated
Apache policy; production requires a PHP-capable host.

## Add it to an existing SPA

1. Keep a clean copy of your built entry HTML. Configure `template` to point at
   that file. The framework preserves the existing script and stylesheet URLs.
2. Create a PHP site config like `examples/basic/site.php`. Define your public
   pages, languages and private SPA route prefixes.
3. Match `rootId` to the SPA mount element: for example `root` for many React
   builds or `app` for many Vue builds. An empty mount element works directly.
   Alternatively, place `<!-- SEO:BODY -->` **inside** that element and optionally
   `<!-- SEO:HEAD -->` inside `<head>`.
4. Run the packaging command after the SPA build:

   ```sh
   php bin/seo.php build site.php dist
   ```

   Here `site.php` is your configuration and `dist` is your existing frontend
   build directory. Config paths such as `template`, `seed` and `includeFiles`
   resolve relative to the configuration file, independent of the shell's
   working directory. Use `__DIR__` for adapter imports and list every required
   PHP/JSON adapter file in `includeFiles`.

5. Upload the complete resulting build directory, including dotfiles, to your
   PHP web root. Test a public route, a private route, the sitemap and
   `/.seo/config.php` (which must return 403).

Existing `.htaccess` files are protected. Review and merge your custom redirects,
security rules and PHP-version settings with the generated policy. Passing
`--replace-htaccess` explicitly replaces it. The packaging command writes the
deployment directory; use a staging build directory rather than a live web root.
Build again after every frontend asset build so PHP uses the current asset URLs.

The renderer supplies semantic content from your config and adapters. Your SPA
still renders its own visual design and interactive behavior. Keep the public
content consistent in both places. Apps using hydration need an intentional
fallback handoff or a separate mount strategy: this HTML is a generic semantic
render, rather than framework-generated hydration markup.

## Site configuration

| Option | Purpose |
| --- | --- |
| `siteUrl`, `siteName` | HTTPS origin and brand name. The origin must have no path, query or credentials. |
| `template`, `rootId` | Clean SPA entry file and mount ID; default ID is `root`. |
| `languages`, `defaultLanguage` | Supported language codes and fallback translation. |
| `prefixLanguages` | Default `true`: `/en/about`. Set `false` with one language for `/about`. |
| `rtlLanguages` | Defaults to Arabic, Persian, Hebrew and Urdu. |
| `basePath` | Empty for root hosting; `/shop` for a subdirectory deployment. Configure the SPA's asset base and router basename to match. |
| `pages` | Explicit public page definitions. |
| `spaRoutes` | Private route prefixes such as `/account` or `/app`; children inherit SPA handling. |
| `sources` | Named anonymous HTTPS JSON endpoints. |
| `seed` | Optional local JSON snapshot with `generatedAt` in Unix seconds and a `data` map. |
| `pageProvider` | Callback receiving the complete data map and site options; returns additional public pages. |
| `validateData` | Callback that throws when data is incomplete or invalid. |
| `refreshSeconds`, `retrySeconds` | Defaults: 3600-second TTL, 300-second error backoff. |
| `includeFiles` | Relative PHP/JSON adapter files to include in the deployment package. |
| `cacheDirectory` | Optional private directory for source-side commands, **outside** the web root. Packaged deployments use their host's temp directory or `SEO_CACHE_DIRECTORY`; see below. |
| `cacheVersion` | Bump when changing an adapter's interpretation of cached data; default `1`. |

Each page has a unique `path`, such as `/`, `/about` or `/products/desk-lamp`, and
a `translations` map. Every page needs a default-language `title`; other fields
are `nav`, `description`, `image`, `sections` and `structuredData`. A section may
contain `heading`, `text` (one paragraph or a list), `items`, and `links`
(`url`/`label`). Text is escaped and script/style elements are stripped. Links
accept local absolute paths or HTTPS URLs. Raw API HTML is not inserted.

Page-level options include `schemaType`, `ogType`, `lastModified` (`YYYY-MM-DD`),
and `navigation: false` to omit a page from the generated navigation. All public
pages appear in the sitemap. Paths use ASCII letters, numbers, hyphens,
underscores and `/`; dynamic adapters should normalize slugs accordingly.

Unknown public routes return 404. Explicit private SPA routes get the clean app
template and `X-Robots-Tag: noindex, nofollow`. Your application remains
responsible for authentication. The gateway does not render account data.

## Connect any public JSON API

See `examples/catalog/site.php` and `examples/catalog/adapters/products.php`.
A source has `url`, optional dot-separated `dataPath` (for example `data.items`),
`type` (`list` or `object`) and `allowEmpty` (default `true`). The callback maps
the returned records to page definitions, so product, course, article, service
and pricing APIs can use the same runtime.

Try the catalog with its included fixture, without contacting a real API:

```sh
php bin/seo.php validate examples/catalog/site.php
php tools/build-example.php catalog
php -S 127.0.0.1:8089 -t examples/catalog/dist tools/router.php
```

Open `http://127.0.0.1:8089/en/products/desk-lamp` or
`http://127.0.0.1:8089/ar/products/desk-lamp`. This example builds offline from
its own template, adapter and seed. It needs no frontend assets or live API.
After creating the output directory, the generic equivalent is:

```sh
php bin/seo.php build examples/catalog/site.php examples/catalog/dist --offline
```

Replace the placeholder API URL before using live refresh. Adapters must tolerate
an empty initial dataset or supply a valid seed. Validation and dynamic route
generation finish before a new snapshot replaces the cache. Deleting records
removes their routes and sitemap entries on the next successful refresh.

Only public, non-personal JSON belongs in a source or seed. API URLs come from
trusted configuration, never request parameters. Requests send no user cookies
or authorization headers, verify TLS, refuse redirects, run in parallel and
have an eight-second timeout and a four-MB body limit per source. Application
code owns pagination; configure additional named sources or an adapter as needed
before a catalog exceeds its API's page size.

## Cache and one-time refresh

The first request after the absolute TTL attempts refresh. A nonblocking writer
lock allows concurrent visitors to use the previous snapshot. Only a complete,
valid, renderable dataset replaces the cache through an atomic rename. Errors
retain the previous snapshot or seed and start retry backoff. Configured API
caches may add their own delay before changes become visible.

Run locally or from the server's terminal:

```sh
php bin/seo.php refresh site.php
php bin/seo.php status site.php
```

The packaged entry supports the same operations. From your deployed build
directory:

```sh
php seo.php refresh
php seo.php status
```

Calling packaged `seo.php` with no CLI argument also forces one refresh. A
refresh reports `refreshed`, its timestamp and any error, and exits nonzero on
failure or lock contention. Status is read-only. There is no public HTTP force
refresh endpoint: requesting `/seo.php` over HTTP returns 404.

Optional cPanel cron, every 15 minutes (replace `<deployment-directory>` with
the directory on your hosting account):

```cron
*/15 * * * * cd <deployment-directory> && php seo.php refresh
```

Use the host's PHP 8.1+ CLI executable with cURL; the website and cron PHP versions
may differ. Static config text changes still require packaging and deployment.
If CLI reports an issuer-certificate error, configure `curl.cainfo` in its
`php.ini` to a maintained CA bundle (or pass `php -d curl.cainfo=/path/to/cacert.pem`).
Keep TLS certificate verification enabled.
The cache stores public source data, not full page responses. HTML responses use
`Cache-Control: private, no-store`, and expose `X-SEO-Generated-At`, `X-SEO-Cache`
and `X-SEO-Refresh`. `enabled` reports cURL/storage capability; confirm a successful
refresh through the CLI result or an advancing timestamp.

## SPA navigation and metadata

The optional browser helper is packaged at `/__seo/seo-client.mjs`. Call
`updateSeo()` after a public SPA route transition, and `markPrivate()` when
entering an account route. See the basic demo's `app.js`. It updates canonical,
language, social metadata and JSON-LD after navigation. Pass `structuredData`
for page-specific schemas; the default is a generic WebPage. Configure asset
paths with your hosting base path when using a subdirectory.

## Deployment and checks

The generated build includes its own PHP runtime, configuration, adapter files,
template, seed and browser helper. Deploy only that build directory; the source
project is not required on the server. Adapter code must use package-relative
imports and bundled files rather than absolute paths to build-machine files.
The basic and catalog examples follow this convention.

Packaging stores the supplied config in `.seo/config-source.php` and creates
`.seo/config.php` to select the bundled template and seed. It resets the source
`cacheDirectory` so the deployed runtime uses storage on its current host.
To choose a persistent cache location, set the server environment variable
`SEO_CACHE_DIRECTORY` to an absolute writable private directory outside the
web root, for both web PHP and cron/CLI. Otherwise the runtime uses an
installation/config-specific directory in that host's system temp directory.
With the default storage, moving a deployment starts with its bundled seed and
a new cache identity.

Apache 2.4 or LiteSpeed needs rewrite support and `.htaccess` overrides. Keep
`.seo/.htaccess` and the root `.htaccess` in FTP/archive uploads. The private
package includes config, seed, the clean template and PHP code; it must not be
downloadable over HTTP. Nginx installations need equivalent private-directory
denial, real asset 404s and a FastCGI fallback to `seo.php` that preserves the
original request URI.

Deploy referenced hashed assets before the new PHP configuration/template and
entry points; retain older hashed assets for clients still using them. Validate
a staged package before activation. This library supplies artifacts, while the
hosting deployment process owns activation and rollback.

```sh
php tests/run.php
php tests/http.php
php tests/standalone.php
```

Or run all three with `composer test` if Composer is installed.

The tests cover cache reuse, TTL, locking, backoff, invalid data, atomic writes,
escaping, locales, dynamic routes, packaging, CLI-compatible entry points and
actual HTTP responses. The standalone suite removes the catalog build inputs,
moves its output and starts fresh PHP processes to verify the packaged CLI,
localized HTML, sitemap and host-specific cache configuration.
`.gitlab-ci.yml` runs the tests on PHP 8.1, 8.3 and 8.5.
The HTTP suite uses the development router; verify Apache/LiteSpeed rules on the
target host. No external API, credentials or JavaScript package install is
needed for the PHP tests.

## License

SEO Framework is free, open-source software released under the [MIT License](LICENSE).
You may use, modify, distribute, sublicense and sell copies, including as part of
commercial projects. Include the copyright and permission notices when
redistributing the software or substantial portions of it, including deployment
packages. The software is provided without warranty. See [LICENSE](LICENSE) for
the full terms.

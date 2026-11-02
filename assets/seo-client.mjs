/** Optional helper: call after your SPA router changes the active public page. */
export function updateSeo({ title, description, canonical, language = 'en', direction = 'ltr', alternates = {}, image, structuredData }) {
  document.title = title;
  document.documentElement.lang = language;
  document.documentElement.dir = direction;
  const meta = (attribute, key, value) => {
    let element = [...document.head.querySelectorAll(`meta[${attribute}]`)].find(node => node.getAttribute(attribute) === key);
    if (!element) { element = document.createElement('meta'); element.setAttribute(attribute, key); document.head.append(element); }
    element.content = value;
  };
  const link = (rel, href, hreflang) => {
    let element = [...document.head.querySelectorAll(`link[rel="${rel}"]`)].find(node => !hreflang || node.hreflang === hreflang);
    if (!element) { element = document.createElement('link'); element.rel = rel; document.head.append(element); }
    element.href = href;
    if (hreflang) { element.hreflang = hreflang; element.setAttribute('data-seo-framework-hreflang', hreflang); }
  };
  meta('name', 'description', description);
  meta('name', 'robots', 'index,follow,max-image-preview:large');
  for (const [key, value] of Object.entries({ 'og:title': title, 'og:description': description, 'og:url': canonical, 'og:type': 'website', 'og:locale': language.replaceAll('-', '_') })) meta('property', key, value);
  for (const [key, value] of Object.entries({ 'twitter:title': title, 'twitter:description': description, 'twitter:card': 'summary_large_image' })) meta('name', key, value);
  if (image) { meta('property', 'og:image', image); meta('name', 'twitter:image', image); }
  else document.head.querySelectorAll('meta[property="og:image"], meta[name="twitter:image"]').forEach(node => node.remove());
  link('canonical', canonical);
  document.head.querySelectorAll('link[data-seo-framework-hreflang]').forEach(node => node.remove());
  for (const [code, href] of Object.entries(alternates)) link('alternate', href, code);
  document.head.querySelectorAll('script[data-seo-framework]').forEach(node => node.remove());
  const schema = structuredData === undefined ? { '@context': 'https://schema.org', '@type': 'WebPage', name: title, description, url: canonical, inLanguage: language } : structuredData;
  if (schema) {
    const script = document.createElement('script'); script.type = 'application/ld+json';
    script.setAttribute('data-seo-framework', ''); script.textContent = JSON.stringify(schema); document.head.append(script);
  }
}

/** Call when entering an account/login route to remove stale public metadata. */
export function markPrivate() {
  let robots = document.head.querySelector('meta[name="robots"]');
  if (!robots) { robots = document.createElement('meta'); robots.name = 'robots'; document.head.append(robots); }
  robots.content = 'noindex,nofollow';
  document.head.querySelectorAll('script[data-seo-framework], link[data-seo-framework-hreflang], link[rel="canonical"], meta[property^="og:"], meta[name^="twitter:"]').forEach(node => node.remove());
}

import { updateSeo } from '/__seo/seo-client.mjs';

// A tiny SPA for demonstrating handoff; no npm packages or framework required.
function render() {
  const language = location.pathname.split('/')[1] === 'ar' ? 'ar' : 'en';
  const about = location.pathname.endsWith('/about');
  const arabic = language === 'ar';
  const title = about ? (arabic ? 'عن الاستوديو' : 'About our studio') : (arabic ? 'نبني أدوات مفيدة' : 'Build something useful');
  const description = about
    ? (arabic ? 'فريق صغير يهتم بالتواصل الواضح والنتائج العملية.' : 'A small team focused on clear communication and practical results.')
    : (arabic ? 'استوديو مستقل لبناء المواقع والأدوات المفيدة.' : 'An independent studio building useful websites and tools.');
  const root = document.getElementById('app');
  root.replaceChildren();
  const wrapper = document.createElement('div'); wrapper.className = 'seo-framework';
  const nav = document.createElement('nav');
  for (const [path, label] of [[`/${language}`, arabic ? 'الرئيسية' : 'Home'], [`/${language}/about`, arabic ? 'من نحن' : 'About'], [arabic ? '/en' : '/ar', arabic ? 'English' : 'العربية']]) {
    const link = document.createElement('a'); link.href = path; link.textContent = label; nav.append(link);
  }
  const heading = document.createElement('h1'); heading.textContent = title;
  const text = document.createElement('p'); text.textContent = description;
  const indicator = document.createElement('p'); indicator.textContent = arabic ? 'التطبيق التفاعلي جاهز.' : 'The interactive application is ready.';
  wrapper.append(nav, heading, text, indicator); root.append(wrapper);
  const suffix = about ? '/about' : '';
  updateSeo({ title: title + ' | Example Studio', description, canonical: `https://example.com/${language}${suffix}`, language, direction: arabic ? 'rtl' : 'ltr',
    alternates: { en: `https://example.com/en${suffix}`, ar: `https://example.com/ar${suffix}`, 'x-default': `https://example.com/en${suffix}` } });
}
document.addEventListener('click', event => {
  const link = event.target.closest('a');
  if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0 || link.origin !== location.origin) return;
  event.preventDefault(); history.pushState({}, '', link.href); render();
});
window.addEventListener('popstate', render);
render();

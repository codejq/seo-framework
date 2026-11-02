/* Progressive enhancements. All guide content and navigation work without JavaScript. */
(() => {
  'use strict';

  document.documentElement.classList.add('js');
  const sidebar = document.getElementById('sidebar');
  const menuButton = document.querySelector('.menu-toggle');
  menuButton.hidden = false;

  function closeMenu() {
    sidebar.classList.remove('open');
    menuButton.setAttribute('aria-expanded', 'false');
  }

  menuButton.addEventListener('click', () => {
    const open = sidebar.classList.toggle('open');
    menuButton.setAttribute('aria-expanded', String(open));
  });
  sidebar.addEventListener('click', event => {
    if (event.target.closest('a')) closeMenu();
  });
  document.addEventListener('click', event => {
    if (!sidebar.contains(event.target) && !menuButton.contains(event.target)) closeMenu();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && sidebar.classList.contains('open')) {
      closeMenu();
      menuButton.focus();
    }
  });
  window.matchMedia('(min-width: 741px)').addEventListener('change', closeMenu);

  const sections = [...document.querySelectorAll('.doc-section')];
  const navLinks = [...document.querySelectorAll('.sidebar nav a, .page-outline nav a')];
  let pendingFrame = false;

  function updateNavigation() {
    const threshold = window.innerWidth <= 740 ? 110 : 130;
    let current = sections[0];
    for (const section of sections) {
      if (section.getBoundingClientRect().top <= threshold) current = section;
    }
    for (const link of navLinks) {
      const active = link.hash === '#' + current.id;
      link.classList.toggle('active', active);
      if (active) link.setAttribute('aria-current', 'location');
      else link.removeAttribute('aria-current');
    }
    pendingFrame = false;
  }

  window.addEventListener('scroll', () => {
    if (!pendingFrame) {
      pendingFrame = true;
      window.requestAnimationFrame(updateNavigation);
    }
  }, { passive: true });
  window.addEventListener('resize', updateNavigation);
  window.addEventListener('hashchange', updateNavigation);
  updateNavigation();

  const toast = document.querySelector('.toast');
  let toastTimer;
  function notify(message) {
    window.clearTimeout(toastTimer);
    toast.textContent = message;
    toast.classList.add('visible');
    toastTimer = window.setTimeout(() => toast.classList.remove('visible'), 2400);
  }

  async function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) {
      try {
        await navigator.clipboard.writeText(value);
        return;
      } catch (_) { /* Try the selection fallback when clipboard permission is unavailable. */ }
    }
    const input = document.createElement('textarea');
    input.value = value;
    input.setAttribute('readonly', '');
    input.setAttribute('aria-label', 'Code to copy');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.append(input);
    input.select();
    const copied = document.execCommand('copy');
    input.remove();
    if (!copied) throw new Error('Clipboard unavailable');
  }

  for (const block of document.querySelectorAll('.code-block')) {
    const code = block.querySelector('pre code');
    const label = block.querySelector('.code-label');
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'copy-button';
    button.textContent = 'Copy';
    button.setAttribute('aria-label', 'Copy ' + label.firstElementChild.textContent.trim());
    label.append(button);
    button.addEventListener('click', async () => {
      try {
        await copyText(code.textContent);
        button.textContent = 'Copied ✓';
        notify('Code copied to clipboard');
        window.setTimeout(() => { button.textContent = 'Copy'; }, 2000);
      } catch (_) {
        notify('Select the code and copy it with your keyboard.');
      }
      button.focus({ preventScroll: true });
    });
  }

  const routePreview = document.querySelector('[data-route-preview]');
  routePreview.hidden = false;
  for (const input of routePreview.querySelectorAll('input[name="preview-language"]')) {
    input.addEventListener('change', () => {
      const language = input.value;
      const arabic = language === 'ar';
      routePreview.querySelector('[data-route-url]').textContent = `https://example.com/${language}/about`;
      routePreview.querySelector('[data-route-language]').textContent = language;
      routePreview.querySelector('[data-route-direction]').textContent = arabic ? 'rtl' : 'ltr';
      const title = routePreview.querySelector('[data-route-title]');
      title.textContent = arabic ? 'عن الاستوديو' : 'About our studio';
      title.lang = language;
      title.dir = arabic ? 'rtl' : 'ltr';
    });
  }

  const dialog = document.querySelector('.search-dialog');
  const searchButton = document.querySelector('[data-search-open]');
  const searchInput = document.getElementById('docs-search');
  const searchResults = document.querySelector('.search-results');
  const searchCount = document.querySelector('.search-count');
  const normalize = value => value.toLocaleLowerCase().replace(/\s+/g, ' ').trim();
  const searchIndex = sections.map(section => ({
    id: section.id,
    title: section.dataset.title,
    keywords: normalize(section.dataset.title + ' ' + section.dataset.keywords),
    text: normalize(section.textContent),
    summary: section.querySelector('p:not(.eyebrow):not(.nav-label)')?.textContent.trim().replace(/\s+/g, ' ') || '',
  }));

  function renderSearch() {
    const query = normalize(searchInput.value);
    const terms = query.split(' ').filter(Boolean);
    const matches = searchIndex
      .map(entry => ({ ...entry, score: terms.reduce((sum, term) => sum + (entry.keywords.includes(term) ? 3 : entry.text.includes(term) ? 1 : 0), 0) }))
      .filter(entry => !query || terms.every(term => entry.keywords.includes(term) || entry.text.includes(term)))
      .sort((a, b) => b.score - a.score);

    searchResults.replaceChildren();
    searchCount.textContent = query ? `${matches.length} ${matches.length === 1 ? 'guide' : 'guides'} found` : 'Browse all guides, or type to search';
    if (!matches.length) {
      const empty = document.createElement('p');
      empty.className = 'search-empty';
      empty.textContent = 'No guides found. Try “pages”, “cache”, “API” or “deploy”.';
      searchResults.append(empty);
    }
    for (const entry of matches) {
      const link = document.createElement('a');
      link.href = '#' + entry.id;
      link.className = 'search-result';
      const title = document.createElement('strong');
      title.textContent = entry.title;
      const description = document.createElement('p');
      description.textContent = entry.summary.length > 140 ? entry.summary.slice(0, 137) + '…' : entry.summary;
      link.append(title, description);
      link.addEventListener('click', () => {
        dialog.close();
        closeMenu();
        const target = document.getElementById(entry.id);
        target.tabIndex = -1;
        target.focus({ preventScroll: true });
      });
      searchResults.append(link);
    }
  }

  function openSearch() {
    if (dialog.open) return;
    renderSearch();
    dialog.showModal();
    searchInput.focus();
  }
  if (typeof dialog.showModal === 'function') {
    searchButton.hidden = false;
    searchButton.addEventListener('click', openSearch);
    searchInput.addEventListener('input', renderSearch);
    dialog.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
        event.preventDefault();
        dialog.close();
      }
    });
    document.addEventListener('keydown', event => {
      const typing = event.target.closest('input, textarea, select, [contenteditable]');
      if ((event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey)
        || ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k')) {
        event.preventDefault();
        openSearch();
      }
    });
    searchInput.addEventListener('keydown', event => {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        searchResults.querySelector('a')?.focus();
      }
      if (event.key === 'Enter') {
        event.preventDefault();
        searchResults.querySelector('a')?.click();
      }
    });
    searchResults.addEventListener('keydown', event => {
      const links = [...searchResults.querySelectorAll('a')];
      const index = links.indexOf(document.activeElement);
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        links[Math.min(index + 1, links.length - 1)]?.focus();
      }
      if (event.key === 'ArrowUp') {
        event.preventDefault();
        if (index <= 0) searchInput.focus();
        else links[index - 1].focus();
      }
    });
    dialog.addEventListener('click', event => {
      const bounds = dialog.getBoundingClientRect();
      if (event.target === dialog && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) dialog.close();
    });
  }
})();

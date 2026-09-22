/*
 * Browser half of the anonymous site sweep -- see _readme/anonymous-site-sweep.md.
 *
 * Paste into the javascript_tool of a tab that is (a) on the site under test and
 * (b) logged out. It defines window.apcSweep, which loads pages in same-origin
 * iframes at a chosen width (so desktop AND phone widths work without resizing
 * the real window, and media queries/fixed positioning behave as at that width).
 *
 *   await apcSweep.pages(['/', '/calendar'], 412)      -> findings per page
 *   await apcSweep.overlays('/', 412)                   -> popup/lightbox checks
 *   await apcSweep.photosFilters('/photos', 412)        -> collapsible-filter check
 *
 * Each returns only the FINDINGS (an empty `issues` array means the page passed)
 * so the output stays small. Nothing is clicked except overlay triggers, and no
 * form is ever submitted.
 */
window.apcSweep = (() => {
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  async function load(path, width, height = 860) {
    document.getElementById('apc-sweep-frame')?.remove();
    const f = document.createElement('iframe');
    f.id = 'apc-sweep-frame';
    f.style.cssText = `position:fixed;left:0;top:0;width:${width}px;height:${height}px;border:0;background:#fff;z-index:99999`;
    document.body.appendChild(f);
    await new Promise((res) => { f.onload = res; f.src = path; });
    await sleep(1200);
    const w = f.contentWindow;
    const d = w.document;
    // Trigger lazy images: scroll through, then back to the top.
    for (let y = 0; y < d.documentElement.scrollHeight; y += height * 0.8) {
      w.scrollTo(0, y);
      await sleep(80);
    }
    w.scrollTo(0, 0);
    await sleep(500);
    return { f, w, d };
  }

  // Strip origin and query string: the browser tool redacts any output containing
  // a query string, which would hide the very finding we want to read.
  const short = (u) => String(u).replace(location.origin, '').split('?')[0].slice(-70);
  const rect = (e) => {
    const b = e.getBoundingClientRect();
    return [Math.round(b.left), Math.round(b.top), Math.round(b.right), Math.round(b.bottom)];
  };
  const visible = (w, e) => {
    const cs = w.getComputedStyle(e);
    const b = e.getBoundingClientRect();
    return cs.display !== 'none' && cs.visibility !== 'hidden' && b.width > 0 && b.height > 0;
  };
  const label = (e) => `${e.tagName.toLowerCase()}${e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\s+/).slice(0, 2).join('.') : ''}${e.textContent ? ' "' + e.textContent.trim().replace(/\s+/g, ' ').slice(0, 30) + '"' : ''}`;

  async function pages(paths, width) {
    const out = [];
    for (const path of paths) {
      const issues = [];
      let ctx;
      try {
        ctx = await load(path, width);
      } catch (e) {
        out.push({ path, width, issues: ['failed to load: ' + e] });
        continue;
      }
      const { w, d } = ctx;
      const vw = w.innerWidth;
      const vh = w.innerHeight;
      const root = d.documentElement;

      if (d.body.classList.contains('user-logged-in')) issues.push('NOT ANONYMOUS -- log out first');
      if (root.scrollWidth > vw + 1) {
        const off = [...d.body.querySelectorAll('*')].filter((e) => {
          const b = e.getBoundingClientRect();
          if (b.right <= vw + 1 || b.width === 0 || !visible(w, e)) return false;
          for (let p = e.parentElement; p && p !== d.body; p = p.parentElement) {
            if (/(hidden|clip|auto|scroll)/.test(w.getComputedStyle(p).overflowX)) return false;
          }
          return true;
        });
        issues.push(`HORIZONTAL SCROLL: page is ${root.scrollWidth}px wide in a ${vw}px viewport; widest: ${off.slice(0, 3).map((e) => label(e) + ' right=' + Math.round(e.getBoundingClientRect().right)).join(' | ')}`);
      }
      const h1s = d.querySelectorAll('h1').length;
      if (h1s !== 1) issues.push(`${h1s} <h1> elements (expected 1)`);
      if (!d.title.trim()) issues.push('empty <title>');

      const broken = [...d.images].filter((i) => visible(w, i) && i.complete && i.naturalWidth === 0);
      if (broken.length) issues.push(`BROKEN IMAGES (${broken.length}): ${broken.slice(0, 3).map((i) => short(i.currentSrc)).join(', ')}`);
      // Leaflet map tiles overhang their (clipped) map container by design.
      const wide = [...d.images].filter((i) => visible(w, i) && !i.closest('.leaflet-container') && i.getBoundingClientRect().right > vw + 1);
      if (wide.length) issues.push(`IMAGES WIDER THAN VIEWPORT (${wide.length}): ${wide.slice(0, 3).map((i) => short(i.currentSrc)).join(', ')}`);

      // Tap targets (WCAG 2.2 minimum 24x24). Inline links inside running text are exempt.
      const small = [...d.querySelectorAll('button, input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea, summary, a')].filter((e) => {
        if (!visible(w, e)) return false;
        const cs = w.getComputedStyle(e);
        if (e.tagName === 'A' && cs.display === 'inline') return false;
        if (e.closest('.visually-hidden, .skip-link, [hidden]')) return false;
        const b = e.getBoundingClientRect();
        return b.width < 24 || b.height < 24;
      });
      if (small.length) issues.push(`SMALL TAP TARGETS (${small.length}, <24px): ${small.slice(0, 4).map((e) => label(e) + ' ' + Math.round(e.getBoundingClientRect().width) + 'x' + Math.round(e.getBoundingClientRect().height)).join(' | ')}`);

      const tiny = [...d.querySelectorAll('p, li, a, span, td, label, h1, h2, h3, h4, dd, dt, figcaption')].filter((e) => {
        if (!visible(w, e) || ![...e.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim())) return false;
        return parseFloat(w.getComputedStyle(e).fontSize) < 12;
      });
      if (tiny.length) issues.push(`TEXT < 12px (${tiny.length}): ${tiny.slice(0, 3).map(label).join(' | ')}`);

      // Sticky/fixed bars eating the screen (a real problem on phones).
      const bars = [...d.body.querySelectorAll('*')].filter((e) => {
        const cs = w.getComputedStyle(e);
        return (cs.position === 'fixed' || cs.position === 'sticky') && visible(w, e) && e.getBoundingClientRect().top <= 1 && !e.closest('.ui-dialog, .apc-lightbox, .apc-photo-lightbox');
      });
      const barHeight = bars.reduce((sum, e) => sum + e.getBoundingClientRect().height, 0);
      if (barHeight > vh * 0.3) issues.push(`STICKY/FIXED bars at top take ${Math.round(barHeight)}px of ${vh}px viewport: ${bars.map(label).join(' | ')}`);

      out.push({ path, width, docHeight: root.scrollHeight, issues });
    }
    document.getElementById('apc-sweep-frame')?.remove();
    return out;
  }

  // Does an overlay fit the screen with a reachable close control?
  function fit(w, name, box, closeBtn, extra = []) {
    const issues = [];
    const vw = w.innerWidth;
    const vh = w.innerHeight;
    const b = box.getBoundingClientRect();
    if (b.left < -1 || b.right > vw + 1) issues.push(`${name} spills horizontally: ${rect(box)} in ${vw}px viewport`);
    if (b.top < -1 || b.bottom > vh + 1) issues.push(`${name} spills vertically: ${rect(box)} in ${vh}px viewport`);
    if (!closeBtn) issues.push(`${name}: no close button found`);
    else {
      const c = closeBtn.getBoundingClientRect();
      if (c.left < 0 || c.right > vw || c.top < 0 || c.bottom > vh) issues.push(`${name}: close button OFF-SCREEN ${rect(closeBtn)}`);
      if (c.width < 24 || c.height < 24) issues.push(`${name}: close button only ${Math.round(c.width)}x${Math.round(c.height)}`);
    }
    for (const e of extra) {
      const r = e.getBoundingClientRect();
      if (r.right > vw + 1) issues.push(`${name}: <${e.tagName.toLowerCase()}> content runs off the right edge (${Math.round(r.right)} > ${vw})`);
    }
    return issues;
  }

  // Opens each overlay reachable from `path` and checks: fits, close control
  // reachable, and that Escape / backdrop click / the back gesture all close it
  // and leave no stray history entry.
  async function overlays(path, width) {
    const { w, d } = await load(path, width);
    const results = [];
    const state = () => w.history.state;
    const dialogOpen = () => { const e = d.querySelector('.ui-dialog'); return !!e && w.getComputedStyle(e).display !== 'none'; };

    async function closeChecks(name, isOpen, escape, backdrop) {
      const issues = [];
      w.history.back(); await sleep(700);
      if (isOpen()) issues.push(`${name}: back gesture did not close it`);
      if (state() && state().apcOverlay) issues.push(`${name}: history entry left behind after back`);
      return { escape, backdrop, issues };
    }

    // Event popup (homepage cards etc.)
    const card = d.querySelector('a.apc-front__card-overlay, a.use-ajax[data-dialog-type=modal]');
    if (card) {
      const issues = [];
      card.click();
      for (let i = 0; i < 40 && !d.querySelector('.ui-dialog .apc-event-popup'); i++) await sleep(100);
      await sleep(600);
      const dlg = d.querySelector('.ui-dialog');
      if (!dlg) issues.push('event popup: never opened');
      else {
        issues.push(...fit(w, 'event popup', dlg, dlg.querySelector('.ui-dialog-titlebar-close'), [...dlg.querySelectorAll('img, .apc-event-popup__actions, .button')]));
        // Escape
        d.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        w.jQuery(dlg.querySelector('.ui-dialog-content')).trigger(w.jQuery.Event('keydown', { keyCode: 27, which: 27 }));
        await sleep(500);
        if (dialogOpen()) issues.push('event popup: Escape did not close it');
        if (state() && state().apcOverlay) issues.push('event popup: history entry left after Escape');
        // Backdrop click (after the anti-double-click delay)
        card.click();
        for (let i = 0; i < 40 && !dialogOpen(); i++) await sleep(100);
        await sleep(700);
        const bd = d.querySelector('.ui-widget-overlay');
        if (bd) {
          bd.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));
          bd.dispatchEvent(new MouseEvent('click', { bubbles: true }));
          await sleep(600);
          if (dialogOpen()) issues.push('event popup: backdrop click did not close it');
          if (state() && state().apcOverlay) issues.push('event popup: history entry left after backdrop click');
        } else issues.push('event popup: no backdrop element');
        // Back gesture
        card.click();
        for (let i = 0; i < 40 && !dialogOpen(); i++) await sleep(100);
        await sleep(700);
        w.history.back(); await sleep(700);
        if (dialogOpen()) issues.push('event popup: back gesture did not close it');
        if (state() && state().apcOverlay) issues.push('event popup: history entry left after back');
      }
      results.push({ overlay: 'event popup', issues });
    }

    // Single-image lightbox (hero placard, wall photos)
    for (const [name, sel] of [['hero lightbox', '[data-apc-lightbox-trigger]'], ['wall lightbox', '[data-apc-wall-lightbox] .apc-photo-gallery__photo']]) {
      const trigger = d.querySelector(sel);
      if (!trigger) continue;
      const issues = [];
      trigger.click(); await sleep(600);
      const ov = d.querySelector('.apc-lightbox');
      if (!ov || ov.hidden) issues.push(`${name}: did not open`);
      else {
        issues.push(...fit(w, name, ov.querySelector('.apc-lightbox__viewport'), ov.querySelector('.apc-lightbox__close'), [ov.querySelector('img')]));
        ov.querySelector('.apc-lightbox__close').click(); await sleep(600);
        if (!ov.hidden) issues.push(`${name}: close button did not close it`);
        if (state() && state().apcOverlay) issues.push(`${name}: history entry left after close`);
        trigger.click(); await sleep(500);
        w.history.back(); await sleep(700);
        if (!ov.hidden) issues.push(`${name}: back gesture did not close it`);
        if (state() && state().apcOverlay) issues.push(`${name}: history entry left after back`);
      }
      results.push({ overlay: name, issues });
    }

    // Rich lightbox (carousels, /photos grid)
    const rich = d.querySelector('[data-apc-photo-grid] .apc-photo-gallery__photo, [data-apc-photo-lightbox] .apc-photo-gallery__photo');
    if (rich) {
      const issues = [];
      rich.click(); await sleep(700);
      const ov = d.querySelector('.apc-photo-lightbox');
      if (!ov || ov.hidden) issues.push('photo lightbox: did not open');
      else {
        issues.push(...fit(w, 'photo lightbox', ov, ov.querySelector('.apc-photo-lightbox__close'), [ov.querySelector('img')].filter(Boolean)));
        w.history.back(); await sleep(700);
        if (!ov.hidden) issues.push('photo lightbox: back gesture did not close it');
        if (state() && state().apcOverlay) issues.push('photo lightbox: history entry left after back');
      }
      results.push({ overlay: 'photo lightbox', issues });
    }

    document.getElementById('apc-sweep-frame')?.remove();
    return { path, width, results };
  }

  // /photos: filters collapse below 62.5rem (1000px), always open above it.
  async function photosFilters(path, width) {
    const { w, d } = await load(path, width);
    const issues = [];
    const det = d.querySelector('[data-apc-photo-filters]');
    if (!det) issues.push('filters <details> not found');
    else {
      const sum = det.querySelector('summary');
      const desktop = width >= 1000;
      if (det.open !== desktop) issues.push(`filters ${det.open ? 'open' : 'closed'} at ${width}px (expected ${desktop ? 'open' : 'closed'})`);
      const toggleVisible = w.getComputedStyle(sum).display !== 'none';
      if (toggleVisible === desktop) issues.push(`toggle ${toggleVisible ? 'visible' : 'hidden'} at ${width}px (expected ${desktop ? 'hidden' : 'visible'})`);
      if (!desktop) {
        sum.click(); await sleep(300);
        if (!det.open) issues.push('clicking the toggle did not open the filters');
        const box = det.querySelector('input[type=checkbox], input[type=radio]');
        box.click(); await sleep(2500);
        const again = d.querySelector('[data-apc-photo-filters]');
        if (!again.open) issues.push('filters collapsed after an AJAX filter change');
        if (!/\(\d+\)/.test(again.querySelector('[data-apc-filter-count]').textContent)) issues.push('no active-filter count shown after ticking a filter');
      }
    }
    document.getElementById('apc-sweep-frame')?.remove();
    return { path, width, issues };
  }

  return { pages, overlays, photosFilters, load };
})();
'apcSweep ready';

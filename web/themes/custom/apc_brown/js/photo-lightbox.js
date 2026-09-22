/**
 * @file
 * Full-size, zoomable lightbox for photo_gallery carousel blocks.
 *
 * Distinct from apc_brown/lightbox (the simple img-only overlay used on
 * event/location photo galleries) -- this one needs the original uncropped
 * image, a caption/credit panel, zoom, and prev/next navigation that stays
 * in sync with the carousel behind it, none of which the generic one does.
 *
 * Opened by photo-carousel.js via document.body.__apcPhotoLightboxOpen(),
 * passed a small controller object rather than a single image: {
 *   current(): {nid, fallbackSrc, fallbackAlt},
 *   next(): same shape, for the next slide,
 *   prev(): same shape, for the previous slide,
 *   pause(), resume(): the carousel's own auto-advance timer controls, so
 *     it doesn't keep cycling underneath an open lightbox.
 * }
 * The full image URL, caption and credit come from
 * drupalSettings.apcPhotoGallery[nid] (built in
 * apc_brown_preprocess_views_view()) -- the controller only supplies a
 * fallback (the already-loaded card image) for the rare case a nid isn't in
 * that map.
 */

((Drupal, once, drupalSettings) => {
  const ZOOM_STEPS = [1, 1.5, 2, 3, 4.5];
  const URL_PATTERN = /(https?:\/\/[^\s<]+)/gi;
  // Same breakpoint the rest of the theme uses for "desktop" layout
  // (detail-page.css, upcoming-events.css). Below it a zoomed-in default
  // would just mean more scrolling on an already-small screen, so mobile
  // keeps opening at plain fit.
  const DESKTOP_QUERY = window.matchMedia('(min-width: 62.5rem)');
  // Index into ZOOM_STEPS a desktop viewer lands on by default -- picked to
  // match "click + twice" (ZOOM_STEPS[2] === 2x), the size requested.
  const DEFAULT_DESKTOP_ZOOM_INDEX = 2;

  // Renders caption text into `el`, wrapping bare http(s) URLs in real <a>
  // links that open in a new tab, while leaving everything else as plain
  // text. Built with DOM nodes (createTextNode/createElement) rather than
  // innerHTML, so nothing typed into a caption can ever be interpreted as
  // markup -- only URLs this function itself finds become links.
  const renderCaptionLinks = (el, text) => {
    el.textContent = '';
    if (!text) {
      return;
    }
    let lastIndex = 0;
    let match;
    URL_PATTERN.lastIndex = 0;
    while ((match = URL_PATTERN.exec(text)) !== null) {
      if (match.index > lastIndex) {
        el.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
      }
      // Trailing punctuation right before the end of the match is usually
      // sentence punctuation, not part of the URL (e.g. "see https://x.org.").
      let url = match[0];
      const trailing = url.match(/[.,;:!?)\]}'"]+$/);
      const suffix = trailing ? trailing[0] : '';
      if (suffix) {
        url = url.slice(0, -suffix.length);
      }
      const link = document.createElement('a');
      link.href = url;
      link.textContent = url;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      el.appendChild(link);
      if (suffix) {
        el.appendChild(document.createTextNode(suffix));
      }
      lastIndex = match.index + match[0].length;
    }
    if (lastIndex < text.length) {
      el.appendChild(document.createTextNode(text.slice(lastIndex)));
    }
  };

  once('apc-photo-lightbox', document.body).forEach(() => {
    const overlay = document.createElement('div');
    overlay.className = 'apc-photo-lightbox';
    overlay.hidden = true;
    overlay.innerHTML = `
      <div class="apc-photo-lightbox__stage">
        <button type="button" class="apc-photo-lightbox__close" aria-label="${Drupal.t('Close')}">&times;</button>
        <button type="button" class="apc-photo-lightbox__nav apc-photo-lightbox__nav--prev" aria-label="${Drupal.t('Previous photo')}">&#8249;</button>
        <button type="button" class="apc-photo-lightbox__nav apc-photo-lightbox__nav--next" aria-label="${Drupal.t('Next photo')}">&#8250;</button>
        <div class="apc-photo-lightbox__viewport">
          <img class="apc-photo-lightbox__img" alt="">
        </div>
        <div class="apc-photo-lightbox__zoom">
          <button type="button" class="apc-photo-lightbox__zoom-out" aria-label="${Drupal.t('Zoom out')}">&minus;</button>
          <button type="button" class="apc-photo-lightbox__zoom-reset">${Drupal.t('Fit')}</button>
          <button type="button" class="apc-photo-lightbox__zoom-in" aria-label="${Drupal.t('Zoom in')}">&plus;</button>
        </div>
        <div class="apc-photo-lightbox__info">
          <p class="apc-photo-lightbox__caption"></p>
          <p class="apc-photo-lightbox__credit"></p>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);

    const stage = overlay.querySelector('.apc-photo-lightbox__stage');
    const viewport = overlay.querySelector('.apc-photo-lightbox__viewport');
    const img = overlay.querySelector('.apc-photo-lightbox__img');
    const captionEl = overlay.querySelector('.apc-photo-lightbox__caption');
    const creditEl = overlay.querySelector('.apc-photo-lightbox__credit');
    const prevBtn = overlay.querySelector('.apc-photo-lightbox__nav--prev');
    const nextBtn = overlay.querySelector('.apc-photo-lightbox__nav--next');

    let controller = null;
    let lastFocused = null;
    let zoomIndex = 0;
    let fitWidth = 0;

    const applyZoom = () => {
      if (zoomIndex === 0 || !fitWidth) {
        img.style.width = '';
        img.style.maxWidth = '';
        img.style.height = '';
        img.style.maxHeight = '';
        return;
      }
      // Both max-* caps have to be lifted, not just max-width: the CSS caps
      // the image at max-height:65vh, which would stop a portrait photo from
      // growing past its fit height no matter what width we set.
      img.style.maxWidth = 'none';
      img.style.maxHeight = 'none';
      img.style.height = 'auto';
      img.style.width = `${Math.round(fitWidth * ZOOM_STEPS[zoomIndex])}px`;
    };

    // Measured lazily, the first time the user actually zooms: at that instant
    // the image is guaranteed on screen at its fit size, so its rendered width
    // is the correct baseline for the zoom multiples. Doing it on img.onload
    // instead was the original bug -- onload can fire before layout, or not at
    // all for a cached image, leaving fitWidth 0 and applyZoom a no-op.
    const captureFit = () => {
      if (!fitWidth) {
        fitWidth = img.getBoundingClientRect().width;
      }
    };

    const zoomIn = () => {
      captureFit();
      if (zoomIndex < ZOOM_STEPS.length - 1) {
        zoomIndex += 1;
        applyZoom();
      }
    };
    const zoomOut = () => {
      if (zoomIndex > 0) {
        zoomIndex -= 1;
        applyZoom();
      }
    };
    const zoomReset = () => {
      zoomIndex = 0;
      applyZoom();
      viewport.scrollTo(0, 0);
    };

    // Once zoomed past the viewport, the image's own auto margins (centering
    // it at fit size) resolve to 0 -- CSS's normal behavior once a box no
    // longer fits its container -- so the scrollable area is left-aligned
    // to the image's top-left corner by default. Confirmed live: with no
    // correction, the default zoom below just showed whatever happened to
    // be in that top-left corner, unrelated to the photo's actual subject
    // (worst on a tall multi-panel photo, a common shape for a submitted
    // screenshot/meme). Scrolls so the photo's own focal point --
    // apcPhotoGallery[nid].focalPoint, the same point every other
    // focal-point-aware image style on this site crops around -- lands in
    // the center of the viewport instead.
    const centerOnFocalPoint = (focalPoint) => {
      if (!focalPoint) {
        return;
      }
      const imgRect = img.getBoundingClientRect();
      const targetX = (focalPoint.x / 100) * imgRect.width - viewport.clientWidth / 2;
      const targetY = (focalPoint.y / 100) * imgRect.height - viewport.clientHeight / 2;
      viewport.scrollLeft = Math.max(0, Math.min(targetX, viewport.scrollWidth - viewport.clientWidth));
      viewport.scrollTop = Math.max(0, Math.min(targetY, viewport.scrollHeight - viewport.clientHeight));
    };

    const render = (data) => {
      if (!data) {
        return;
      }
      const meta = (drupalSettings.apcPhotoGallery && drupalSettings.apcPhotoGallery[data.nid]) || null;

      // Reset the measured fit width so the next zoom re-measures for this
      // image rather than reusing the previous slide's baseline.
      fitWidth = 0;
      zoomReset();
      img.src = (meta && meta.full) || data.fallbackSrc;
      img.alt = (meta && meta.alt) || data.fallbackAlt || '';

      // Desktop opens a bit zoomed in by default (see DEFAULT_DESKTOP_ZOOM_INDEX
      // above) rather than at plain fit. This has to wait for the *new*
      // image to actually be loaded and laid out before measuring fitWidth
      // -- captureFit()'s own comment already flags why a plain img.onload
      // isn't reliable here (fires before layout, or not at all for a
      // cached image); double requestAnimationFrame after either the load
      // event or an already-cached image (img.complete) covers both.
      if (DESKTOP_QUERY.matches) {
        const applyDefaultZoom = () => {
          captureFit();
          zoomIndex = Math.min(DEFAULT_DESKTOP_ZOOM_INDEX, ZOOM_STEPS.length - 1);
          applyZoom();
          centerOnFocalPoint(meta && meta.focalPoint);
        };
        const afterLayout = () => requestAnimationFrame(() => requestAnimationFrame(applyDefaultZoom));
        if (img.complete && img.naturalWidth) {
          afterLayout();
        }
        else {
          img.addEventListener('load', afterLayout, { once: true });
        }
      }

      const captionText = (meta && meta.caption) || '';
      renderCaptionLinks(captionEl, captionText);
      captionEl.hidden = !captionText;
      if (meta && meta.credit) {
        creditEl.textContent = Drupal.t('Photo by @name', { '@name': meta.credit });
        creditEl.hidden = false;
      }
      else {
        creditEl.textContent = '';
        creditEl.hidden = true;
      }
    };

    const close = () => {
      overlay.hidden = true;
      img.src = '';
      if (controller) {
        controller.resume();
      }
      controller = null;
      if (lastFocused) {
        lastFocused.focus();
      }
    };

    const open = (openController, trigger) => {
      controller = openController;
      lastFocused = trigger;
      controller.pause();
      render(controller.current());
      overlay.hidden = false;
      overlay.querySelector('.apc-photo-lightbox__close').focus();
      // One history entry per open, so the back gesture closes this rather
      // than leaving the page (see overlay-history.js).
      Drupal.apcOverlayHistory.push(close);
    };

    // User-initiated closes go through dismiss() so the history entry open()
    // pushed is rewound; popstate calls close() directly.
    const dismiss = () => Drupal.apcOverlayHistory.dismiss(close);

    prevBtn.addEventListener('click', () => {
      if (controller) {
        render(controller.prev());
      }
    });
    nextBtn.addEventListener('click', () => {
      if (controller) {
        render(controller.next());
      }
    });

    overlay.querySelector('.apc-photo-lightbox__zoom-in').addEventListener('click', zoomIn);
    overlay.querySelector('.apc-photo-lightbox__zoom-out').addEventListener('click', zoomOut);
    overlay.querySelector('.apc-photo-lightbox__zoom-reset').addEventListener('click', zoomReset);

    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) {
        dismiss();
      }
    });
    overlay.querySelector('.apc-photo-lightbox__close').addEventListener('click', dismiss);

    document.addEventListener('keydown', (event) => {
      if (overlay.hidden) {
        return;
      }
      if (event.key === 'Escape') {
        dismiss();
      }
      else if (event.key === 'ArrowLeft' && controller) {
        render(controller.prev());
      }
      else if (event.key === 'ArrowRight' && controller) {
        render(controller.next());
      }
    });

    document.body.__apcPhotoLightboxOpen = open;
  });

  // Reads a slide/grid item's lightbox lookup data. The nid comes from the
  // photo link's own data-photo-nid attribute (apc_brown_preprocess_views_view_field()
  // -- not parsed from the href, which is a /photos/[title] alias since
  // pathauto.pattern.community_photos and carries no nid at all); the card
  // image is the fallback if that nid isn't in drupalSettings.apcPhotoGallery
  // for some reason.
  const itemData = (item) => {
    const link = item.querySelector('.apc-photo-gallery__photo');
    const img = item.querySelector('img');
    return {
      nid: link ? (link.dataset.photoNid || null) : null,
      fallbackSrc: img ? img.src : '',
      fallbackAlt: img ? img.alt : '',
    };
  };

  // The /photos browse grid (view display page_1, marked [data-apc-photo-grid]
  // in apc_brown_preprocess_views_view) opens the same lightbox as the
  // carousels rather than navigating to the bare node view. Unlike a carousel
  // there's no auto-advance timer to pause, and prev/next simply walk the grid
  // in DOM order (wrapping within the current page of results). The caption
  // link is deliberately left alone -- it still goes to the node page, giving
  // a way through to the full record.
  Drupal.behaviors.apcPhotoGrid = {
    attach(context) {
      once('apc-photo-grid', '[data-apc-photo-grid]', context).forEach((grid) => {
        const items = Array.from(grid.querySelectorAll('.apc-photo-gallery__item'));
        if (!items.length) {
          return;
        }

        grid.addEventListener('click', (event) => {
          // Core renders an admin's contextual-links pencil *inside* the
          // photo's <a>, so any click on it would otherwise either open the
          // lightbox or follow that wrapping link. A click on a real menu
          // item (Edit/Delete, inside .contextual-links) must navigate, so
          // leave it alone. A click on the pencil trigger itself must not
          // follow the wrapping link -- cancel that default so the menu can
          // toggle -- and must not open the lightbox.
          if (event.target.closest('.contextual')) {
            if (!event.target.closest('.contextual-links')) {
              event.preventDefault();
            }
            return;
          }
          const trigger = event.target.closest('.apc-photo-gallery__photo');
          if (!trigger || !document.body.__apcPhotoLightboxOpen) {
            return;
          }
          let index = items.indexOf(trigger.closest('.apc-photo-gallery__item'));
          if (index < 0) {
            return;
          }
          event.preventDefault();

          const controller = {
            current: () => itemData(items[index]),
            next: () => {
              index = (index + 1) % items.length;
              return itemData(items[index]);
            },
            prev: () => {
              index = (index - 1 + items.length) % items.length;
              return itemData(items[index]);
            },
            pause: () => {},
            resume: () => {},
          };
          document.body.__apcPhotoLightboxOpen(controller, trigger);
        });
      });
    },
  };
})(Drupal, once, drupalSettings);

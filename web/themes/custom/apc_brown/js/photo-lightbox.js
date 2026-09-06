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

      captionEl.textContent = (meta && meta.caption) || '';
      captionEl.hidden = !captionEl.textContent;
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
    };

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
        close();
      }
    });
    overlay.querySelector('.apc-photo-lightbox__close').addEventListener('click', close);

    document.addEventListener('keydown', (event) => {
      if (overlay.hidden) {
        return;
      }
      if (event.key === 'Escape') {
        close();
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

  // Reads a slide/grid item's lightbox lookup data. The nid is already in the
  // photo's own link (views.view.photo_gallery links field_photo_image to
  // node/[nid]); the card image is the fallback if that nid isn't in
  // drupalSettings.apcPhotoGallery for some reason.
  const itemData = (item) => {
    const link = item.querySelector('.apc-photo-gallery__photo');
    const img = item.querySelector('img');
    const match = link ? link.getAttribute('href').match(/(\d+)\/?$/) : null;
    return {
      nid: match ? match[1] : null,
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

/**
 * @file
 * Modal lightbox for location photos -- embedded directly in the event
 * detail page, and (via field--field-location-image--compact-card.html.twig)
 * in the calendar event popup's location card.
 */

((Drupal, once) => {
  const MIN_SCALE = 1;
  const MAX_SCALE = 4;
  const SCALE_STEP = 0.5;

  Drupal.behaviors.apcLightbox = {
    attach(context) {
      // once(id, document.body) -- not the 'body' *selector* form, and not
      // scoped to `context` -- because this is a page-level singleton that
      // must get created exactly once regardless of how attach() was
      // triggered. The popup's dialog markup arrives via AJAX with `context`
      // set to just the inserted fragment, which has no <body> descendant of
      // its own; a selector lookup for 'body' against that context always
      // comes back empty, so the overlay (and window.__apcLightboxOpen)
      // would silently never get created there. Passing the element
      // directly sidesteps context entirely.
      once('apc-lightbox', document.body).forEach(() => {
        const overlay = document.createElement('div');
        overlay.className = 'apc-lightbox';
        overlay.hidden = true;
        overlay.innerHTML =
          '<button type="button" class="apc-lightbox__close" aria-label="Close">&times;</button>' +
          '<div class="apc-lightbox__viewport">' +
          '<img class="apc-lightbox__img apc-lightbox__img--transition" alt="">' +
          '</div>' +
          '<div class="apc-lightbox__zoom">' +
          '<button type="button" class="apc-lightbox__zoom-button" data-zoom-out aria-label="Zoom out">−</button>' +
          '<button type="button" class="apc-lightbox__zoom-button" data-zoom-in aria-label="Zoom in">+</button>' +
          '</div>';
        document.body.appendChild(overlay);

        const viewport = overlay.querySelector('.apc-lightbox__viewport');
        const img = overlay.querySelector('.apc-lightbox__img');
        const zoomInButton = overlay.querySelector('[data-zoom-in]');
        const zoomOutButton = overlay.querySelector('[data-zoom-out]');
        let lastFocused = null;

        let scale = MIN_SCALE;
        let translateX = 0;
        let translateY = 0;
        let dragging = false;
        let dragStartX = 0;
        let dragStartY = 0;
        let dragOriginX = 0;
        let dragOriginY = 0;

        // Keeps at least part of the image inside the viewport at any zoom
        // level, rather than letting a pan drift it entirely off-screen with
        // no visible way back short of the zoom-out button.
        function clamp(value, limit) {
          return Math.max(-limit, Math.min(limit, value));
        }

        function applyTransform() {
          const maxOffsetX = (viewport.clientWidth * (scale - 1)) / 2;
          const maxOffsetY = (viewport.clientHeight * (scale - 1)) / 2;
          translateX = clamp(translateX, maxOffsetX);
          translateY = clamp(translateY, maxOffsetY);
          img.style.transform = `translate(${translateX}px, ${translateY}px) scale(${scale})`;
          zoomOutButton.disabled = scale <= MIN_SCALE;
          zoomInButton.disabled = scale >= MAX_SCALE;
          img.style.cursor = scale > MIN_SCALE ? 'grab' : 'default';
        }

        function resetZoom() {
          scale = MIN_SCALE;
          translateX = 0;
          translateY = 0;
          applyTransform();
        }

        function setScale(newScale) {
          scale = Math.max(MIN_SCALE, Math.min(MAX_SCALE, newScale));
          if (scale === MIN_SCALE) {
            translateX = 0;
            translateY = 0;
          }
          applyTransform();
        }

        const close = () => {
          overlay.hidden = true;
          img.src = '';
          resetZoom();
          if (lastFocused) {
            lastFocused.focus();
          }
        };

        const open = (src, alt, trigger) => {
          lastFocused = trigger;
          img.src = src;
          img.alt = alt || '';
          resetZoom();
          overlay.hidden = false;
          overlay.querySelector('.apc-lightbox__close').focus();
          // One history entry per open, so the back gesture closes this
          // rather than leaving the page (see overlay-history.js).
          Drupal.apcOverlayHistory.push(close);
        };

        // User-initiated closes go through dismiss() so the history entry
        // open() pushed is rewound; popstate calls close() directly.
        const dismiss = () => Drupal.apcOverlayHistory.dismiss(close);

        overlay.addEventListener('click', (event) => {
          if (event.target === overlay) {
            dismiss();
          }
        });
        overlay.querySelector('.apc-lightbox__close').addEventListener('click', dismiss);
        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && !overlay.hidden) {
            dismiss();
          }
        });

        zoomInButton.addEventListener('click', () => {
          img.classList.add('apc-lightbox__img--transition');
          setScale(scale + SCALE_STEP);
        });
        zoomOutButton.addEventListener('click', () => {
          img.classList.add('apc-lightbox__img--transition');
          setScale(scale - SCALE_STEP);
        });

        // Double-click/tap toggles between fit (1x) and a comfortable
        // zoomed-in level -- the fastest way in for anyone without a scroll
        // wheel (i.e. touch).
        img.addEventListener('dblclick', () => {
          img.classList.add('apc-lightbox__img--transition');
          setScale(scale > MIN_SCALE ? MIN_SCALE : 2.5);
        });

        // Scroll pans rather than zooms -- a trackpad/wheel is how most
        // people expect to move around an already-zoomed image (the zoom
        // buttons and double-click above are the dedicated way to change
        // the zoom level instead). No-op at fit size: applyTransform()
        // clamps translate to 0 there, since there is nothing to pan yet.
        viewport.addEventListener('wheel', (event) => {
          if (scale <= MIN_SCALE) {
            return;
          }
          event.preventDefault();
          img.classList.remove('apc-lightbox__img--transition');
          translateX -= event.deltaX;
          translateY -= event.deltaY;
          applyTransform();
        }, { passive: false });

        // Plain pointer events (not drag-and-drop) so the same code handles
        // mouse and touch alike; only active once zoomed in, since there is
        // nothing to pan at fit size.
        img.addEventListener('pointerdown', (event) => {
          if (scale <= MIN_SCALE) {
            return;
          }
          dragging = true;
          dragStartX = event.clientX;
          dragStartY = event.clientY;
          dragOriginX = translateX;
          dragOriginY = translateY;
          img.classList.remove('apc-lightbox__img--transition');
          img.classList.add('apc-lightbox__img--dragging');
          img.setPointerCapture(event.pointerId);
        });
        img.addEventListener('pointermove', (event) => {
          if (!dragging) {
            return;
          }
          translateX = dragOriginX + (event.clientX - dragStartX);
          translateY = dragOriginY + (event.clientY - dragStartY);
          applyTransform();
        });
        const endDrag = () => {
          dragging = false;
          img.classList.add('apc-lightbox__img--transition');
          img.classList.remove('apc-lightbox__img--dragging');
        };
        img.addEventListener('pointerup', endDrag);
        img.addEventListener('pointercancel', endDrag);

        document.body.__apcLightboxOpen = open;
      });

      once('apc-lightbox-trigger', '[data-apc-lightbox-trigger]', context).forEach((trigger) => {
        trigger.addEventListener('click', () => {
          if (document.body.__apcLightboxOpen) {
            document.body.__apcLightboxOpen(trigger.dataset.full, trigger.dataset.alt, trigger);
          }
        });
      });
    },
  };
})(Drupal, once);

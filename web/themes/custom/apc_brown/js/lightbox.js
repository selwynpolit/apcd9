/**
 * @file
 * Modal lightbox for location photos -- embedded directly in the event
 * detail page, and (via field--field-location-image--compact-card.html.twig)
 * in the calendar event popup's location card.
 */

((Drupal, once) => {
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
          '<img class="apc-lightbox__img" alt="">';
        document.body.appendChild(overlay);

        const img = overlay.querySelector('.apc-lightbox__img');
        let lastFocused = null;

        const close = () => {
          overlay.hidden = true;
          img.src = '';
          if (lastFocused) {
            lastFocused.focus();
          }
        };

        const open = (src, alt, trigger) => {
          lastFocused = trigger;
          img.src = src;
          img.alt = alt || '';
          overlay.hidden = false;
          overlay.querySelector('.apc-lightbox__close').focus();
        };

        overlay.addEventListener('click', (event) => {
          if (event.target === overlay) {
            close();
          }
        });
        overlay.querySelector('.apc-lightbox__close').addEventListener('click', close);
        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && !overlay.hidden) {
            close();
          }
        });

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

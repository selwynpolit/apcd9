/**
 * @file
 * Click-to-swap image gallery for event and location detail pages.
 */

((Drupal, once) => {
  Drupal.behaviors.apcGallery = {
    attach(context) {
      once('apc-gallery', '[data-apc-gallery]', context).forEach((gallery) => {
        const img = gallery.querySelector('[data-apc-gallery-img]');
        const desktopSource = gallery.querySelector('[data-apc-gallery-source-desktop]');
        const thumbs = gallery.querySelectorAll('[data-apc-gallery-thumb]');
        // Only present on templates that made the hero a lightbox trigger
        // (the event/location detail pages) -- not the calendar popup's
        // smaller gallery, which has no lightbox of its own.
        const heroTrigger = gallery.querySelector('[data-apc-lightbox-trigger][data-apc-gallery-hero]');

        if (!img || thumbs.length < 2) {
          return;
        }

        thumbs.forEach((thumb) => {
          thumb.addEventListener('click', () => {
            img.src = thumb.dataset.heroMobile;
            img.alt = thumb.dataset.alt;
            if (desktopSource) {
              desktopSource.srcset = thumb.dataset.heroDesktop;
            }
            if (heroTrigger) {
              heroTrigger.dataset.full = thumb.dataset.full;
              heroTrigger.dataset.alt = thumb.dataset.alt;
            }
            thumbs.forEach((t) => t.classList.toggle('is-active', t === thumb));
          });
        });
      });
    },
  };
})(Drupal, once);

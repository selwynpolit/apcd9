/**
 * @file
 * Opens the simple, single-image lightbox (apc_brown/lightbox -- the same
 * one the homepage hero's shuffle placard uses) for the blended signs/memes
 * wall (photo_gallery:wall_recent_all), on both the homepage and the
 * /calendar/upcoming sidebar. Deliberately not the richer prev/next +
 * caption apc_brown/photo-lightbox used by the carousels and the /photos
 * grid -- picked on request for a simpler, more consistent feel with the
 * hero's own lightbox.
 *
 * Per-photo full-size URL/alt come from drupalSettings.apcPhotoGallery
 * (built in apc_brown_preprocess_views_view() for every photo_gallery
 * display, keyed by nid), matched against the *photo's* own data-photo-nid
 * attribute (apc_brown_preprocess_views_view_field() -- not parsed from a
 * link's href, which is an alias like /photos/some-title since
 * pathauto.pattern.community_photos and carries no nid at all). The caption
 * text is its own separate link to the node (Views' own alter->make_link on
 * field_caption) with no data-photo-nid of its own -- clicking it also opens
 * this same lightbox rather than navigating, so the nid is read off the
 * item's photo link instead, via the shared .apc-photo-gallery__item
 * ancestor.
 */

((Drupal, once, drupalSettings) => {
  Drupal.behaviors.apcWallLightbox = {
    attach(context) {
      once('apc-wall-lightbox', '[data-apc-wall-lightbox]', context).forEach((grid) => {
        grid.addEventListener('click', (event) => {
          // Same contextual-links guard as the rich grid's own handler
          // (photo-lightbox.js) -- an admin's contextual pencil lives
          // inside the photo's own <a>.
          if (event.target.closest('.contextual')) {
            if (!event.target.closest('.contextual-links')) {
              event.preventDefault();
            }
            return;
          }

          // A browse grid (the /photos page, data-apc-wall-browse) leaves
          // the caption link alone so it navigates to the node. Every grid,
          // browse or not, gets prev/next arrows through its photos.
          const browse = grid.hasAttribute('data-apc-wall-browse');
          const trigger = event.target.closest(browse
            ? '.apc-photo-gallery__photo'
            : '.apc-photo-gallery__photo, .apc-photo-gallery__caption a');
          if (!trigger || !document.body.__apcLightboxOpen) {
            return;
          }

          const item = trigger.closest('.apc-photo-gallery__item');
          const dataFor = (el) => {
            const link = el ? el.querySelector('.apc-photo-gallery__photo') : null;
            const photoNid = link ? link.dataset.photoNid : null;
            return photoNid ? (drupalSettings.apcPhotoGallery || {})[photoNid] : null;
          };
          const photoLink = item ? item.querySelector('.apc-photo-gallery__photo') : trigger;
          const nid = photoLink ? photoLink.dataset.photoNid : null;
          const data = nid ? (drupalSettings.apcPhotoGallery || {})[nid] : null;
          if (!data) {
            return;
          }

          event.preventDefault();

          let nav = null;
          if (item) {
            // Only items with lightbox data take part; wraps at the ends.
            const items = Array.from(grid.querySelectorAll('.apc-photo-gallery__item')).filter(dataFor);
            let index = items.indexOf(item);
            const move = (delta) => {
              index = (index + delta + items.length) % items.length;
              const d = dataFor(items[index]);
              return { src: d.full, alt: d.alt };
            };
            nav = { prev: () => move(-1), next: () => move(1) };
          }
          document.body.__apcLightboxOpen(data.full, data.alt, trigger, nav);
        });
      });
    },
  };
})(Drupal, once, drupalSettings);

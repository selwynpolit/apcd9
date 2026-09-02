/**
 * @file
 * Shuffle-and-cycle carousel for the photo_gallery view's block displays.
 *
 * Server renders a bounded, cacheable batch (photo-gallery-specs.md, "Why
 * client-side shuffle, not server-side random"); this reorders that batch
 * client-side with Fisher-Yates and cycles through it one photo at a time.
 * field_photo_image is linked to its node page (views.view.photo_gallery's
 * own alter config), which is still what the /photos browse grid does on
 * click -- consistent with that page's own caption link. Every *carousel*
 * block placement (homepage and calendar-page mini alike) overrides that
 * to open apc_brown/photo-lightbox instead: apc_brown_preprocess_block()
 * marks those specific block instances with [data-apc-photo-lightbox] and
 * attaches that library, since block placement is the only place these are
 * distinguishable from the /photos page display, which all of them share
 * the same view/display with. The lightbox is handed a small controller
 * (current/next/prev/pause/resume) rather than a single image, so its own
 * navigation stays in sync with this carousel instead of duplicating it.
 */

((Drupal, once) => {
  const AUTO_ADVANCE_MS = 6000;

  function shuffle(items) {
    for (let i = items.length - 1; i > 0; i -= 1) {
      const j = Math.floor(Math.random() * (i + 1));
      [items[i], items[j]] = [items[j], items[i]];
    }
    return items;
  }

  Drupal.behaviors.apcPhotoCarousel = {
    attach(context) {
      once('apc-photo-carousel', '[data-apc-photo-carousel]', context).forEach((carousel) => {
        const track = carousel.querySelector('.view-content');
        if (!track) {
          return;
        }

        const slides = shuffle(
          Array.from(track.children).filter((el) => el.classList.contains('apc-photo-gallery__item')),
        );
        if (slides.length < 2) {
          return;
        }

        // Re-append in the shuffled order so DOM order matches slides[],
        // then the rest of this behavior can just index into slides[].
        slides.forEach((slide) => track.appendChild(slide));

        const controls = document.createElement('div');
        controls.className = 'apc-photo-gallery__controls';
        controls.innerHTML = `
          <button type="button" class="apc-photo-gallery__prev" aria-label="${Drupal.t('Previous photo')}">&#8249;</button>
          <button type="button" class="apc-photo-gallery__next" aria-label="${Drupal.t('Next photo')}">&#8250;</button>
        `;

        // Overlaid on the photo itself (not a below-image row), so it has to
        // live inside whichever slide is currently active -- moved there on
        // every show() rather than fixed in the DOM once.
        const placeControls = (slide) => {
          const imageWrapper = slide.querySelector('.apc-photo-gallery__image');
          (imageWrapper || slide).appendChild(controls);
        };

        // The image formatter sets loading="lazy" (correct for the grid/
        // browse page), but the shuffle above just detached and reattached
        // every slide -- browsers can drop a lazy image's load intent across
        // that kind of reinsertion and never schedule the fetch, even though
        // the element has real layout and is on-screen. Force eager loading
        // on whichever slide is about to become visible (here, and again in
        // show() below) so the visible photo never depends on that timing.
        const loadEager = (slide) => {
          const img = slide.querySelector('img');
          if (img) {
            img.loading = 'eager';
          }
        };

        let current = 0;
        slides.forEach((slide, index) => {
          slide.classList.toggle('is-active', index === current);
          slide.setAttribute('aria-hidden', index === current ? 'false' : 'true');
        });
        placeControls(slides[current]);
        loadEager(slides[current]);

        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let timer = null;
        let paused = false;

        const show = (index) => {
          slides[current].classList.remove('is-active');
          slides[current].setAttribute('aria-hidden', 'true');
          current = (index + slides.length) % slides.length;
          slides[current].classList.add('is-active');
          slides[current].setAttribute('aria-hidden', 'false');
          placeControls(slides[current]);
          loadEager(slides[current]);
        };

        const next = () => show(current + 1);
        const prev = () => show(current - 1);

        const stopTimer = () => {
          if (timer) {
            window.clearInterval(timer);
            timer = null;
          }
        };

        // WCAG "don't autoplay uninterruptibly" plus prefers-reduced-motion:
        // never start the timer at all under reduced motion, and pause on
        // hover/focus below covers the general case. Manual prev/next still
        // work either way.
        const startTimer = () => {
          if (reducedMotion || paused || timer) {
            return;
          }
          timer = window.setInterval(next, AUTO_ADVANCE_MS);
        };

        const pause = () => {
          paused = true;
          stopTimer();
        };
        const resume = () => {
          paused = false;
          startTimer();
        };

        controls.querySelector('.apc-photo-gallery__prev').addEventListener('click', prev);
        controls.querySelector('.apc-photo-gallery__next').addEventListener('click', next);

        if (carousel.closest('[data-apc-photo-lightbox]')) {
          // nid is already right there in the photo's own link
          // (views.view.photo_gallery links field_photo_image to
          // node/[nid]) -- reused as the lookup key into
          // drupalSettings.apcPhotoGallery rather than duplicating it as a
          // data attribute.
          const slideData = (slide) => {
            const link = slide.querySelector('.apc-photo-gallery__photo');
            const slideImg = slide.querySelector('img');
            const match = link ? link.getAttribute('href').match(/(\d+)\/?$/) : null;
            return {
              nid: match ? match[1] : null,
              fallbackSrc: slideImg ? slideImg.src : '',
              fallbackAlt: slideImg ? slideImg.alt : '',
            };
          };

          const controller = {
            current: () => slideData(slides[current]),
            next: () => {
              next();
              return slideData(slides[current]);
            },
            prev: () => {
              prev();
              return slideData(slides[current]);
            },
            pause,
            resume,
          };

          carousel.addEventListener('click', (event) => {
            // An admin's contextual-links pencil is rendered inside the
            // photo's <a>. Let a real menu item (Edit/Delete) navigate;
            // stop the pencil trigger from following the wrapping link; and
            // in neither case open the lightbox. (Its dropdown is still
            // clipped by the carousel frame's overflow:hidden -- editing is
            // expected from the /photos grid, not the rotating display.)
            if (event.target.closest('.contextual')) {
              if (!event.target.closest('.contextual-links')) {
                event.preventDefault();
              }
              return;
            }
            const trigger = event.target.closest('.apc-photo-gallery__photo');
            if (!trigger) {
              return;
            }
            if (document.body.__apcPhotoLightboxOpen) {
              event.preventDefault();
              document.body.__apcPhotoLightboxOpen(controller, trigger);
            }
          });
        }

        carousel.addEventListener('mouseenter', pause);
        carousel.addEventListener('mouseleave', resume);
        carousel.addEventListener('focusin', pause);
        carousel.addEventListener('focusout', (event) => {
          if (!carousel.contains(event.relatedTarget)) {
            resume();
          }
        });

        startTimer();
      });
    },
  };
})(Drupal, once);

/**
 * @file
 * Shows a random photo from the homepage hero's server-rendered pool of
 * recent signs on page load, then swaps to another random one on each
 * Shuffle click. No AJAX -- shuffles within the rendered pool only.
 *
 * Done client-side rather than by shuffling the pool server-side so every
 * page load gets a fresh pick regardless of the controller's own render
 * cache (#cache max-age 900 on the apc_front_page render array -- a
 * server-side shuffle would only reshuffle once per cache period, not per
 * visitor).
 */

((Drupal, once) => {
  Drupal.behaviors.apcSignShuffle = {
    attach(context) {
      once('apc-sign-shuffle', '[data-apc-sign-shuffle]', context).forEach((wrapper) => {
        const items = Array.from(wrapper.querySelectorAll('[data-apc-sign-index]'));
        const button = wrapper.querySelector('[data-apc-sign-shuffle-button]');

        if (items.length < 2 || !button) {
          return;
        }

        // Server markup always shows index 0 (the newest photo) -- swap to a
        // random one right away so the very first paint isn't always "latest".
        let current = 0;
        const initial = Math.floor(Math.random() * items.length);
        if (initial !== current) {
          items[current].hidden = true;
          items[initial].hidden = false;
          current = initial;
        }

        button.addEventListener('click', () => {
          // Random index other than the one currently shown -- avoids the
          // "nothing happened" feel of occasionally re-picking the same photo.
          let next = current;
          while (next === current) {
            next = Math.floor(Math.random() * items.length);
          }
          items[current].hidden = true;
          current = next;
          items[current].hidden = false;
        });
      });
    },
  };
})(Drupal, once);

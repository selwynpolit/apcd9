/**
 * @file
 * Collapsible exposed filters on the /photos browse page (page_1) below the
 * desktop breakpoint. The template renders them inside a <details>; this keeps
 * it open at desktop width and remembers what the visitor chose on a phone.
 *
 * The filter form is part of the view, and Views' AJAX replaces the whole view
 * -- filters included -- every time a checkbox changes. A freshly rendered
 * <details> would come back closed and the filters would collapse after each
 * click, so the open/closed choice lives here, outside the replaced markup,
 * and is re-applied whenever behaviors attach to the new one.
 */

((Drupal, once) => {
  // Same breakpoint as the sidebar/stack switch in photo-gallery.css.
  const desktop = window.matchMedia('(min-width: 62.5rem)');
  let userOpen = false;

  const sync = (details) => {
    details.open = desktop.matches || userOpen;
  };

  // "Filters (2)" -- so a collapsed panel still says something is applied.
  const updateCount = (details) => {
    const count = details.querySelectorAll('input:checked').length;
    const badge = details.querySelector('[data-apc-filter-count]');
    if (badge) {
      badge.textContent = count ? `(${count})` : '';
    }
  };

  // One listener for the page rather than one per attach, since AJAX
  // re-renders would otherwise pile them up on detached elements.
  desktop.addEventListener('change', () => {
    document.querySelectorAll('[data-apc-photo-filters]').forEach(sync);
  });

  Drupal.behaviors.apcPhotoFilters = {
    attach(context) {
      once('apc-photo-filters', '[data-apc-photo-filters]', context).forEach((details) => {
        sync(details);
        updateCount(details);
        details.querySelector('summary').addEventListener('click', () => {
          // Runs before the browser toggles it, so open is still the old state.
          userOpen = !details.open;
        });
        details.addEventListener('change', () => updateCount(details));
      });
    },
  };
})(Drupal, once);

/**
 * @file
 * /calendar/upcoming: submit the filter form when the category changes.
 *
 * The form's own "Apply" button (always visible) is what the date pickers
 * use -- a half-typed date must not fire a request -- and is the no-JavaScript
 * fallback for the dropdown too. Changing the dropdown submits the whole form,
 * so any dates already picked are kept. A plain GET submit (not AJAX) is
 * deliberate: the result is a normal URL (?tag=12&from=2026-10-20) that can be
 * bookmarked or shared, and it resets the pager to page 1 because the form
 * carries no `page` parameter.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.apcUpcomingFilter = {
    attach(context) {
      once('apc-upcoming-filter', '.apc-upcoming__filter-select', context).forEach((select) => {
        select.addEventListener('change', () => select.form.submit());
      });
    },
  };
}(Drupal, once));

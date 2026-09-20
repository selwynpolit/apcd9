/**
 * @file
 * Disables Drupal core Views' default post-AJAX "scroll to top of view"
 * behavior.
 *
 * \Drupal\views\Controller\ViewAjaxController::ajaxView() adds a
 * ScrollTopCommand to every AJAX response for an ajax-enabled view whenever
 * the request carries a pager_element parameter -- which core's own
 * views/js/ajax_view.js always includes, for an exposed-filter form
 * submission just as much as an actual pager click (it's simply part of the
 * view's fixed ajax settings, not something specific to paging). Confirmed
 * live on /photos: changing a Category or Photo/Sign checkbox while
 * scrolled anywhere else on the page snapped the viewport back to the top
 * of the results grid, which read as a full page reload even though the
 * request itself was genuinely AJAX.
 *
 * Drupal.AjaxCommands.prototype.scrollTop is used for nothing else in core,
 * so overriding it to a no-op only removes this one "jump to view" effect
 * -- everything else about the AJAX update (content swap, URL update via
 * pushState) is untouched.
 */

((Drupal) => {
  Drupal.AjaxCommands.prototype.scrollTop = () => {};
})(Drupal);

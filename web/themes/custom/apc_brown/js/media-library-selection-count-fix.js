/**
 * @file
 * Works around a known, unreleased core bug: Media Library's own
 * dialog:aftercreate listener (core/modules/media_library/js/
 * media_library.ui.js) unconditionally appends a fresh
 * .js-media-library-selected-count element to the dialog's button pane
 * every time dialog:aftercreate fires, with no check for one already being
 * there. Normally that event only fires once per dialog, but confirmed live
 * on the anonymous community_photo/calendar_event "Add media" dialog: the
 * DropzoneJS upload step's move to the "fill in required fields" step
 * fires dialog:aftercreate a second time for the same still-open dialog,
 * so the "N of 1 item selected" line ends up doubled.
 *
 * Core's listener is a private closure, not something this theme can hook
 * into directly, and the fix isn't released yet (still an open core issue
 * as of this writing). This instead runs its own dialog:aftercreate
 * listener, registered to fire after core's own -- this library extends
 * media_library/ui via apc_brown.info.yml's libraries-extend, so Drupal's
 * library loader guarantees this script executes after media_library.ui.js
 * -- and trims any extra counter elements down to the one core just
 * updated, on every firing of the event.
 *
 * @see https://www.drupal.org/project/drupal/issues/3463417
 */

((Drupal) => {
  window.addEventListener('dialog:aftercreate', () => {
    document
      .querySelectorAll(
        '.media-library-widget-modal .ui-dialog-buttonpane .js-media-library-selected-count',
      )
      .forEach((el, index) => {
        if (index > 0) {
          el.remove();
        }
      });
  });
})(Drupal);

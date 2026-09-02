/**
 * @file
 * Scrolls the "Add or select media" dialog to its own top when it opens.
 *
 * Something inside the Media Library widget (most likely a focus-management
 * step, since document.activeElement lands on the Dropzone "Select files"
 * button as soon as the dialog appears) leaves the dialog's own scrollable
 * content pre-scrolled a couple hundred pixels down on open -- confirmed
 * live: #drupal-modal's scrollTop was 225 immediately after Drupal's own
 * 'dialog:aftercreate' event fired, with nothing in this codebase setting
 * it. The visible effect is the drop zone itself (the "Add file" heading,
 * "Drop files here to upload them", and the Select files button) sitting
 * partly above the fold, with the existing-media grid showing first instead
 * -- for anonymous and authenticated users alike, since both go through the
 * same core Media Library dialog markup.
 *
 * Resetting scrollTop after the dialog is already open and focused doesn't
 * fight that focus placement -- keyboard/screen-reader users still land
 * exactly where they did before -- it only corrects the visual scroll
 * position so sighted users see the dialog starting from its own top, the
 * same way any other freshly-opened dialog would.
 */

(() => {
  window.addEventListener('dialog:aftercreate', (event) => {
    // event.target is #drupal-modal (the scrollable content div itself) --
    // it carries no class identifying which dialog this is; that lives on
    // jQuery UI's own wrapper, exposed here via the dialog settings' modern
    // `classes` map rather than the deprecated `dialogClass` string (which
    // core leaves empty for this dialog).
    const dialogClasses = (event.settings && event.settings.classes && event.settings.classes['ui-dialog']) || '';
    if (dialogClasses.includes('media-library-widget-modal')) {
      event.target.scrollTop = 0;
    }
  });
})();

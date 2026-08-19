/**
 * @file
 * Makes event links inside FullCalendar's "+N more" popover open the dialog.
 *
 * Why this is needed: Fullcalendar View binds Drupal's AJAX in datesRender(),
 *
 *   function datesRender (info) { Drupal.attachBehaviors(info.el); }
 *
 * which fires when the date grid renders. The "+N more" popover is built later,
 * on click, so attachBehaviors never runs over it. The links inside carry the
 * correct href and the use-ajax markup, but nothing has bound them — clicking
 * one does nothing useful.
 *
 * The handler is delegated from the calendar container, so it works on DOM that
 * did not exist when the behavior ran. It reads the href already on the anchor
 * rather than re-deriving anything from the event object, so it stays correct
 * however EventPopupProcessor builds those URLs.
 *
 * Deliberately scoped to .fc-more-popover. Events in the grid itself are
 * already handled by Drupal's own binding, and a broader selector would risk
 * intercepting those and firing two dialogs.
 */
(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.apcMorePopoverDialog = {
    attach(context) {
      once('apc-more-popover', '.js-drupal-fullcalendar', context).forEach((calendar) => {
        $(calendar).on('click', '.fc-more-popover a[href]', function (event) {
          const href = this.getAttribute('href');

          // Ignore anything that is not one of our popup routes — the popover
          // header holds a close control, and future versions may add more.
          if (!href || href.indexOf('/calendar/event/') === -1) {
            return;
          }

          event.preventDefault();
          event.stopPropagation();

          Drupal.ajax({
            url: href,
            dialogType: 'modal',
            dialog: { width: 520 },
            progress: { type: 'throbber' },
          }).execute();
        });
      });
    },
  };
})(jQuery, Drupal, once);

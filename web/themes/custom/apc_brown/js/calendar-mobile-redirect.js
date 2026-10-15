/**
 * @file
 * Sends phones from /calendar to /calendar/upcoming.
 *
 * At <= 768px fullcalendar_view switches to FullCalendar's `listYear` view
 * (default_mobile_view in views.view.calendar). That is a calendar-year list
 * that opens on the first event in the view's "ended within the last month"
 * window -- so a phone landed on a month-old date with past events first,
 * and events past 31 December were hidden until you tapped forward. It also
 * downloads the whole calendar library and event payload just to show a
 * list. /calendar/upcoming is already that list, from today onward, with a
 * category filter, so phones go there instead.
 *
 * The 768px breakpoint matches fullcalendar_view's own mobile_width, so the
 * two never disagree about what counts as a phone. `?view=grid` skips the
 * redirect (handy for a narrow desktop window, and for testing). A category
 * already chosen here (?field_tags_target_id=12) carries over as ?tag=12.
 * location.replace() so the Back button doesn't bounce straight back here.
 *
 * Loaded in the <head> (see the library) so it runs before the calendar
 * library in the footer.
 */
(function () {
  'use strict';

  try {
    var params = new URLSearchParams(window.location.search);
    if (params.get('view') === 'grid' || !window.matchMedia('(max-width: 768px)').matches) {
      return;
    }
    var target = '/calendar/upcoming';
    var tag = params.get('field_tags_target_id');
    if (tag && /^\d+$/.test(tag)) {
      target += '?tag=' + tag;
    }
    window.location.replace(target);
  }
  catch (e) {
    // Never block the calendar over this; worst case a phone sees the list view.
  }
}());

/**
 * @file
 * Filters /calendar's events by category without losing your place.
 *
 * The obvious approach -- let Drupal's own Views AJAX behavior handle the
 * exposed filter form -- doesn't fit here the way it does for the photo
 * gallery. fullcalendar_view bakes every matching event into one JSON array
 * at render time (FullcalendarViewPreprocess.php: `'events' => $entries`)
 * and hands it to FullCalendar's JS once; FullCalendar itself handles all
 * month/week/day navigation client-side after that, with no further server
 * round trip. Letting Views AJAX replace the `.js-drupal-fullcalendar`
 * container -- which is what it does -- would make fullcalendar_view.js's
 * own behavior re-run and construct a *new* FullCalendar.Calendar instance
 * on that new element, which resets to today's month
 * (`default_date_source: now`) regardless of what the visitor was looking
 * at. Filtering is supposed to answer "what's on in the month I'm already
 * viewing", not send you back to today.
 *
 * So this never lets the exposed filter form actually submit. Selecting a
 * category fires a plain fetch() at the same `/views/ajax` endpoint Views'
 * own AJAX would use, but only the `settings` command in the response is
 * read -- specifically the freshly-filtered `calendar_options.events`
 * array Drupal just computed server-side -- and handed directly to the
 * *existing* FullCalendar instance via removeAllEventSources() +
 * addEventSource(). The calendar element is never touched, so whatever
 * month/view the visitor is on stays exactly where it was.
 *
 * The exposed filter's own "Apply" button is left in the DOM (necessary
 * for the `views.view.calendar` display's own AJAX server support to
 * exist at all, and it's what happens if JavaScript is unavailable): with
 * this behavior attached, selecting a category never lets that submission
 * occur, so it's purely a no-JS fallback, not a competing code path.
 *
 * The round trip is a real, uncached `/views/ajax` request re-running the
 * whole calendar query server-side (see apc_calendar.module's tag-count
 * hook for one example of what else runs on that path), so on a slower
 * connection or an uncached environment it's genuinely not instant --
 * a spinner overlay + a page-wide wait cursor cover that gap so a visitor
 * doesn't wonder whether their click registered.
 */

((Drupal, drupalSettings, once) => {
  Drupal.behaviors.apcCalendarFilter = {
    attach(context) {
      once('apc-calendar-filter', '.view-id-calendar.view-display-id-page_1', context).forEach((viewEl) => {
        const select = viewEl.querySelector('select[name="field_tags_target_id"]');
        const calendarEl = document.querySelector('.js-drupal-fullcalendar');
        if (!select || !calendarEl) {
          return;
        }

        const viewIndex = calendarEl.getAttribute('data-calendar-view-index');
        const domIdMatch = [...viewEl.classList].find((c) => c.startsWith('js-view-dom-id-'));
        const viewDomId = domIdMatch ? domIdMatch.replace('js-view-dom-id-', '') : null;
        if (viewIndex === null || !viewDomId) {
          return;
        }

        // The "Apply" button (and Enter-in-select) would otherwise submit
        // this GET form normally -- a real page navigation, the exact
        // full-reload experience this exists to replace. Left in the DOM,
        // unhidden, for when JavaScript genuinely isn't running -- the
        // marker class only hides it (in calendar-filter.css) once this
        // behavior has actually attached and taken over submission, so a
        // no-JS visitor never loses their only way to apply the filter.
        const form = select.closest('form');
        if (form) {
          form.addEventListener('submit', (event) => event.preventDefault());
          form.classList.add('apc-calendar-filter-active');
        }

        // A spinner over the calendar itself (not just the disabled select)
        // plus a page-wide wait cursor -- the fetch below is a real,
        // uncached server round trip, so on a slow connection the only
        // other feedback would be a disabled dropdown, easy to miss if the
        // visitor's eyes are already on the calendar grid below it.
        const calendarWrapper = calendarEl.parentElement;
        calendarWrapper.classList.add('apc-calendar-loading-wrapper');
        const loadingOverlay = document.createElement('div');
        loadingOverlay.className = 'apc-calendar-loading-overlay';
        loadingOverlay.setAttribute('role', 'status');
        loadingOverlay.setAttribute('aria-live', 'polite');
        loadingOverlay.innerHTML = '<span class="apc-calendar-loading-spinner" aria-hidden="true"></span>'
          + '<span class="apc-calendar-loading-text">Fetching events…</span>';
        calendarWrapper.appendChild(loadingOverlay);

        select.addEventListener('change', () => {
          const value = select.value;
          const params = new URLSearchParams({
            view_name: 'calendar',
            view_display_id: 'page_1',
            view_dom_id: viewDomId,
            _wrapper_format: 'drupal_ajax',
          });
          if (value) {
            params.set('field_tags_target_id', value);
          }

          // Bookmarkable/shareable without a reload: the page itself
          // already honors this same query parameter server-side (it's a
          // normal exposed filter), so a later hard refresh or shared link
          // reproduces the same filtered view -- replaceState just avoids
          // triggering that refresh right now.
          const url = new URL(window.location.href);
          if (value) {
            url.searchParams.set('field_tags_target_id', value);
          }
          else {
            url.searchParams.delete('field_tags_target_id');
          }
          window.history.replaceState(null, '', url);

          select.disabled = true;
          document.body.classList.add('apc-calendar-loading');
          loadingOverlay.classList.add('is-visible');

          fetch(`/views/ajax?${params.toString()}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
          })
            .then((response) => response.json())
            .then((commands) => {
              const settingsCommand = commands.find((command) => command.command === 'settings');
              const viewSettings = settingsCommand
                && settingsCommand.settings
                && settingsCommand.settings.fullCalendarView
                && settingsCommand.settings.fullCalendarView[viewIndex];
              if (!viewSettings) {
                throw new Error('No fullCalendarView settings in the AJAX response.');
              }

              const events = JSON.parse(viewSettings.calendar_options).events || [];
              const calendarObj = drupalSettings.calendar && drupalSettings.calendar[viewIndex];
              if (!calendarObj) {
                throw new Error('FullCalendar instance not found at drupalSettings.calendar[' + viewIndex + '].');
              }

              calendarObj.removeAllEventSources();
              calendarObj.addEventSource(events);
            })
            .catch((error) => {
              // Fails safely: the calendar keeps showing whatever it last
              // had rather than breaking, and this is visible for whoever
              // is looking at devtools without bothering anyone else.
              // eslint-disable-next-line no-console
              console.error('apc_brown calendar filter: could not refresh events.', error);
            })
            .finally(() => {
              select.disabled = false;
              document.body.classList.remove('apc-calendar-loading');
              loadingOverlay.classList.remove('is-visible');
            });
        });
      });
    },
  };
})(Drupal, drupalSettings, once);

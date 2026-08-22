/**
 * @file
 * Shows a hover card for FullCalendar month-grid events.
 *
 * The month grid (dayGridMonth only -- see EventHoverDataProcessor) hides
 * the built-in time label to keep cells compact; this restores it, plus a
 * thumbnail/location/tags, in a card that appears on hover instead.
 *
 * Deliberately does NOT call Calendar.setOption('eventMouseover', ...): in
 * FullCalendar v4, setOption() tears down and rebuilds the calendar's
 * internal rendering for most options rather than patching just that one
 * callback. That rebuild does not re-run fullcalendar_view.js's own
 * eventRender(), which is what adds the use-ajax/dialog attributes each
 * event's link needs to open the detail popup -- so calling setOption() here
 * silently broke click-to-popup the first time this was tried. Plain event
 * delegation (mouseenter/mouseleave on .fc-event, matching more-popover-
 * dialog.js's existing pattern for clicks) never touches the Calendar
 * instance at all, so nothing it already rendered is put at risk.
 *
 * The one thing delegation alone can't give back is the event's data. That's
 * solved read-only: at hover time -- never earlier, so there's no need to
 * wait for the calendar to exist the way a setOption() approach would --
 * drupalSettings.calendar[viewIndex].getEvents() is queried (a getter, not a
 * mutation) and matched by URL against the hovered link's href, which
 * recovers the same Event object FullCalendar itself built, extendedProps
 * (image/location/tags/virtual from EventHoverDataProcessor) included.
 */
(function ($, Drupal, once) {
  'use strict';

  const SHOW_DELAY = 150;
  let showTimer = null;
  let $card = null;

  function getCard() {
    if ($card) {
      return $card;
    }
    $card = $('<div class="apc-event-hover-card" role="presentation"></div>').appendTo(document.body);
    return $card;
  }

  function formatTimeRange(start, end) {
    if (!start) {
      return '';
    }
    const timeFmt = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' });
    let text = timeFmt.format(start);
    if (end && end.getTime() !== start.getTime()) {
      const sameDay = start.toDateString() === end.toDateString();
      text += ' – ' + (sameDay ? timeFmt.format(end) : new Intl.DateTimeFormat(undefined, {
        month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
      }).format(end));
    }
    return text;
  }

  function buildCard(event) {
    const props = event.extendedProps || {};
    const $el = getCard();
    $el.empty();

    if (props.image) {
      $('<div class="apc-event-hover-card__image"></div>')
        .css('background-image', 'url(' + props.image + ')')
        .appendTo($el);
    }

    const $body = $('<div class="apc-event-hover-card__body"></div>').appendTo($el);

    $('<div class="apc-event-hover-card__title"></div>').text(event.title).appendTo($body);

    const timeText = props.virtual ? Drupal.t('Online only') : (event.allDay ? '' : formatTimeRange(event.start, event.end));
    if (timeText) {
      $('<div class="apc-event-hover-card__time"></div>').text(timeText).appendTo($body);
    }

    if (props.location) {
      $('<div class="apc-event-hover-card__location"></div>').text(props.location).appendTo($body);
    }

    if (props.tags && props.tags.length) {
      const $tags = $('<div class="apc-event-hover-card__tags"></div>').appendTo($body);
      props.tags.forEach((tag) => {
        $('<span class="apc-event-hover-card__tag"></span>').text(tag).appendTo($tags);
      });
    }

    return $el;
  }

  function position($el, targetEl) {
    const rect = targetEl.getBoundingClientRect();
    const cardWidth = $el.outerWidth();
    const cardHeight = $el.outerHeight();
    const margin = 8;

    let left = rect.left + (rect.width / 2) - (cardWidth / 2);
    left = Math.max(margin, Math.min(left, window.innerWidth - cardWidth - margin));

    // Prefer below the event; flip above it if there is not enough room.
    let top = rect.bottom + margin;
    if (top + cardHeight > window.innerHeight - margin) {
      top = rect.top - cardHeight - margin;
    }

    $el.css({ left: left + 'px', top: Math.max(margin, top) + 'px' });
  }

  function showCard(event, targetEl) {
    clearTimeout(showTimer);
    showTimer = setTimeout(() => {
      const $el = buildCard(event);
      position($el, targetEl);
      $el.addClass('is-visible');
    }, SHOW_DELAY);
  }

  function hideCard() {
    clearTimeout(showTimer);
    if ($card) {
      $card.removeClass('is-visible');
    }
  }

  /**
   * Finds the href a hovered .fc-event is (or contains, or sits inside).
   *
   * FullCalendar renders a linked dayGrid event as the anchor itself, but
   * this is deliberately defensive about that rather than assuming it, since
   * the same delegated selector also has to work for events inside the
   * "+N more" popover, built later by different markup.
   */
  function findHref(el) {
    if (el.getAttribute('href')) {
      return el.getAttribute('href');
    }
    const descendant = el.querySelector('a[href]');
    if (descendant) {
      return descendant.getAttribute('href');
    }
    const ancestor = el.closest('a[href]');
    return ancestor ? ancestor.getAttribute('href') : null;
  }

  /**
   * Matches a href back to the FullCalendar Event object it links to.
   *
   * Compares pathnames rather than raw strings since FullCalendar may hand
   * back event.url as an absolute URL even though EventPopupProcessor set it
   * as a relative one.
   */
  function findEventByHref(calendar, href) {
    if (!href) {
      return null;
    }
    const path = new URL(href, window.location.origin).pathname;
    return calendar.getEvents().find((candidate) => {
      return candidate.url && new URL(candidate.url, window.location.origin).pathname === path;
    }) || null;
  }

  Drupal.behaviors.apcEventHover = {
    attach(context) {
      once('apc-event-hover', '.js-drupal-fullcalendar', context).forEach((calendarEl) => {
        $(calendarEl)
          .on('mouseenter', '.fc-event', function () {
            const viewIndex = parseInt(calendarEl.getAttribute('data-calendar-view-index'), 10);
            const calendar = drupalSettings.calendar && drupalSettings.calendar[viewIndex];
            if (!calendar) {
              return;
            }
            const event = findEventByHref(calendar, findHref(this));
            if (event) {
              showCard(event, this);
            }
          })
          .on('mouseleave', '.fc-event', hideCard)
          // A click is about to open the detail dialog -- don't leave the
          // hover card floating over it.
          .on('click', '.fc-event, .fc-more-popover a', hideCard);
      });

      // Scroll/resize invalidate the card's position rather than tracking
      // it live -- simplest thing that does not look broken.
      once('apc-event-hover-window', 'html', context).forEach(() => {
        $(window).on('scroll resize', hideCard);
      });
    },
  };
})(jQuery, Drupal, once);

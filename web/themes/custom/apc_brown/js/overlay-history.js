/**
 * @file
 * Lets the browser's back gesture/button close an open overlay (the event
 * popup dialog, and both photo lightboxes) instead of leaving the page.
 *
 * None of those overlays change the URL, so on a phone a back swipe while one
 * was open went straight to whatever page came before the site -- the reported
 * "swiping back just exits the site". Each overlay now pushes one history
 * entry when it opens; back then pops that entry (popstate) and closes the
 * overlay, leaving the visitor on the page they were already on.
 *
 * Overlays register through Drupal.apcOverlayHistory:
 *  - push(close): call when the overlay opens. `close` must hide the overlay
 *    and must not touch history itself -- it is what popstate calls.
 *  - dismiss(close): call from user-initiated close paths (button, Escape,
 *    backdrop) for an overlay that hides itself. Hides it and rewinds the
 *    entry push() added, so history doesn't accumulate dead entries.
 *  - release(close): same rewind, for a close path that already hid the
 *    overlay itself (the jQuery UI dialog's own close button, below).
 *
 * It also lets a click on the event popup's dimmed backdrop close it.
 *
 * The stack matters because the lightbox can open on top of the event popup
 * (its location photos): back closes the lightbox first, then the popup.
 *
 * It also restores the page's scroll position across all of that, ourselves.
 * 2026-09-23 report: on the homepage in Firefox on a Mac, opening an event
 * popup partway down the page and closing it with Escape landed back at the
 * top of the page instead of where the visitor was. First guess was a
 * Firefox-specific `history.back()` scroll-restoration quirk (every close
 * path here except the real back gesture triggers that navigation itself, as
 * cleanup) -- wrong, per a live Chrome repro of the same symptom once tested
 * carefully (this file's own earlier fix attempt for that guess is why
 * scrollY capture/restore was already here to build on). The real cause: the
 * popup's "Details" link is deliberately first in DOM/tab order (see below),
 * and jQuery UI's dialog focuses the first tabbable element in the dialog
 * when it opens -- so opening ANY popup focuses that link, and the browser's
 * default focus-follows-scroll behaviour scrolls the *page* to it, not
 * understanding that the dialog is position: fixed and already fully in
 * view regardless of page scroll. Confirmed live: window.scrollY changes the
 * moment the dialog appears, before any of this file's own code runs.
 * push() used to read window.scrollY itself, on 'dialog:aftercreate' -- by
 * then the jump above has usually already happened, so it faithfully
 * captured (and every close path then faithfully restored) the *wrong*
 * position. Capturing scrollY as early as possible instead -- on the
 * keydown/mousedown/touchstart that starts opening a dialog, well before
 * jQuery UI's own dialog-open focus handling runs -- avoids the wrong
 * capture without needing to fight or predict that focus behaviour at all.
 */

((Drupal) => {
  // Milliseconds after the event popup opens during which a backdrop click is
  // ignored -- see the double-click note where it's used.
  const BACKDROP_CLICK_DELAY = 400;

  // The scroll position from just before the most recent interaction that
  // might open one of these overlays -- see the file-header comment on why
  // this can't just be read fresh when the overlay actually opens. Global
  // and unscoped to any particular trigger (rather than listening only on
  // known trigger selectors) so a future new trigger type doesn't need this
  // file changed too: only one thing can be opening at a time, so whatever
  // this holds when an overlay's 'push' actually runs is necessarily the
  // scroll position from just before whatever the visitor did to open it.
  let lastInteractionScrollY = window.scrollY;
  const captureScrollY = () => {
    lastInteractionScrollY = window.scrollY;
  };
  document.addEventListener('mousedown', captureScrollY, true);
  document.addEventListener('touchstart', captureScrollY, true);
  document.addEventListener('keydown', captureScrollY, true);

  const stack = []; // { close, scrollY }
  // history.back() from release() also fires popstate; count those so the
  // listener doesn't mistake our own rewind for the visitor going back.
  let pendingBacks = 0;
  // scrollY to restore when one of those self-triggered backs' popstate
  // arrives -- set right before each window.history.back() call below.
  let pendingScrollY = null;

  // Called after every close, on both the real-back-gesture path and our
  // own synthetic-back path below. Run twice: once now, and once on the
  // next frame, since focus restoration (jQuery UI dialog's own close
  // handler moves focus back to the trigger) and/or the browser's own
  // history-scroll-restoration can themselves scroll the page a moment
  // *after* this runs -- the rAF call is what actually wins in that case.
  // Harmless to call when nothing moved: scrolling to the position already
  // in place is a no-op.
  const restoreScroll = (y) => {
    if (typeof y !== 'number') {
      return;
    }
    window.scrollTo(0, y);
    requestAnimationFrame(() => window.scrollTo(0, y));
  };

  window.addEventListener('popstate', () => {
    if (pendingBacks > 0) {
      pendingBacks -= 1;
      restoreScroll(pendingScrollY);
      pendingScrollY = null;
      return;
    }
    const entry = stack.pop();
    if (entry) {
      entry.close();
      restoreScroll(entry.scrollY);
    }
  });

  const release = (close) => {
    const index = stack.findIndex((entry) => entry.close === close);
    if (index === -1) {
      return;
    }
    const wasTop = index === stack.length - 1;
    const [entry] = stack.splice(index, 1);
    // Only the newest entry can be rewound; anything else stays as an
    // orphan, which is harmless (one extra back press at worst).
    if (wasTop) {
      pendingBacks += 1;
      pendingScrollY = entry.scrollY;
      window.history.back();
    }
  };

  Drupal.apcOverlayHistory = {
    push(close) {
      window.history.pushState({ apcOverlay: true }, '');
      stack.push({ close, scrollY: lastInteractionScrollY });
    },
    release,
    dismiss(close) {
      release(close);
      close();
    },
  };

  // The event popup is a stock Drupal/jQuery UI modal dialog opened from
  // several places (homepage and upcoming cards, the calendar's own
  // FullCalendar links, its "more" popover), so hook it by its content
  // rather than by any one trigger. Other dialogs (Add location, Media
  // Library) are deliberately left alone. Drupal inserts the AJAX response
  // before dialog:aftercreate fires, so the content check works here.
  document.addEventListener('dialog:aftercreate', (event) => {
    const element = event.target;
    if (!element.querySelector || !element.querySelector('.apc-event-popup')) {
      return;
    }
    const close = () => {
      const button = element.closest('.ui-dialog')?.querySelector('.ui-dialog-titlebar-close');
      if (button) {
        button.click();
      }
      else {
        event.dialog.close();
      }
    };
    Drupal.apcOverlayHistory.push(close);

    // Clicking the dimmed backdrop closes the popup too, alongside the X and
    // Escape. Only this read-only popup -- a form dialog would lose typed
    // input to a stray click. Three guards:
    //  - the press must have *started* on the backdrop, so selecting text
    //    inside the popup and releasing outside it doesn't close it;
    //  - it's a click, not a pointerdown, so the tap that dismisses the popup
    //    can't also land on the card/calendar cell underneath;
    //  - ignored for the first moments after opening, so the second click of
    //    a double-click on a card can't close the popup it just opened.
    const openedAt = Date.now();
    let pressedOnBackdrop = false;
    const isBackdrop = (target) => target.classList?.contains('ui-widget-overlay');
    const onPointerDown = (pointerEvent) => {
      pressedOnBackdrop = isBackdrop(pointerEvent.target);
    };
    const onClick = (clickEvent) => {
      const valid = pressedOnBackdrop && isBackdrop(clickEvent.target);
      pressedOnBackdrop = false;
      if (valid && Date.now() - openedAt > BACKDROP_CLICK_DELAY) {
        close();
      }
    };
    document.addEventListener('pointerdown', onPointerDown, true);
    document.addEventListener('click', onClick, true);

    // Fires for every way the dialog can close, including our own close()
    // above -- release() is a no-op by then, since popstate already removed
    // the entry.
    element.addEventListener('dialog:afterclose', () => {
      document.removeEventListener('pointerdown', onPointerDown, true);
      document.removeEventListener('click', onClick, true);
      release(close);
    }, { once: true });
  });
})(Drupal);

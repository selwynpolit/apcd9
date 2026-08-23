/**
 * @file
 * Appends an "Add a new venue" entry to the Location autocomplete results.
 *
 * The visible link below the field remains the no-JavaScript path and the
 * primary affordance; this is enhancement. Its real value is the zero-results
 * case: jQuery UI hides the menu entirely when nothing matches, which is
 * precisely the moment a submitter needs to be told they can add one.
 *
 * Scoped to our element only. Overriding the shared Drupal.autocomplete.options
 * would alter every autocomplete on the site, including admin screens.
 */
(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.apcLocationAddNew = {
    attach(context) {
      const inputs = once(
        'apc-location-add-new',
        'input[data-apc-location-autocomplete]',
        context,
      );

      inputs.forEach((input) => {
        const $input = $(input);

        // Core attaches the widget in its own behavior; if it has not run yet
        // there is nothing to wrap and no harm in skipping.
        if (!$input.data('ui-autocomplete')) {
          return;
        }

        const originalSource = $input.autocomplete('option', 'source');
        const originalSelect = $input.autocomplete('option', 'select');

        // Set through the option setter rather than assigning to options.source
        // directly: jQuery UI resolves the source once in _initSource(), so a
        // direct assignment after init is silently ignored.
        $input.autocomplete('option', 'source', function (request, response) {
          const addNew = {
            label: Drupal.t('＋ Add a new venue…'),
            value: '',
            apcAddNew: true,
          };

          const append = (suggestions) => {
            const list = Array.isArray(suggestions) ? suggestions.slice() : [];
            list.push(addNew);
            response(list);
          };

          if (typeof originalSource === 'function') {
            // Core's sourceData is bound to the widget instance.
            originalSource.call(this, request, append);
          } else {
            append([]);
          }
        });

        // Distinct rendering for the "Add a new venue" row, so it reads as a
        // different kind of thing from a real matched venue rather than
        // blending into the list.
        //
        // Ordinary rows are rendered with .html(), matching Drupal core's own
        // autocomplete (core/misc/autocomplete.js). The entity autocomplete
        // matcher HTML-escapes each label, so a venue named "Jill's House"
        // arrives as "Jill&#039;s House" and must be decoded by .html().
        // jQuery UI's base _renderItem uses .text() instead, which rendered the
        // raw &#039; entity -- the bug this replaces. .html() is safe here: the
        // label is already server-escaped, exactly as core relies on.
        const instance = $input.autocomplete('instance');
        if (instance) {
          instance._renderItem = function (ul, item) {
            if (item.apcAddNew) {
              return $('<li>')
                .addClass('apc-autocomplete-add-new')
                .append($('<div>').text(item.label))
                .appendTo(ul);
            }
            return $('<li>')
              .append($('<a>').html(item.label))
              .appendTo(ul);
          };
        }

        $input.autocomplete('option', 'select', function (event, ui) {
          if (ui.item && ui.item.apcAddNew) {
            event.preventDefault();

            // Reuse the existing link rather than duplicating the dialog
            // wiring — it already carries the use-ajax and data-dialog-*
            // attributes core's dialog system needs.
            const $link = $input
              .closest('.field--name-field-location, .js-form-wrapper, form')
              .find('.apc-add-location-link')
              .first();

            if ($link.length) {
              $link.trigger('click');
            }

            // Leave whatever the user had typed in place; returning false stops
            // jQuery UI writing the empty value over it.
            return false;
          }

          return typeof originalSelect === 'function'
            ? originalSelect.call(this, event, ui)
            : true;
        });
      });
    },
  };
})(jQuery, Drupal, once);

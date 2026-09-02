/**
 * @file
 * Disables scroll-wheel zoom on the geofield's Leaflet widget map.
 *
 * The leaflet_widget's own `scroll_zoom_enabled: 0` setting does not do this:
 * its widget JS only wires focus/blur enable/disable when the setting is ON,
 * and does nothing when it's OFF -- leaving Leaflet's built-in default, which
 * is wheel zoom ENABLED. The result is a map that hijacks the page scroll
 * whenever the pointer passes over it. Here we disable it outright; the map's
 * +/- buttons still zoom.
 *
 * Attached only on the location term form
 * (apc_calendar_form_taxonomy_term_locations_form_alter), so it only ever
 * touches that form's map.
 */

((Drupal, drupalSettings, once, $) => {
  function disableScrollZoom(lMap) {
    if (lMap && lMap.scrollWheelZoom && lMap.scrollWheelZoom.enabled()) {
      lMap.scrollWheelZoom.disable();
    }
  }

  Drupal.behaviors.apcLeafletNoScrollZoom = {
    attach() {
      // Two paths, because map init order vs. this behavior isn't guaranteed:
      //
      // 1. Bind the leaflet module's own 'leaflet.map' init event (fired with
      //    the Leaflet map object) once, for any map that initialises after
      //    this runs.
      once('apc-leaflet-no-scroll', 'html').forEach(() => {
        $(document).on('leaflet.map', (event, settings, lMap) => {
          disableScrollZoom(lMap);
        });
      });

      // 2. Disable on any maps already initialised before we bound above --
      //    the leaflet module keeps each live map on drupalSettings.leaflet.
      if (drupalSettings.leaflet) {
        Object.keys(drupalSettings.leaflet).forEach((id) => {
          disableScrollZoom(drupalSettings.leaflet[id].lMap);
        });
      }
    },
  };
})(Drupal, drupalSettings, once, jQuery);

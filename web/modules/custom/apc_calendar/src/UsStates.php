<?php

declare(strict_types=1);

namespace Drupal\apc_calendar;

/**
 * Valid US state and territory abbreviations.
 *
 * Shared between AddLocationForm (a two-letter field validated against this
 * list rather than a 50-option select) and the location term form's address
 * lookup helpers, which need the same list to normalize a geocoder's
 * spelled-out state name ("Texas") into the abbreviation the Address field
 * expects ("TX").
 */
final class UsStates {

  public const STATES = [
    'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI',
    'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN',
    'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH',
    'OK', 'OR', 'PA', 'PR', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA',
    'VI', 'WA', 'WV', 'WI', 'WY',
  ];

  /**
   * Full state name, lowercased, to abbreviation.
   *
   * Nominatim's admin-level results sometimes carry a code and sometimes
   * only a spelled-out name ("Texas"). The Address field wants the
   * abbreviation, so the geocoder lookup helpers normalize through this map
   * when no code is available.
   */
  public const NAME_TO_CODE = [
    'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR',
    'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT',
    'delaware' => 'DE', 'district of columbia' => 'DC', 'florida' => 'FL',
    'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID', 'illinois' => 'IL',
    'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS', 'kentucky' => 'KY',
    'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD',
    'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN',
    'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT',
    'nebraska' => 'NE', 'nevada' => 'NV', 'new hampshire' => 'NH',
    'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY',
    'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH',
    'oklahoma' => 'OK', 'oregon' => 'OR', 'pennsylvania' => 'PA',
    'puerto rico' => 'PR', 'rhode island' => 'RI', 'south carolina' => 'SC',
    'south dakota' => 'SD', 'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT',
    'vermont' => 'VT', 'virginia' => 'VA', 'virgin islands' => 'VI',
    'washington' => 'WA', 'west virginia' => 'WV', 'wisconsin' => 'WI',
    'wyoming' => 'WY',
  ];

}

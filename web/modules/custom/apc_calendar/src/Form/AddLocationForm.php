<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Form;

use Drupal\apc_calendar\Plugin\EntityReferenceSelection\SessionLocationSelection;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Modal form for adding a location from inside the event submission form.
 *
 * Deliberately not an entity form and not Inline Entity Form. The audience is a
 * volunteer adding one event in a couple of minutes: they get four plain
 * inputs, no map, no coordinates, no image, no publishing controls. Everything
 * else about the location is an admin's job at approval time.
 *
 * On success the term is created (unpublished, via
 * apc_calendar_taxonomy_term_presave()), recorded as referenceable for this
 * session, and written straight into the event form's Location autocomplete so
 * the submitter can carry on without losing anything they had typed.
 */
final class AddLocationForm extends FormBase {

  /**
   * Valid US state and territory abbreviations.
   *
   * A two-letter field validated against this list rather than a 50-option
   * select: the same data quality, far less markup, and for an Austin calendar
   * the default is right almost every time.
   */
  private const STATES = [
    'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI',
    'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN',
    'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH',
    'OK', 'OR', 'PA', 'PR', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA',
    'VI', 'WA', 'WV', 'WI', 'WY',
  ];

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly PrivateTempStoreFactory $tempStoreFactory,
    protected readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('tempstore.private'),
      $container->get('renderer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'apc_calendar_add_location';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Target for AJAX validation errors — a modal has nowhere else to put them.
    $form['#prefix'] = '<div id="apc-add-location-form-wrapper">';
    $form['#suffix'] = '</div>';
    $form['status_messages'] = [
      '#type' => 'status_messages',
      '#weight' => -10,
    ];

    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Tell us where this event is happening. An organiser will check the details before it goes on the calendar.'),
      '#weight' => -5,
    ];

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Venue name'),
      '#description' => $this->t('For example, Springdale Community Hub.'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['address_line1'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Street address'),
      '#description' => $this->t('For example, 2200 Springdale Rd.'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['locality'] = [
      '#type' => 'textfield',
      '#title' => $this->t('City'),
      '#default_value' => 'Austin',
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['administrative_area'] = [
      '#type' => 'textfield',
      '#title' => $this->t('State'),
      '#default_value' => 'TX',
      '#required' => TRUE,
      '#size' => 4,
      '#maxlength' => 2,
      '#description' => $this->t('Two-letter abbreviation.'),
    ];

    $form['postal_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ZIP code'),
      '#required' => TRUE,
      '#size' => 12,
      '#maxlength' => 10,
    ];

    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Anything helpful about getting in?'),
      '#description' => $this->t('Optional — parking, accessibility, which door to use.'),
      '#rows' => 3,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add this location'),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        'wrapper' => 'apc-add-location-form-wrapper',
      ],
    ];

    // Core's dialog.ajax.js binds .dialog-cancel and closes the dialog, so this
    // needs no AJAX callback of its own. Escape and the X already work; this is
    // for people who look for a button.
    $form['actions']['cancel'] = [
      '#type' => 'button',
      '#value' => $this->t('Cancel'),
      '#attributes' => ['class' => ['dialog-cancel']],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $state = strtoupper(trim((string) $form_state->getValue('administrative_area')));
    if ($state !== '' && !in_array($state, self::STATES, TRUE)) {
      $form_state->setErrorByName('administrative_area', $this->t('Please use a two-letter state abbreviation, for example TX.'));
    }

    // Accepts ZIP and ZIP+4. A wrong-but-well-formed ZIP still gets checked by
    // an organiser at approval; the point here is to catch typos and stray text,
    // not to verify the code exists.
    $zip = trim((string) $form_state->getValue('postal_code'));
    if ($zip !== '' && !preg_match('/^\d{5}(-\d{4})?$/', $zip)) {
      $form_state->setErrorByName('postal_code', $this->t('Please enter a 5-digit ZIP code, for example 78702.'));
    }

    $name = trim((string) $form_state->getValue('name'));
    if ($name === '') {
      return;
    }

    // Cheap duplicate guard. Unapproved locations are invisible in the
    // autocomplete, so without this a submitter who cannot find "Springdale
    // Community Hub" adds a second one — and the vocabulary fragments exactly
    // where it matters. Matching includes unpublished terms on purpose.
    $existing = $this->entityTypeManager->getStorage('taxonomy_term')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'locations')
      ->condition('name', $name)
      ->range(0, 1)
      ->execute();

    if ($existing) {
      $form_state->setErrorByName('name', $this->t('We already have a location with that name. Close this window and start typing it in the Location field — if it does not appear, it is waiting for approval and an organiser will sort it out.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // All the work happens in ajaxSubmit(); this exists to satisfy FormInterface
    // and to keep the form functional if JavaScript is unavailable.
  }

  /**
   * Creates the term and hands it back to the event form.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    // Re-render the form with its messages if validation failed.
    if ($form_state->hasAnyErrors()) {
      return $response->addCommand(new ReplaceCommand('#apc-add-location-form-wrapper', $form));
    }

    $term = Term::create([
      'vid' => 'locations',
      'name' => trim((string) $form_state->getValue('name')),
      'field_address' => [
        'country_code' => 'US',
        'address_line1' => trim((string) $form_state->getValue('address_line1')),
        'locality' => trim((string) $form_state->getValue('locality')),
        'administrative_area' => strtoupper(trim((string) $form_state->getValue('administrative_area'))),
        'postal_code' => trim((string) $form_state->getValue('postal_code')),
      ],
    ]);

    if ($notes = trim((string) $form_state->getValue('notes'))) {
      $term->set('field_notes', ['value' => $notes, 'format' => 'basic_html']);
    }

    // Saved unpublished by apc_calendar_taxonomy_term_presave().
    $term->save();

    // Make this one term referenceable for the rest of this session, so the
    // event form's validation accepts it despite being unpublished.
    $this->rememberTerm((int) $term->id());

    // The autocomplete widget round-trips its value as "Label (id)".
    //
    // The " - pending" marker matches what SessionLocationSelection puts in the
    // dropdown for unpublished locations, so a freshly added venue reads the
    // same whether it was picked from the list or just created here. It is
    // display only — EntityAutocomplete stores the extracted ID, not this text.
    $label = $term->label();
    if (!$term->isPublished()) {
      $label .= ' - ' . $this->t('pending');
    }
    $value = $label . ' (' . $term->id() . ')';

    return $response
      ->addCommand(new CloseModalDialogCommand())
      ->addCommand(new InvokeCommand('[data-apc-location-autocomplete]', 'val', [$value]))
      ->addCommand(new InvokeCommand('[data-apc-location-autocomplete]', 'trigger', ['change']));
  }

  /**
   * Records the new term ID in this session's private tempstore.
   *
   * @see \Drupal\apc_calendar\Plugin\EntityReferenceSelection\SessionLocationSelection
   */
  private function rememberTerm(int $tid): void {
    $store = $this->tempStoreFactory->get(SessionLocationSelection::COLLECTION);
    $tids = $store->get(SessionLocationSelection::KEY) ?: [];
    if (!in_array($tid, $tids, TRUE)) {
      $tids[] = $tid;
      $store->set(SessionLocationSelection::KEY, $tids);
    }
  }

}

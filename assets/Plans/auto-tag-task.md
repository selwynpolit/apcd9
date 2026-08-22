# Task: "Suggest tags" button on `calendar_event`

Status: **designed, not started.** No code written, no config changed.

Goal: cut the near-duplicate fragmentation in the `tags` vocabulary at the source, by offering the
submitter a set of already-correct tags **on request**, while they are filling in the form.

The shape is deliberately small: a button, a checkbox list, and one matcher in PHP. An earlier
design did this automatically at save time and grew a great deal of machinery to make that safe —
see "Rejected approaches", which is the most useful section in this document.

## Read first

- `CLAUDE.md` — TODO section and the two standing rules.
- `Event Calendar Plan.md` **§3e** — why `field_tags` is deliberately *not* gated the way
  `locations` is, and why the answer to duplicate tags was "seed the vocabulary" rather than "add a
  review gate". This task is the mechanism that makes §3e's advice work without a seeding step.
- `event-import-task.md` — the importer reuses the matcher built here. See "Collisions".

**Two standing rules for this repo, both learned the hard way:**

1. Run `ddev drush cst` **before** `ddev drush cex`. An unguarded `cex` has already silently
   discarded hand-edited config that had not yet been imported.
2. Verify against the running site, not against exported YAML.

## What gets built

A **Suggest tags** button on the `calendar_event` add/edit form, below `field_tags`. Clicking it
posts the form over AJAX, matches the title and body against a keyword map, and renders a checkbox
list of the tags it found — **all checked by default**. The submitter unchecks anything wrong and
saves normally.

Three properties follow from "on request" rather than "on save", and they are the whole reason the
design is this small:

- **Nothing is automatic**, so nothing can contradict the user. There is no case where an editor
  deletes a wrong tag, saves, and the tag comes back. That is the single most common way
  auto-tagging becomes hated, and it is structurally impossible here.
- **Matching happens server-side only.** One implementation, in PHP. No second matcher in JS, and
  therefore no risk of the two disagreeing.
- **No JavaScript is written at all.** Core's AJAX framework and Form API do the work.

## Decisions already taken

| Question | Decision |
|---|---|
| Trigger | **Explicit button.** Not automatic on save, not live-as-you-type. |
| Where the list appears | **Inline**, in a container below `field_tags`. Not a modal. |
| Default state | **All suggestions checked.** Unchecking is the deliberate act. |
| Who sees the button | **Everyone**, anonymous included. |
| Where the keyword map lives | **Config object + admin settings form.** |
| Matching sophistication | **Multi-word phrases + per-tag negative matches.** No stemming. |
| Does the location contribute keywords | **No.** Title and body only. |
| Free typing in the tags field | **Unchanged.** `auto_create: true` stays; suggestions are additive. |

## Architecture

```
config: apc_calendar.tag_map          <- single source of truth
        |
        +-- TagSuggester service (PHP)  <- the only matcher that exists
              |
              +-- the Suggest tags AJAX handler   (user-facing, on request)
              +-- the Feeds import path           (no user present; see Collisions)
```

Build the matcher as a **service**, not as a private function in the `.module` file, so the importer
can call it without going anywhere near the form.

## The button

Added in `apc_calendar_form_node_calendar_event_form_alter()`, outside the `isAnonymous()` branch —
everyone gets it, same as the add-location affordance already in that hook.

```php
$form['apc_tag_suggest'] = [
  '#type' => 'submit',
  '#value' => t('Suggest tags'),
  '#limit_validation_errors' => [],   // see below
  '#submit' => ['apc_calendar_tag_suggest_submit'],
  '#ajax' => [
    'callback' => 'apc_calendar_tag_suggest_ajax',
    'wrapper'  => 'apc-tag-suggestions',
  ],
];
```

`#limit_validation_errors => []` matters. Without it, clicking the button on a half-filled form
raises validation errors for every required field the user has not reached yet — including the
location requirement enforced by `apc_calendar_form_node_calendar_event_form_validate_location()`.
Suggesting tags is not submitting the event and must not behave as though it were.

The submit handler reads `$form_state->getValue('title')` and
`$form_state->getValue(['body', 0, 'value'])`, calls `TagSuggester`, stashes the result in
`$form_state`, and calls `$form_state->setRebuild(TRUE)`. The AJAX callback returns **only**
`$form['apc_tag_suggestions']`.

Since the submitter is anonymous some of the time, the button and the list both need labelling that
means something to someone who has never seen the form before — the same standard already applied to
the add-location link and the focal-point crosshair.

### Why the body text arrives intact — measured, not assumed

`textarea.value` is stale while CKEditor 5 is open, which would normally mean reaching into
`Drupal.CKEditor5Instances` from JavaScript. An AJAX **form** button avoids that entirely, and the
mechanism is in core:

- `web/core/misc/ajax.js`, `Drupal.Ajax.prototype.beforeSerialize()` — *"Allow detaching behaviors
  to update field values before collecting them"* — calls
  `Drupal.detachBehaviors(this.$form.get(0), settings, 'serialize')`.
- `web/core/modules/editor/js/editor.js`, `detach(context, settings, trigger)` — *"The 'serialize'
  trigger indicates that we should simply update the underlying element with the new text, without
  destroying the editor."*

So the editor syncs into the textarea before the POST is built, and stays alive. This only holds for
an AJAX button attached to the form. A bespoke JS button calling a controller route would not get
it, and would additionally need CSRF handling that is awkward for anonymous users.

### The one real risk: the server-side rebuild

The callback returns only the suggestions container, but the server rebuilds the **whole** form to
produce it. `field_event_date` (Smart Date), `field_event_image` (Media Library) and the focal-point
widget are composite widgets, and this repo has already lost time to a rebuilt widget displaying a
stale value.

The exposure here is much lower — nothing rebuilt is sent to the browser, so display cannot regress
— but it is not zero. **Prove this on the running site before building anything else** (Sequence
step 1). If Media Library misbehaves during rebuild, that is the point to find out, not after the
map and the settings form exist.

## Applying the selected tags

Do **not** try to inject values into the widget's text string. Act on the built entity instead, in a
handler placed between the two handlers the node form already has:

```php
$form['actions']['submit']['#submit'] = ['::submitForm', 'apc_calendar_apply_suggested_tags', '::save'];
```

By the time it runs, `::submitForm` has built the entity from the widget values, so the handler:

1. Reads the checked suggestion names from `$form_state`.
2. For each, looks up an existing `tags` term by name, **case-insensitively and explicitly** —
   `loadByProperties()` plus a comparison, not a bare `=` query. Do not rely on database collation
   to do this.
3. Creates the term if there is no match. This is where "create dynamically, do not seed" happens,
   and doing it here rather than through `auto_create` means publish status and any future
   provenance marking are under our control.
4. Appends the term IDs to `field_tags` on the entity, skipping any already present so a user who
   both typed a tag and left its suggestion checked gets one reference, not two.

`::save` then saves. No widget internals are touched, and the comma-quoting format of the
autocomplete input is never parsed or rewritten by us.

Note `apc_calendar_taxonomy_term_presave()` forces an initial capital on `tags` and `locations` term
names. Map keys should be written already capitalised, or config will not match what gets created.

## The map

A single config object, `apc_calendar.tag_map`, edited at
`/admin/config/content/apc-calendar/tag-map`:

```yaml
tags:
  Housing:
    keywords: ['housing', 'rent', 'eviction', 'tenant', 'affordable housing']
    exclude: []
  Parking:
    keywords: ['parking']
    exclude: ['no parking']
  'City council':
    keywords: ['city council', 'council meeting']
    exclude: []
```

Needs `config/schema/apc_calendar.schema.yml`. A config object with no schema throws on export.

**Cost of config rather than a module YAML file.** The map becomes a thing that can drift between
the running site and `config/sync` — precisely the failure mode standing rule #1 exists to catch.
Anyone who tunes keywords through the admin form and does not export has made a change that survives
until the next `cim` silently reverts it. **Put a one-line `cst` → `cex` reminder on the settings
form itself.** What this buys is a map that can be adjusted by someone who is not set up to deploy,
which for a vocabulary that will be tuned repeatedly in its first months is worth the exposure.

### Matching rules

- Case-insensitive, whole-word/whole-phrase. `council` must not fire inside `councillor`.
- Phrases match literally with internal whitespace normalised, so `city   council` in a body still
  hits `city council`.
- **Exclusions win outright.** If any `exclude` phrase appears anywhere in the text, that tag is not
  suggested at all. The subtler alternative — masking matched regions before scanning — is more
  correct and not worth the complexity for a suggestion the user can uncheck.
- Source text is `title` + body **as plain text**. Strip tags first, or a tag named after an HTML
  attribute value will fire on markup.
- Keep keywords ASCII. `\b` semantics differ between PCRE with `/u` and other engines; there is only
  one matcher today, but there is no reason to plant that landmine for whoever adds a second caller.

## What happens to tags the user types by hand

Unchanged by this task, but worth recording because it is the behaviour the button is working
around, and it is not what people assume.

The autocomplete **dropdown** searches with `CONTAINS`. But a value typed and saved *without*
selecting from the dropdown is resolved by `EntityAutocomplete::matchEntityByTitle()`, which calls
`getReferenceableEntities($input, '=', 6)` — **exact match only**, scoped to the `tags` bundle:

- **One match** → the existing term is reused.
- **No match** → `auto_create` creates a new term. So `Housings`, `Housing.` and
  `affordable housing` each become new terms alongside `Housing`.
- **2–5 matches** → a form error telling the user to *"Specify the one you want by appending the id
  in parentheses"*. For an anonymous submitter that is a dead end.

Two consequences:

**The case-insensitivity is the database's, not Drupal's.** `=` goes to SQL; `housing` matching
`Housing` is MySQL's collation. On PostgreSQL the same submission creates a duplicate. This is why
step 2 of "Applying the selected tags" above does its own explicit comparison rather than relying on
a query.

**Unpublished terms do not match at all.** `TermSelection::buildEntityQuery()` adds
`$query->condition('status', 1)` for anyone without `administer taxonomy`. §3e notes that an
unapproved term is invisible in the autocomplete; the same condition governs the save-time match. So
**unpublishing a junk tag at `/admin/content/tags-review` guarantees a duplicate** the next time
somebody types that word. For tags, **delete beats unpublish** unless you are confident nobody will
retype the name.

## Interaction with §3e

§3e accepts that an anonymous submitter can create a live, publicly reachable taxonomy term by
typing it into the form, on the reasoning that gating tags produces duplicates faster than it
prevents spam, and that the real mitigation is to seed the vocabulary so people pick instead of
invent.

This task delivers that mitigation **without the seeding step**, and it strengthens §3e's position
rather than changing it:

- A tag taken from a suggestion comes from a curated map, so the common case produces a known-good
  name instead of a submitter's improvisation.
- Free typing still works, so the hole §3e describes is still open. Deliberate and unchanged.
  `/admin/content/tags-review` remains the backstop, and `term_merge` /
  `taxonomy_manager_merge` are already enabled for whatever slips through.
- Suggestions produce no debris: a map entry nobody ever accepts creates no term, so there are no
  empty term pages or dead filter facets — which is exactly what seeding would have produced.

**If taxonomy terms are ever added to the XML sitemap, §3e's risk assessment changes and so does
this one.**

## Rejected approaches

### The previous design for this same task: automatic tagging on save, with a live inline picker

Worth recording in full, because it is what this document originally described and it looked
reasonable.

The plan was: match on every save in `hook_node_presave()`, and mirror the matcher in JavaScript so
a live checkbox list under `field_tags` could show the user what was about to happen. That single
decision — **tagging automatically** — generated all of the following, none of which survives in the
current design:

- A second matcher in JS, the map exported via `drupalSettings`, and a standing requirement that the
  two implementations never diverge (which is what ruled out stemming).
- A hidden `apc_tags_reviewed` flag, needed so that removing a wrongly-inferred tag was not undone
  on the next save — and needed to round-trip through every subsequent edit.
- An argument about whether suggestions should arrive pre-checked, in order to make the JS and no-JS
  paths produce identical results.
- Reading `Drupal.CKEditor5Instances` and watching `model.document` `change:data` on a debounce.
- JavaScript that parsed and rewrote the autocomplete widget's value string, including core's
  quoted-comma format for values like `"Cleveland, Ohio" (23)`. This was flagged in that draft as
  the place the bug would be.

**The root cause was automation, not the picker.** Everything above is scaffolding to make automatic
tagging safe against a user who disagrees with it. Requiring a click removes the user-disagreement
problem entirely, and roughly 150 lines of PHP with no JavaScript replaces ~400 lines across two
languages plus a parity test suite.

### A modal dialog rather than an inline container

`OpenModalDialogCommand` detaches its content into an element **outside** the `<form>`, so form
elements inside it no longer serialise with the form. Working around that means reading the
checkboxes in JavaScript and writing back to the tags input — which reintroduces the widget
string-parsing this design just removed. An inline container has none of these problems and is
strictly less code. Check this section before anyone proposes the modal again; it is the tidiest-
looking option and it does not work.

### A JS button calling a controller route

Loses the `beforeSerialize` → `detach('serialize')` behaviour that makes the body text correct for
free, and needs CSRF handling that is awkward for anonymous users on a possibly-cached form. No
benefit over an AJAX form button.

### Seeding 15–30 terms up front (§3e's own suggestion)

Produces empty term pages and dead filter facets for every tag nobody ends up using. Lazy creation
on first genuine acceptance gives the same anti-fragmentation benefit with none of the debris.

### Deriving the map from each term's description field

Keeps vocabulary and keywords in one place, but requires terms to exist before they can suggest
anything — directly incompatible with not seeding.

### Letting `field_location` contribute keywords

Venue names are proper nouns and mostly noise for topical tags.

### Stemming or fuzzy matching

Rejected under the previous design because PHP and JS have no shared stemmer. With a single PHP
matcher that objection is gone, so this is now a **reasonable future addition** if the map's recall
proves poor. Do not add it speculatively.

### [Autotagger](https://www.drupal.org/project/autotagger) contrib

Does the matching half as configuration; D9/10/11, security-covered, 1.0.4 released Feb 2026.
Rejected on install base (~57 sites) and because it provides no user-facing confirmation step, which
is the point of this task.

### LLM tagging via `drupal/ai` + `ai_automators` (`llm_taxonomy`)

Genuinely better recall on wording a keyword map never anticipated. Rejected *for now*: per-node API
cost and latency, non-deterministic output, and it wants `auto_create` in order to add anything new
— pointing it straight at the fragmentation problem this task exists to reduce. **Revisit once the
map has run long enough to measure its miss rate**, and if adopted, configure it to select only from
existing terms.

## Collisions

**`event-import-task.md`.** Imported events have no form and therefore never see the button. Tag
them by calling `TagSuggester` from a `hook_node_presave()` **gated to imports** — `$node->isNew()`
and a non-empty `feeds_item` — so the automatic path exists only where there is no user to
contradict it. None of the flag machinery from the rejected design is needed for this.

If the ICS feed turns out to populate `CATEGORIES`, map it to `field_tags` in the feed type as well;
source-authored tags beat inferred ones. Measure it against the real Forward TX feed rather than
assuming either way — that feed has been measured once already and had surprises in it.

**No collision with the location-required rule.** That rule is a form validate handler and this task
adds nothing to entity-level validation. Note that `#limit_validation_errors => []` on the Suggest
button is what keeps it from firing prematurely.

## Sequence

1. **Smoke-test the rebuild first.** Add a bare AJAX button to the form that returns an empty
   container, and confirm Smart Date, Media Library and focal point all survive it on the running
   site. Everything else depends on this working.
2. Create the config object, its schema, and the settings form. Populate with a first pass at the
   map.
3. `TagSuggester` service plus a unit test. The test is the cheap place to pin down phrase and
   exclusion behaviour.
4. Wire the button, the submit handler, the AJAX callback and the suggestions container.
5. `apc_calendar_apply_suggested_tags()`, including the case-insensitive existing-term lookup and
   the create path.
6. Import-side call, gated to `feeds_item`.
7. `ddev drush cst`, review the diff, then `ddev drush cex -y`.

## Verify before calling it done

1. Click **Suggest tags** on a form where the body has been typed but never blurred. The suggestions
   reflect the current editor content, not stale text.
2. Click it on an otherwise empty form. **No validation errors appear** for title, date or location.
3. Uncheck a suggestion, save, then edit and save again. The tag does not reappear.
4. Type a tag by hand that is also suggested and leave the suggestion checked. It is referenced
   **once**, not twice.
5. Type a tag matching an existing term in different case (`housing` where `Housing` exists). The
   existing term is reused, and this holds because of our own comparison rather than the collation.
6. Submit as **anonymous** end to end. Button renders, labels read sensibly to a first-time visitor,
   tags land on the unpublished node.
7. Enter a body containing an exclusion phrase (`no parking`). That tag is not suggested.
8. Edit the map, then reload the form as anonymous and confirm the new keywords take effect — the
   node add form should not be serving a cached suggestion set. Honeypot and CAPTCHA probably
   already prevent caching here; verify rather than assume.
9. Run a Feeds import and confirm imported events are tagged without any form involvement.
10. `ddev drush cst`, review the diff, then `ddev drush cex -y`.

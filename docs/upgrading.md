# Upgrading

What an installation has to do — or knowingly not do — when it takes a new
release of this module. Nothing here is automatic: a release that needed a
hand is a release with a note under it.

## Contents

- [The rule for anything this module ships to a host](#the-rule-for-anything-this-module-ships-to-a-host)
- [0.4.1 — four concerns, and two of them are sensitive (BREAKING)](#041--four-concerns-and-two-of-them-are-sensitive-breaking)
- [0.3.0 — the filter dropdowns became the shell's](#030--the-filter-dropdowns-became-the-shells)
- [0.4.0 — the shared taxonomy is dropped](#040--the-shared-taxonomy-is-dropped)
- [0.4.0 — the PostGIS bundle is `utafitilabs/postgis-bundle` (BREAKING)](#040--the-postgis-bundle-is-utafitilabspostgis-bundle-breaking)
- [0.4.0 — a kind's hue is its place in the list](#040--a-kinds-hue-is-its-place-in-the-list)
- [0.4.1 — `incident.details` is dropped](#041--incidentdetails-is-dropped)
- [0.4 line — `incident_taxonomy_kind.colour_key` is dropped](#04-line--incident_taxonomy_kindcolour_key-is-dropped)
- [0.4 line — `incident_taxonomy_subcategory.field_set` is dropped](#04-line--incident_taxonomy_subcategoryfield_set-is-dropped)

## The rule for anything this module ships to a host

**Nothing a host has in its own tree is removed in one release.** A Stimulus
controller, a config key, a template block an installation may have overridden:
the release that stops using it ships it deprecated and inert, and the release
AFTER that deletes it. The reason is mechanical rather than polite — Flex keeps
a host's own `assets/controllers.json` entry when a package it already has is
updated, so anything this package deletes in one step stays switched on over
there with nothing behind it.

## 0.4.1 — four concerns, and two of them are sensitive (BREAKING)

**What changed.** The core replaced flat permission values with **(concern,
verb) pairs**, and this module moved with it. The two values it used to
declare — `incidents.record` and `incidents.manage` — are gone as
*declarations*; what it declares now is four **concerns**, each with the verbs
something here actually enforces, through
`Uhifadhi\Contracts\Access\ConcernSourceInterface` (service
`incident.access.concerns`, tagged `uhifadhi.access.concerns`).

`docs/permissions.md` is the full table. The short version:

| Concern | Verbs |
|---|---|
| `incidents` | read · record · manage · export |
| `incident-vocabulary` | read · configure |
| `case-files` **(sensitive)** | read · manage · delete |
| `case-money` **(sensitive)** | read · manage |

### What an installation must do

**RE-READ THE POSITIONS THAT HELD THE OLD TWO VALUES.** The core's
`Version20260921002000` backfills each position's old `permissions` into the
new `grants` column pair for pair, and the two strings this module used
(`incidents.record`, `incidents.manage`) happen to be legal pairs already — so
they carry across unchanged and nobody is locked out of filing or of moving a
case. **Three things are new and are granted to nobody by the backfill:**

- **`incidents.read`.** Reading the register used to need no permission at all;
  it is a declared power now. **Until somebody is granted it they get a 403 on
  the dashboard, the register and every case file.** Grant it wherever you
  granted reach to the module, which for most organizations is everybody.
- **`incident-vocabulary.read` / `.configure`.** The kinds and lists editors and
  the Settings section used to ride on `incidents.manage`. Grant the pair to
  whoever administers an area's words; it is no longer implied by managing a
  case.
- **`case-files.*` and `case-money.*`.** Reading a case file whole used to be
  implied by reaching the page. Grant `case-files.read` and `case-money.read`
  to restore that, and **withhold either one from the people who should not
  have it** — which is the entire point of the change.

`incidents.export` likewise: the CSV used to be offered to anybody who could
reach the dashboard, and is now its own verb — the same reading, a different
act, because a file leaves the building.

### What a module or a host that integrates must do

- **`IncidentReportController::RECORD_PERMISSION` and
  `IncidentDetailController::MANAGE_PERMISSION` are removed**, along with
  `IncidentTaxonomyController::MANAGE_PERMISSION`,
  `IncidentAreaListController::MANAGE_PERMISSION`,
  `IncidentMoneyController::MANAGE_PERMISSION` and
  `IncidentSettingsController::MANAGE_PERMISSION`. The keys are on
  `Uhifadhi\Incident\Access\IncidentConcerns` and a pair is built with
  `Grant::of(IncidentConcerns::INCIDENTS, Verb::Manage)`.
- **`IncidentModuleProvider::permissions()` returns `[]`.** Keeping the two
  deprecated `ModulePermission` rows beside the four concerns would print this
  module twice in one matrix, once as values nothing enforces.
- **Four services take a different collaborator.**
  `IncidentEvidenceTarget`, `IncidentTransitionToken` and
  `IncidentEvidenceVoter` take `Uhifadhi\Bundle\TeamBundle\Access\Door` in
  place of an `AuthorizationCheckerInterface`; `IncidentDetailController`,
  `IncidentMoneyController`, `IncidentReportController`,
  `IncidentSettingsController`, `IncidentController` and
  `IncidentListController` take none at all, because their gates are
  attributes now. `IncidentTaxonomyController` and
  `IncidentAreaListController` take `%incident.record_screens%` last.
- **A template that overrode the case file or the dashboard.** The record's
  cards are wrapped in `door('case-files.read', area)` and
  `door('case-money.read', area)`, and the filing control now asks
  `recordScreens and door('incidents.record', area)` — the flag alone is the
  installation's half and no longer the whole question.

### Two behaviours tightened, deliberately

- **Evidence bytes on the Files hub answer `case-files.read` on the incident's
  own area.** They used to be readable by anybody signed in. A photograph of a
  suspect on a page the case file itself withholds was the hole this closes.
- **Removing evidence is `case-files.delete`, not the same pair as attaching
  it.** An organization may let a clerk attach photographs without letting the
  same clerk take one off.

### There is no `incidents.delete`

Nothing in this module destroys an incident — a case is resolved and filed, and
a correction is a new event rather than an erasure. A verb is declared where
something enforces it, so a row nothing gates on is a box an administrator can
tick that changes nothing, and it is not declared. It arrives with the first
thing that deletes a record.

### Every AGGREGATE of a withheld fact is withheld too

This is the second half of the change and the half an installation will notice
on screens nobody edited. A register-wide total is the cases' figures added up,
so a figure drawn for somebody the cases are withheld from hands back exactly
what the case files hold back. Seven surfaces now ask the same door the record
asks — `docs/permissions.md` has the table:

- the dashboard's `Fines & compensation` board, its KPI strip's `Money
  outstanding` slot, and the money column of its register — `case-money.read`;
- the dashboard's `Latest evidence` board — `case-files.read`;
- the area overview's `Money outstanding` cell — `case-money.read`;
- a department's `Fines assessed` and `Compensation approved` plates —
  `case-money.read`;
- the performance topic's `Compensation claims` column — `case-money.read`.

**Withheld is a word on the page, never a nought.** A zero is a measurement and
a dash means "we could not measure". The one exception is the performance
matrix: `MatrixCell` has no withheld state, so the column is dropped rather
than borrowing a word that would print a different sentence from the true one.

**AN ORGANIZATION-WIDE FIGURE NEEDS EVERY AREA**, because a wide reading is the
narrow readings added up and one refused area would be smuggled into the total.

**What this means for a host or a module.** `IncidentOverviewContributor`
takes a second argument, `IncidentDoors` (service `incident.access.doors`), and
its `context()` publishes `by.incidents.seesMoney` beside the reading — a
contributed cell is rendered with `with_context: false` and has no area to ask
a door with, so the answer is handed over with the figures.
`IncidentDepartmentKpiProvider` and `IncidentPerformanceTopic` take the same
service (second argument, after the repository/directory), and
`IncidentPerformanceTopic::columns()` takes an optional `bool $withMoney`
defaulting to true. The doors fail closed three ways — no SecurityBundle, no
token, no answer — so a reading taken off a request (a warm-up, a console
command) publishes no money at all.

## 0.3.0 — the filter dropdowns became the shell's

The incidents filter bar now uses the shell's grouped dropdown (`.i-dd`), a
`<details>` the browser opens by itself, instead of this module's own
`.i-dd*` rules and the `incident-filters` Stimulus controller that toggled
them. **No step is required of an installation**, and the bar keeps working
either way; two things are worth knowing.

**The dropdowns read slightly differently.** The shell's chosen-option style
colours the label accent where this module tinted the whole row, the trigger
label is clipped at 15 characters, and the caret no longer rotates. That is the
point of the move — one control, one appearance, everywhere in the product —
and it is not something to report as a regression.

**`incident-filters` is deprecated and does nothing.** It is still shipped, now
`"enabled": false` by default, so an installation that already enabled it
cannot break on this update; a fresh install does not enable it at all. **It is
deleted in 0.4.0**, together with its `assets/package.json` entry. An
installation that wants to be done with it now can drop the
`@uhifadhi/incident-module/incident-filters` entry from its own
`assets/controllers.json`; nothing in this module mounts it.

## 0.4.0 — the shared taxonomy is dropped

`Uhifadhi\Incident\Migrations\Version20260919210000` collects the deferral
0.3 opened: `incident.subcategory_id`, `incident_subcategory` and
`incident_category` are dropped, the referencing key and its index first, then
the column, then the key between the two tables, then the tables. It is marked
`@destructive` and runs with `doctrine:migrations:migrate` like anything else.
**No step is required of an installation** — every record has been filed
against its own area's word since 0.3, in
`incident.taxonomy_subcategory_id`, and what goes here is the copy nothing has
read since.

**If you want to keep what the old tables held, dump them before you migrate**
— `pg_dump -t incident_category -t incident_subcategory …` — because a
`down()` brings the tables back empty and no rollback brings the rows back.

**And `doctrine:migrations:diff` goes quiet again.** Until this release it
proposed dropping those three things on every run, because the mapping had
already let them go. If an installation generated that proposal and kept the
file, **delete it**: it plans `DROP TABLE incident_subcategory` while
`incident.subcategory_id` still references the table, PostgreSQL refuses with
*cannot drop table incident_subcategory because other objects depend on it*,
and the failure blocks every later version behind it. This release's version
does the same job in the order the database accepts.

## 0.4.0 — the PostGIS bundle is `utafitilabs/postgis-bundle` (BREAKING)

The spatial types this module's points are stored in now come from
`utafitilabs/postgis-bundle` instead of `fundistadi/postgis-bundle`. The two
register the same DBAL types under the same names, so **no column, no index
and no stored geometry changes, and there is no migration**. What changes is
the class an installation registers and the config key it would configure it
under:

| | before | after |
|---|---|---|
| package | `fundistadi/postgis-bundle` | `utafitilabs/postgis-bundle` |
| namespace | `FundiStadi\PostGISBundle\` | `UtafitiLabs\PostGISBundle\` |
| bundle class | `FundiStadiPostGISBundle` | `UtafitiLabsPostGISBundle` |
| config key | `fundi_stadi_post_gis` | `utafiti_labs_post_gis` |

What an installation does:

```diff
 // config/bundles.php
-FundiStadi\PostGISBundle\FundiStadiPostGISBundle::class => ['all' => true],
+UtafitiLabs\PostGISBundle\UtafitiLabsPostGISBundle::class => ['all' => true],
```

…then `composer update`, and rename `config/packages/fundi_stadi_post_gis.yaml`
and its root key if the installation wrote one (most have not: the bundle
needs no configuration). **Register only one of the two.** Both declare the
same type names, and Doctrine refuses a type registered twice.

Own code that extends `SpatialEntityRepository` or type-hints a geometry type
changes its `use` line and nothing else — the class names below the namespace
are unchanged.

## 0.4.0 — a kind's hue is its place in the list

**This module declares no colour from this release.** A kind used to carry a
`colourKey` an administrator picked from a dropdown of four, and this module's
stylesheet turned that key into one of five hues it stated itself. Both halves
are gone. A kind now wears the hue its POSITION in the area's list points at —
first kind, first hue — reached through the shell's nine `--cat-1..9` and the
`[data-cat="1".."9"]` rules that resolve them, so the chip, the dot, the donut
arc, the legend square, the matrix row and the map pin are one decision and the
product has one palette.

**No step is required of an installation.** What changes on screen:

- **A kind's hue may move.** It is now read off the order of the kinds, and the
  nine house hues are not the four this module used to state. Reordering the
  kinds in *Incident kinds* moves the hues with them, which is the only control
  there is over which kind wears which.
- **The colour dropdown is gone** from *Incident kinds*, and from the create
  form under it. In its place the kind's swatch is SHOWN, read-only, in the
  manager's head and beside every kind on the `Settings` configure section —
  which can draw it now that the swatch is the shell's `.catsw` rather than
  this module's.
- **`POST …/kinds/{uuid}/colour` (`incident_kinds_kind_colour`) is removed.**
  Nothing in the product posted to it but a form this release deletes; an
  installation that scripted it has nothing to send it.
- **The area overview's two incident layers are states, not categories.**
  `Open` wears `--warn` and `Resolved & closed · 30 days` wears `--ok`, which
  is what those tokens mean everywhere else in the product. The nine house
  hues are for the words an area wrote, and neither of those is one.
- **Money is the accent**, on the money card, the money fold, the claimant role
  and the money chip. The fifth token this sheet used to state had, in both
  themes, the same value as the accent, so nothing moves.
- **`Uhifadhi\Incident\Model\IncidentHues` is deleted.** Where a platform
  contract still takes a colour STRING — the atlas layer's `swatch`, the
  overview's `MapLayer` and `PulseEvent` — this module now hands over the house
  token BY NAME (`var(--cat-3)`), which the host resolves and the shell's plate
  rules repaint for imagery. `Uhifadhi\Incident\Model\HousePalette` is where
  that string is written, once. **It is a workaround and it is flagged**: those
  three fields are `string` colours (`GeoJsonLayer::$swatch`,
  `MapLayer::$swatch`, `PulseEvent::$swatch`), and when they take a category INDEX the
  way `AreaNavChild::$cat` and `ChartSeries::$cat` already do, `HousePalette`
  goes away.
- **The last two colour values are gone too.** `--crit` — the fourth step of
  the severity alarm ramp — and the scrim under the filing bar were stated in
  this sheet because the shell shipped no token for either. It ships both now,
  so the sheet spends `var(--crit)` and `var(--scrim)` and states no colour at
  all, with no exceptions. **This module therefore needs a core that ships
  them**; an older one leaves the critical chip and the filing bar's scrim
  falling back to inherited values.
- **The token bridge is gone from `incidents.css`.** The sheet restated a dozen
  of the shell's own aliases in a `:root` block and, loading last, won with
  them — `--shadow` among them, stated once where the shell states two, so the
  light theme wore the dark theme's shadow on every page an incidents screen
  was on. The shell's are now the only copy.

`Uhifadhi\Incident\Migrations\Version20260919230000` drops the `NOT NULL` on
`incident_taxonomy_kind.colour_key`, because nothing writes it any more. **The
values stay**, so an installation can still read what each kind used to be set
to, and a rollback to 0.3 finds them where it left them.

## 0.4.1 — `incident.details` is dropped

**Take a dump first if you ever read the column yourself.**

**What changed.** `Uhifadhi\Incident\Migrations\Version20260924000000` runs
`ALTER TABLE incident DROP details`, marked `@destructive`. The property that
mapped it, `getDetails()` and `setDetails()` are gone with it.

**Why.** An incident keeps its answers per behaviour block —
`incident.block_answers`, in the shape the block asks in. `Version20260912103000`
moved every answer with a block to go to into that column and named this one as
what the next release drops. This is that release.

**What goes with it.** That version deliberately left two kinds of key where
they were: a key whose name does not say what it counted (`quantity` answered
"3 sacks, dried" under one word and "2 animals" under another) and a key no
block asks for at all (`enclosure`, `crop`, `circumstances`, `signs`). **Those
answers are gone after this migration.** Nothing on any screen read them, and
choosing a block for them would have been this module writing somebody's
record. If an installation wants them, run

```
pg_dump -t incident --data-only > incidents-before-the-drop.sql
```

before upgrading.

**Rolling back.** `down()` re-adds the column, fills every row with the empty
object and tightens it, so a rollback lands on a schema the previous release
can run. It does **not** put the answers back — they are not derivable from
`block_answers`, and a guess written into a record is worse than a gap.

## 0.4 line — `incident_taxonomy_subcategory.field_set` is dropped

Ships in the first release on the 0.4 line after 0.4.1.

**What changed.** `Uhifadhi\Incident\Migrations\Version20260925000000` runs
`ALTER TABLE incident_taxonomy_subcategory DROP field_set`, marked
`@destructive`. The property that mapped it, `TaxonomySubcategory::getFieldSet()`
and `setFieldSet()` are gone with it. No index or constraint rode on the column.

**Why.** A word's questions are the questions of the behaviour blocks it
switches on — `incident_taxonomy_subcategory.blocks`. `Version20260912103000`
stopped every write to this column and named it as what a later release drops;
nothing on any screen read it. This version collects the second half of that
deferral, in a version of its own, so that a rollback of either drop is a
rollback of one thing.

**If you read the column yourself**, take a dump before upgrading:

```
pg_dump -t incident_taxonomy_subcategory --data-only > subcategories-before-the-drop.sql
```

**Rolling back.** `down()` re-adds the column, fills every row with the empty
list and tightens it, so a rollback lands on a schema the previous release can
run. It does **not** put the lists back — they are not derivable from
`blocks`.

After this version `doctrine:migrations:diff` proposes nothing: every column
`Version20260912103000` deferred has been dropped.

## 0.4 line — `incident_taxonomy_kind.colour_key` is dropped

Collected: `Version20260921000000` drops the column with an `@destructive`
marker, and the drift lock (`MigrationsCoverSchemaTest`) tolerates nothing.
An installation that wants what the column held takes
`pg_dump -t incident_taxonomy_kind` before migrating; rolling the version back
restores the column empty.

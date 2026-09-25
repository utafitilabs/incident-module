# uhifadhi/incident-module

What happened in an area, recorded once: poaching, human–wildlife conflict
(with the fines and compensation that follow), compliance and encroachment, and
wildlife mortality. A [uhifadhi](https://github.com/uhifadhilabs) module bundle.

## Contents

- [What it is](#what-it-is)
- [Installation](#installation)
- [Getting started](#getting-started)
- [The maps](#the-maps)
- [Upgrading](#upgrading)
- [Learn more](#learn-more)
- [License](#license)

## What it is

An **incident** is one event, in one area, at one place, in one category, at one
point in a five-state workflow — `reported → verified → in progress → resolved →
closed`. One record type serves every reader: Protection and Ecology read
subsets of one taxonomy rather than each keeping their own copy.

The module ships nine `incident*` tables, the report flow, the case file, a
sixteen-widget dashboard surface composed on the shell's widget machinery, and
the **Incident kinds** editor each area writes its own classification in.

**An area starts empty.** The module ships no kinds of incident, seeds none and
suggests none: a kind, the departments a lens puts it in front of,
and under it the sub-categories — their behaviour blocks, which way money runs
and the term each promises — are all the area's own, written in the kinds editor
before the first incident is filed there. **What a sub-category's form asks is
not among them:** the questions come from the behaviour blocks it switches on and
from nowhere else, so nothing in the product invents a field and there is no form
builder (see `docs/the-model.md`). Sample
kinds exist only in the devkit demo content, which writes them into an area
through that same editor's service.

## Installation

```bash
composer require uhifadhi/incident-module
```

This module requires the core (`uhifadhi/uhifadhi`) and the evidence store (`uhifadhi/storage-module`); all three are on Packagist, so an installation names nothing.

Then the installation's four commands, the same four after every change to it:

```console
php bin/console cache:clear --no-warmup
php bin/console doctrine:migrations:migrate
php bin/console registry:sync
php bin/console cache:warmup
```

This module ships the migrations for the tables it owns and registers their path itself, so `migrate` runs them and an installation writes no version for them; `registry:sync` then enters the module in the catalogue and gives every area its row, and prints what it added, kept and retired; `doctrine:migrations:diff` stays reserved for the installation's own entities and must report no changes after this. In development AssetMapper serves the module's stylesheets and scripts from source; the production image compiles them.

The bundle registers via Flex (`"type": "symfony-bundle"`), which adds
`Uhifadhi\Incident\UhifadhiIncidentBundle` to `config/bundles.php`.

### Switching it on

A module is installed but **parked**: every page of it answers 404 in an area that has not taken it. An administrator switches it on per area from that area's module grid, and grants the module's permissions to the positions that need them from the positions screen. Reading needs the module's `read` grant; nothing else is required to see it.

## Getting started

Then, in the host:

1. **Answer the user contract.** Five columns name a person — who reported the
   incident, who it is assigned to, who acted on the event, who linked it to
   another, and the team member behind a party to it — and none of them names an
   account class. They are mapped to
   `Uhifadhi\Contracts\Entity\UserInterface`, and the installation resolves
   that interface to whatever it calls its people. Install
   the core (`uhifadhi/uhifadhi`) and the answer arrives with it — TeamBundle
   states the resolution from its own bundle; otherwise write one line naming
   your own class, under the `orm:` key already in
   `config/packages/doctrine.yaml`:

   ```yaml
   doctrine:
       orm:
           resolve_target_entities:
               Uhifadhi\Contracts\Entity\UserInterface: App\Entity\Person
   ```

   Until something answers it, the bundle installs and the kernel boots, but
   anything that walks the metadata stops on the unresolved interface. Deleting
   an account later sets those five columns null and leaves the incidents
   standing, which is why each of those records keeps the person's name beside
   the relation.
2. **Migrate.**

   ```bash
   bin/console doctrine:migrations:migrate
   ```

   That is the whole step. This module ships the statements that create its
   tables, so there is no mappings block to write and nothing to generate:
   `doctrine:migrations:diff` is what an installation runs for the entities IT
   owns, and after installing or updating this package it must report no
   changes. The versions add nine `incident*` tables and nothing else; they alter
   no host table, and the foreign keys into `area_of_interest`, `zone` and
   `team_user` are declared here rather than in the core.
3. **Write the area's kinds.** Open *Incidents → Incident kinds* in each area
   (permission `incident-vocabulary.configure`) and name what that area files. Nothing is
   seeded, so this is the step between installing the module and filing the first
   incident; a bundle that wrote somebody's classification scheme into their
   database on boot would be making that decision for them. In a development
   installation, `bin/console fixtures:demo` writes a month of sample incidents
   and the kinds they are filed under.
The Stimulus controllers — `incident-board`, `incident-report` and the rest;
`incident-filters` is deprecated, inert and off by default, and goes in 0.4.0
(see [Upgrading](docs/upgrading.md)) — need no step of their own: Flex synchronises
`assets/controllers.json` from this package's own `assets/package.json` on every
`composer require`/`update`, because the package declares the `symfony-ux`
keyword.

Everything this module binds to arrives in ONE package, `uhifadhi/uhifadhi` —
the core, whose five bundles are what these screens stand on: AreaBundle for the
area an incident happens in and its zones, ShellBundle for the page frame and
the widget machinery the dashboard is, AtlasBundle for the maps, RegistryBundle
for the per-area catalogue this module registers itself
in, and TeamBundle for the account class. The contracts it implements ship
inside it. Two further packages are required: `uhifadhi/storage-module` stores
the photographs an incident is filed with and puts them on the Files hub, and
`utafitilabs/postgis-bundle` is where an incident's point and its evidence's
point are stored — the spatial types, the GiST indexes and the `ST_*` DQL this
module's repositories ask in.

The one thing an installation still provides is the ACCOUNT CLASS behind the
person contract — see the user contract above. TeamBundle answers it from its
own bundle; an installation with an account class of its own names it in one
line of `resolve_target_entities`.

**Icons need nothing imported.** This module registers its own set and draws
under two prefixes only: `incident:`, answered by the glyphs it ships in
`assets/icons/incident`, and `shell:`, answered by the core. No `lucide:` name is
drawn from here, so a deployment with on-demand fetching off — which is what a
deployment configures — renders every mark on these pages.

## The maps

**Maps come from the atlas.** This module ships no map JavaScript, no Leaflet and
no chrome: it states what is on a plate in PHP and renders it with one Twig call.

```php
// src/Service/IncidentMapService.php
$map->addLayer(new GeoJsonLayer(
    id: 'incident.'.$kind['slug'],
    label: $kind['label'],
    features: $collection,
    swatch: HousePalette::token($kind['cat']),
    shape: LayerShape::Point,
    count: \count($own),
    group: IncidentMapService::GROUP,
));
```

```twig
{{ render_map(dashboard.map, {'role': 'img', 'aria-label': 'Where every incident was filed'}, filters) }}
```

One layer per category in the house hue that category's PLACE in the area's
list points at — this module names no colour, it names a position and the shell
resolves it — each legend row a switch; the
area's zones underneath, wearing their names; the boundary in the platform's one
treatment. The imagery, the control stack, the floating legend and fullscreen are
the atlas's, which is why an incident map, a patrol map and the area map read
identically. The third argument is this module's filter row, rendered one row
above the map and inside the plate, so the chips stay a row in fullscreen.

The full API is [the atlas components](https://github.com/utafitilabs/uhifadhi/blob/main/src/Uhifadhi/Bundle/AtlasBundle/docs/components.md).

## Upgrading

```bash
composer update uhifadhi/incident-module
bin/console doctrine:migrations:migrate
```

Again, `migrate` is the whole of it. New tables and columns arrive as versions
in this package; `doctrine:migrations:diff` stays what you run for your own
entities, and after this update it must report no changes. If it does report
something, that is a bug in this package — please report it rather than
committing the version it wrote.

### Upgrading to 0.3: one taxonomy, and it is the area's

`Uhifadhi\Incident\Migrations\Version20260911140000` converges the
installation-wide classification onto the per-area one. It runs with `migrate`
like any other version and needs nothing from you, but it is worth knowing what
it does, because it moves data rather than only schema:

- Every area that has filed an incident is given **its own copy** of exactly the
  words its incidents reference — the same wire-code as the old slug, the same
  label, colour, money direction, term and fields. Two areas that shared a slug
  end up with two rows, and from here their vocabularies move independently.
- `incident.taxonomy_subcategory_id` is added, filled by (area, slug), and made
  required once every row has found its area's copy. By construction every row
  does: the copies are generated from the rows the incidents point at. If any
  row were left over the column stays optional, the migration raises a warning,
  and `incident.subcategory_id` still holds what those incidents were filed
  against — point them at one of the area's sub-categories and tighten the
  column by hand.
- **Nothing is dropped.** `incident.subcategory_id`, `incident_subcategory` and
  `incident_category` are kept, still populated, for one release, so you can read
  what a record used to say and can roll the code back. A later release drops all
  three in a version marked `@destructive`.
- **That release is 0.4** — `Version20260919210000`, above. Between the two,
  `doctrine:migrations:diff` proposed dropping those three things, because the
  mapping no longer knew them; that proposal was the deferral working, not
  drift, and applying it fails. From 0.4 there is nothing left to propose.

### Upgrading to 0.4: the shared taxonomy is dropped

`Uhifadhi\Incident\Migrations\Version20260919210000` drops
`incident.subcategory_id`, `incident_subcategory` and `incident_category` — the
release the convergence below deferred them to. Nothing is asked of you, and
after it `doctrine:migrations:diff` reports no changes again. If you generated
that drop yourself while it was deferred, delete the version you generated: it
plans the `DROP TABLE` before the key that references it and fails, blocking
every later version. See [Upgrading](docs/upgrading.md).

### Upgrading to 0.3: a word's questions come from its blocks

`Uhifadhi\Incident\Migrations\Version20260912103000` moves every answer a
record carries into the shape the behaviour blocks ask in
(`incident.block_answers`) and adds `incident.claimed_at_filing` for the figure
the money block asks at filing. It runs with `migrate` and needs nothing from
you; like the version above it moves data, so it is worth knowing what it does:

- Every answer whose key **name** says what it answered arrives in the block that
  asks it: `snares_lifted` becomes a count row, `road_segment` becomes the kind
  and the name of a place, `suspects` becomes a party row in the suspect's role.
  The repeating blocks — counts, parties, seizures, samples, casualties, measures
  — arrive as rows, in the order the migration's own table names them, so two
  installations upgrade identically.
- **Two kinds of key are deliberately left where they are.** A key that does not
  say what it counted (`quantity` answered "3 sacks, dried" under one word and
  "2 animals" under another) and a key no block asks for at all (`enclosure`,
  `crop`, `circumstances`, `signs` — they came from the retired free-text field
  list). Choosing a block answer for either would be writing the record.
- **Nothing is dropped by this version.** `incident.details` and
  `incident_taxonomy_subcategory.field_set` are dropped by later versions on the
  0.4 line, each marked `@destructive`: 0.4.1 drops `incident.details`
  (`Version20260924000000`), and the left-behind keys go with it; the release
  after it drops `field_set` (`Version20260925000000`). From there
  `doctrine:migrations:diff` has nothing to propose. See
  [Upgrading](docs/upgrading.md) for the dump to take first.
- The figure is **not** backfilled from a money record. What a money record holds
  was written by whoever assessed or approved it, in a state past filing; dating
  somebody else's figure to a moment nobody recorded it at would be a lie about a
  number.
- After the update the **kinds editor no longer asks for a list of field names**,
  and the report form asks exactly what each sub-category's blocks ask. An area
  whose words were switching on no blocks will see a short step 2 until somebody
  ticks them: the blocks were always the model, and they are now the whole of it.

If your `config/packages/incident.yaml` carries an `incident.taxonomy` tree, the
container refuses to build until you remove the key, and says so in those words.
The tree is not silently ignored, because a deployment that lost its
classification scheme would find out on the first filing screen. Your areas keep
the words they were using: the migration above copied them in.

Before a production run:

```bash
# 1. Back up. Nothing below is a substitute for this.
pg_dump …

# 2. Read what will run, without running it.
bin/console doctrine:migrations:migrate --dry-run
```

Two hatches, for the two ways this goes wrong:

- **An installation that already has the `incident*` tables** — created by a
  `diff` written before this package shipped its own versions — must tell the
  version log they are there, or the first version will try to create them
  again:

  ```bash
  bin/console doctrine:migrations:version \
      'Uhifadhi\Incident\Migrations\Version20260910045214' --add
  ```

  That marks the version executed without running it. Check the table list in
  `docs/the-model.md` against your database first.

- **A deployment that applies SQL by hand** — a reviewed change window, a
  database somebody else administers — takes the statements instead of the run:

  ```bash
  bin/console doctrine:migrations:migrate --write-sql=incident-upgrade.sql
  ```

## Learn more

- [Charter](docs/charter.md) — one record type and many readers, why departments
  are a lens and never a fence, and why the dashboard rides the shell's framework.
- [The model](docs/the-model.md) — the nine tables, and the rules about money (two
  directions, each recorded from its own place in the workflow), filing and
  provenance that somebody will otherwise re-argue.
- [The workflow, and the definition under it](docs/workflow.md) — the five places,
  their guards, and how `IncidentWorkflow` maps one-to-one onto a Symfony
  `state_machine`.
- [Screens](docs/screens.md) — the routes, why the five design directions are
  presets rather than pages, and the query string another module files with.
- [Permissions](docs/permissions.md) — the two declared permissions and the
  sentences the permission matrix prints under them.
- [Configuration](docs/configuration.md) — `config/packages/incident.yaml`, the
  taxonomy tree, and what `leads` does and does not decide.
- [Evidence on the Files hub](docs/files-hub.md) — the
  `uhifadhi/storage-module` contract, and what this module honestly knows about a file.
- [Dev tooling](docs/dev-tooling.md) — the demo month this module declares for
  devkit to seed, the two commands that stay, and what the declaration cannot
  write yet.
- [Development](docs/development.md) — `composer check`, the tooling levels, and
  the real-PostGIS test suites.
- [Upgrading](docs/upgrading.md) — what a release asks of an installation, and the
  two-release rule for anything this module ships into a host's own tree.

## License

**AGPL-3.0-or-later** — see [LICENSE](LICENSE): the same license as the
uhifadhi host this module plugs into. Use, modify and self-host freely; if you
offer a modified version to users over a network, they are entitled to the
source of what they're running.

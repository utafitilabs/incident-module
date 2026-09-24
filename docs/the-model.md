# The model

## Contents

- [The nine tables](#the-nine-tables)
- [Money runs in two directions](#money-runs-in-two-directions)
- [No money record is opened at filing](#no-money-record-is-opened-at-filing)
- [Each direction is recorded from its own place](#each-direction-is-recorded-from-its-own-place)
- [A sub-category's questions come from its blocks](#a-sub-categorys-questions-come-from-its-blocks)
- [One taxonomy, and it is the area’s](#one-taxonomy-and-it-is-the-areas)
- [How this module references areas](#how-this-module-references-areas)
- [The figures a zone publishes](#the-figures-a-zone-publishes)
- [The figure a station publishes](#the-figure-a-station-publishes)
- [Provenance is written once](#provenance-is-written-once)

## The nine tables

An **incident** is one event, in one area, at one place, filed against one of
that area's own sub-categories, at one point in a five-state workflow.

| Thing | Table | Why it exists |
|---|---|---|
| `Incident` | `incident` | The event. One area, one PostGIS point, one sub-category, one place in the workflow. |
| `TaxonomyKind` / `TaxonomySubcategory` | `incident_taxonomy_kind`, `incident_taxonomy_subcategory` | The taxonomy, and it is **each area's own**. An area starts **empty** — nothing is shipped or seeded — and writes its kinds in the Incident kinds editor. A kind carries the departments a lens leads with, and wears the house hue its place in that list points at rather than one anybody chose; a sub-category composes **behaviour blocks** — which are the whole of what its form asks — says which way money runs and what term it promises. An incident's answers to those blocks live in `incident.block_answers`, and the figure the money block asks at filing in `incident.claimed_at_filing`. Wire-codes are unique per area and never change; retirement **deactivates, never deletes**. |
| `IncidentEvent` | `incident_event` | The **append-only** timeline. Nothing on it is ever edited or removed; a correction is a new event saying what was corrected. |
| `IncidentEvidence` | `incident_evidence` | Photographs and documents, each keeping **its own** capture time and position — never the upload's. |
| `IncidentParty` | `incident_party` | A suspect, a claimant, a witness, the ranger who filed it — and the **animal**. One shape, different roles; the design refuses to build four tables. |
| `IncidentMoney` | `incident_money` | Four amounts (claimed, assessed, approved, settled) in **one** direction. |
| `IncidentLink` | `incident_link` | "These two are related" — and a link is a claim, so it carries who made it. |

Three rules are worth stating in prose, because each is a decision somebody will
otherwise re-argue:

## Money runs in two directions

**Money runs in two directions and is never added together.** A *fine* is owed
TO the authority; a *compensation claim* is owed BY it. Which direction — if any
— an incident can carry is the **sub-category's** business, which is how roadkill
carries a fine while natural mortality beside it carries nothing.

## No money record is opened at filing

**No money record is opened at filing** — not even where the money block asked a
figure there. A sub-category that *carries* money is one whose form asks the
question; that is not a claim that this incident involves any. The figure a filer
gives is the claimant's own, or the officer's at the roadside, and it is kept as
`incident.claimed_at_filing`: the case file shows it as **claimed at filing** and
says out loud that nothing has been judged. The money row appears when somebody
records an amount — which is also when the
case file's money card appears, and why a roadkill where no driver was ever
identified can still be resolved rather than waiting forever for a payment nobody
is making.

## Each direction is recorded from its own place

**A compensation claim is recorded from `verified`; a fine only from
`in progress`.** The two directions are not the same kind of act, so they do not
wait for the same thing. A claim is somebody else's statement — a household asks
for compensation the moment the authority agrees the thing happened, and a
product that would not write it down until a responder had been assigned would be
losing a claim it has already been handed. A fine is the authority's own act:
assessing a penalty is enforcement, which is the work `in progress` names, and
fining somebody while the report is still only a report would be fining them on
the strength of an allegation.

`MoneyDirectionEnum::recordableFrom()` holds both answers, and it is the single
source the case file's money panel is gated on and the money service refuses on —
so the panel is never drawn where a POST would be refused. Each direction also
refuses in its own words (`refusedTooEarly()`): a claimant told "response has not
started" would be told the wrong rule.

## A sub-category's questions come from its blocks

**A sub-category's questions are the questions of the behaviour blocks it
switches on, and of nowhere else.** Tick a block in the kinds editor and its
questions join step 2 of the report form; untick it and they are gone. Nothing in
the product invents a field, no screen types a question, and there is no form
builder.

`src/Model/BlockQuestionCatalogue.php` is the whole vocabulary: per block, each
question's label as the filer reads it, the control it is drawn in
(`QuestionControlEnum` — a styled select, a number with its unit, a yes/no pair, a
date, a text field, or the select that reads one area's own list), the key the
answer is kept under, and whether it **holds up a filing**. It is code rather than
data for three reasons: a question is behaviour the form has to know how to draw,
an answer has to be worth counting across areas, and a block's defining question
has to be the same one everywhere or "needed to file" means nothing.

| Block | Asks | Gates a filing |
|---|---|---|
| Species | species, sex, age class | the species |
| Counts | what was counted, how many — **a row that repeats** | one whole row |
| Method & means | method, gear, vehicle, suspected agent, activity, operator | the method |
| Parties | role, name, contact, household, ID number — **rows** | a row with a role and a name |
| Seizures | item, how many, description, custody reference — **rows** | a row with an item and a count |
| Money | one figure, named by the direction | the figure |
| Condition & disposition | condition, disposition | the condition |
| Samples | type, reference, sent to, date sent — **rows** | a row with a type and a reference |
| Casualty & treatment | injury, how many people, treatment, facility, date — **rows** | a row with an injury |
| Extent | the measure with its unit and its value — **rows** — plus land use | a row with a measure and a value |
| Named place | kind of place, which one, or a typed name | the kind and the name |
| Notice & licence | permit status, licence status, notice served, reference, date | both statuses |

Everything not in the last column is **paperwork and can wait**: contacts, ID
numbers, custody references, dates sent, facilities. A block that is on and
records nothing is worse than a block that is absent, which is why the defining
answers gate — and the paperwork never does, because a half-remembered report that
exists beats a perfect one that was never filed.

**Where the answers live.** `incident.block_answers` keys them by block: a block
whose questions are asked once keeps `{key: answer}`, a block that repeats keeps
`{rows: [{key: answer}, …]}`. `IncidentBlockAnswerService` reads a posted form
against the catalogue and decides what is still missing — the browser says the
same thing sooner and is not the authority.

**Four questions read a per-area list** (`AreaListEnum`): which animal, by what
method, what the ground is used for, which named place. **No screen edits those
lists yet**, so the form draws the area's list — empty — beside a typed `other`; a
hardcoded animal would be one area's vocabulary written into every area on earth.
The editors are the next thing this module owes the form.

## One taxonomy, and it is the area’s

**An incident is filed against its own area's words.** `Incident.subcategory`
points at a `TaxonomySubcategory`, whose kind belongs to the same area; two areas
that use the same wire-code hold two rows, and neither can reach the other's.
There is no installation-wide vocabulary behind it and nothing is seeded on
install, which is why a new area's kinds section opens empty and the demo's four
kinds arrive only through devkit.

The admin's "copy from another area" gesture is
deliberately deferred: it needs to enumerate areas and read their names — which
`Uhifadhi\Contracts\Entity\AreaInterface` now exposes (`getName`,
`getUuidString`, and enumeration through the ORM against the interface; see
[the core's `area-contract.md`](https://github.com/utafitilabs/uhifadhi/blob/main/src/Uhifadhi/Contracts/docs/area-contract.md)) —
but the gesture itself is not yet ruled, and the empty-state template marks the
contract.

## How this module references areas

**How this module references areas.** `Incident::$area` and the area-scoped
`TaxonomyKind` are mapped to the concrete `Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest`,
not to the `AreaInterface` contract. The contract lets a module point at an area
*without* requiring AreaBundle — the way TeamBundle's `Department` does — but
this module already hard-requires AreaBundle for its PostGIS points, zones and
overview contributions, so the concrete class costs it nothing it was avoiding and keeps
the area's own accessors in hand. Loose coupling through the interface is the
right call for a module that has no other reason to depend on AreaBundle; that is
not this one.

## The figures a zone publishes

**A zone's figures are decided by the ground, not by the stamp.**
`Uhifadhi\Incident\Module\IncidentZoneFigureProvider` answers the core's
`Kpi\ZoneFigureProviderInterface` for every zone of an area in one call — three
queries however many zones are asked about — and each figure counts the
incidents whose **point falls inside the ring** (`ST_Contains(zone.geom,
incident.position)`), read from AreaBundle's `Zone` geometry. `incident.zone_id`
is what the locator wrote at filing; these figures are not read from it, so an
area that redraws its zones reads its history through the new geography at once.
A point inside no zone counts for no zone — it is still the area's, and the
area's own figures still hold it. `position` is `NOT NULL`, so there is no
incident without one.

| Key | What it is | Unit |
|---|---|---|
| `incidents` | Incidents **filed** in the period whose point is in the zone. | count |
| `incidents_open` | How many on that ground were still open **at the instant the period closed** — filed before it, neither resolved nor closed by then. Whenever they were filed: a backlog is not a month's filings. | count |
| `incidents_fine` | Fines on incidents filed in the period on that ground, summed as `IncidentMoney::payable()` defines (approved, else assessed, else claimed). Absent where nothing there carries a fine. | the configured currency |
| `incidents_compensation` | The same, for compensation. Two plates, never one: the two directions are never added together. | the configured currency |

The keys, labels and money definition are the **department seam's own**, so a
zone card and a performance plate cannot disagree about what a fine was. Nothing
here is scored against the month before: `DepartmentKpi::direction()` reads
every figure as better when larger, which is true of filings and false of a
backlog. The answer states the period it measured, which is the period asked
for — every figure is read off timestamps this module records itself. A zone
whose ground held neither a filing nor an open incident is **left out** of the
answer rather than published at zero, and an area that has never had an incident
is answered with `ZoneFigures::none()`. `ZoneFigureProviderInterface::COVERED` is
not published: incidents are points, and a point covers no ground.

## The figure a station publishes

**A post is a point, so its figure is a distance.**
`Uhifadhi\Incident\Module\IncidentStationFigureProvider` answers the core's
`Kpi\StationFigureProviderInterface` for every station of an area in one call —
two queries however many posts are asked about — and what it counts is the
incidents whose **point lies within 12 km of the post's point**
(`ST_DWithin(incident.position::geography, station.point::geography, 12000)`,
metres on the spheroid), read from AreaBundle's `Station` geometry and narrowed
first to the post's own area. Not the incidents somebody posted there recorded,
and not the ones stamped with the post's zone: who filed a row and which ring it
fell in are other questions with their own seams. **A radius is not a
partition** — two posts 15 km apart share the ground between them, so one
incident may count for both, and these counts are never summed into an area
total.

**The radius is one named constant**, `IncidentStationFigureProvider::NEAR_M`
(12 000 m), read by both the query and the caption so the number the dock prints
cannot drift from the number it was measured with. Changing it changes both at
once; nothing else in the module writes a distance.

| Key | What it is | Unit |
|---|---|---|
| `StationFigureProviderInterface::HEADLINE` | Incidents **filed** in the period whose point is within 12 km of the post. | count |

**One key, because the dock draws one row per module.** Everything else it has
to say goes in the caption — `within 12 km · 1 open` — where the open count is
how many incidents within the radius were still open **at the instant the period
closed**, whenever they were filed: a backlog is not a month's filings. The
period answered is the period asked for, every figure being read off timestamps
this module records itself. No figure carries a URL: the core resolves the
dock's `Open →` from this module's entry route and its slug. Nothing is scored
against the month before (`previous` stays null) and `areaName` stays null — a
station figure is nobody's share of a roll-up. A post with neither a filing in
the period nor an open incident at its close is **left out** of the answer
rather than published at zero, and an area whose posts saw nothing is answered
with `StationFigures::none()`.

## Provenance is written once

**Provenance is written once and never edited.** An incident filed from a patrol
observation stays linked to that observation forever
(`Incident::recordProvenance()` refuses a second call). The hand-off is a UUID, a
label and a URL rather than a foreign key, because the patrols module is a
separate bundle and a host may install either without the other — see
[the report flow](screens.md).

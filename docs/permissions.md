# Permissions

## Contents

- [The four concerns this module declares](#the-four-concerns-this-module-declares)
- [The pairs, site by site](#the-pairs-site-by-site)
- [Why the case file and the money are their own concerns](#why-the-case-file-and-the-money-are-their-own-concerns)
- [An aggregate is withheld with the fact it aggregates](#an-aggregate-is-withheld-with-the-fact-it-aggregates)
- [Why filing and managing are split](#why-filing-and-managing-are-split)
- [Doors](#doors)
- [Powers: somebody else's incident](#powers-somebody-elses-incident)
- [The authority table](#the-authority-table)

## The four concerns this module declares

Declared, never granted. The module contributes rows to the organization's
grants matrix through `Uhifadhi\Contracts\Access\ConcernSourceInterface` (service
`incident.access.concerns`, tagged `uhifadhi.access.concerns` by hand), and they
vanish with the module on uninstall. Installing it hands nobody a new power.

Every one of them names this module, which is how the third question a check
asks — *does the person serve a department that runs incidents* — is
answerable about them at all. A person serves the one department they belong
to and any they support; somebody with no department reaches no incident at
all, whatever their position holds. All four offer **organization** or **area** and
not department: an incident happens on ground.

| Concern | Verbs | What it is about |
|---|---|---|
| `incidents` | read · record · manage · export | The register, filing a report, moving a case through verification, response and closure, and taking the month away as a file. |
| `incident-vocabulary` | read · configure | How an area is set up to file: its kinds and sub-categories, the words its questions offer, and what it counts money in. |
| `case-files` **(sensitive)** | read · manage · delete | The narrative as it was taken, the suspects, claimants, informants and witnesses named on the record, and the evidence attached to it. |
| `case-money` **(sensitive)** | read · manage | The fine or the claim, what was assessed and approved, what has been paid, and waiving the rest with a reason. |

There is deliberately **no `own` scope anywhere**. It would mean "the incidents
I filed", and this module's charter forbids anything that lets one reader see a
subset of a register another reader sees in full.

## The pairs, site by site

| Pair | Where |
|---|---|
| `incidents.read` | the dashboard, the register, a case file, the kinds overview, every widget-library route |
| `incidents.record` | `incident_new`, `incident_create` |
| `incidents.manage` | `incident_transition` — and the transition token, which is not minted for somebody the endpoint would refuse |
| `incidents.export` | `incident_export` |
| `incident-vocabulary.read` | the kinds editor and the lists editor, as screens |
| `incident-vocabulary.configure` | every write on either editor, and the Settings section's one POST |
| `case-files.read` | the Evidence, Involved parties and Narrative cards, the parties block's answers, the evidence and money lines of the timeline, and the bytes of a stored file on the Files hub |
| `case-files.manage` | attaching a file to a case |
| `case-files.delete` | taking one back off |
| `case-money.read` | the Money card, the money figures in the record's subline, the money block's answer, money events on the timeline, and every AGGREGATE of them (below) |
| `case-money.manage` | `incident_money`, `incident_money_waive` |

**There is no `incidents.delete`.** A verb is declared where something
enforces it, and nothing here destroys an incident: a case is resolved and
filed, and a correction is a new event rather than an erasure. A declared power
nothing enforces is a box an administrator can tick that changes nothing. It
arrives with the first thing that deletes a record.

Every per-area route passes its area — `#[IsGranted('incidents.read', subject:
'area')]` — because a gate asked without the ground is a gate any placement
reaching any area at all walks through. The module's own
`tests/Unit/Access/AccessConformanceTest` (the core's
`AccessConformanceTestCase`) fails the build on one that forgot.

## Why the case file and the money are their own concerns

So that a fact can be withheld **without withholding the page it sits on**.

An incident record opens on `incidents.read`. Its case-file cards and its money
card are drawn only where their own concern is held, and what is withheld is
withheld everywhere on that page — the cards, the subline above them, the
block answers below them and the events on the timeline that would repeat them.
A reader without `case-money.read` opens the same case file everybody else
opens and there is no money on it: not greyed, not a placeholder, absent.

The two facts are separate because organizations treat them separately. An
informant named on a poaching case is endangered by the *reading*, not by the
filing; money attracts a different kind of interest again, and is routinely
held by the few who settle it while the case stays readable by everybody
working it.

## An aggregate is withheld with the fact it aggregates

A register-wide total is the cases' figures added up, so a figure drawn for
somebody the cases are withheld from hands back exactly what the case files
hold back. Every one of these asks the same door the record asks:

| Surface | Asks | What a refused reader gets |
|---|---|---|
| dashboard · `Fines & compensation` board | `case-money.read` | the card, saying withheld |
| dashboard · the KPI strip's `Money outstanding` | `case-money.read` | the slot, an em dash and the word (the strip stays four to a row) |
| dashboard · the register's `money` column | `case-money.read` | the table, one column short |
| dashboard · `Latest evidence` | `case-files.read` | the card, saying withheld |
| area overview · `Money outstanding` | `case-money.read` | the cell, saying withheld |
| a department's KPI strip · `Fines assessed`, `Compensation approved` | `case-money.read` | the three non-money plates, and no money plate at all |
| performance topic · the `Compensation claims` column | `case-money.read` | a matrix without that column |

**Withheld is not a nought, and not a dash.** A nought is a measurement and a
dash means "we could not measure"; this figure was measured and is not this
reader's. Where a cell can say so it says "withheld"; where the platform's own
value object cannot — a `MatrixCell` offers a value, a dash and "not this
department's to answer" and nothing else — the column is dropped instead of
borrowing a word that would print a different sentence from the true one.

**AN ORGANIZATION-WIDE FIGURE NEEDS EVERY AREA.** A reading across areas is the
areas' readings added up, so one area the reader is refused would be smuggled
into the total. A scope naming no area asks about all of them and one refusal
withholds the figure. `Uhifadhi\Incident\Access\IncidentDoors` is where that
rule lives, and it is also where a contributed cell asks its door at all: the
area overview, the organization dashboard, a department's KPI strip and a
performance topic are rendered by somebody else from a context this module
hands over, so the question is answered before the handover rather than in the
markup. It fails closed three ways — no door, no token, no answer.

**The organization dashboard's own cell publishes neither.** `Latest
incidents` is references, areas, kinds, filing times and term chips; there is
nothing on it to withhold, and it is drawn whole for everybody who reaches `/`.

## Why filing and managing are split

The split is the design's own economics — *a report is cheap and a verification
is expensive*. The design's IN·R1 card says filing should need no permission of
its own, so a deployment that agrees grants `incidents.record` to everyone who
can reach the module; it exists because a POST that creates a record must be
guarded by something an organization can see and assign.

## Doors

Every link, button and section this module draws that leads somewhere a
permission guards goes through `door('<concern>.<verb>', area)` — never
`is_granted`. That is what lets a test walk them all and hold them against the
routes, and the conformance refuses a template that asks the checker directly.

Two of them ask a second question first. The filing control and the widget
library also depend on whether the writing screens **exist** in this
installation at all (they need SecurityBundle), which is the `recordScreens`
flag — a fact about the installation, not about the viewer.

## Powers: somebody else's incident

An incident is filed by one person and worked by others, so four acts here
change somebody else's record. Each is declared through
`Uhifadhi\Contracts\Access\PowerSourceInterface` (`Uhifadhi\Incident\Access\IncidentPowers`,
service `incident.access.powers`, tagged `uhifadhi.access.powers` by hand), in the
group *Somebody else's records*, and Team › Permissions asks each of them as
every kind of person, about a colleague in their area and about somebody
beyond it:

| Power | Asks |
|---|---|
| verify, respond to, resolve or close somebody else's incident | `incidents.manage` |
| record or waive the money on somebody else's incident | `case-money.manage` |
| attach evidence to somebody else's incident | `case-files.manage` |
| take evidence off somebody else's incident | `case-files.delete` |

The question is asked of the area the person's latest filed incident lies in,
which is the subject the routes ask it of. Somebody who has filed nothing yet
is asked about where their next case would be filed: the one area they are
placed at, or — placed across several, across the organization or nowhere —
the whole organization.

Deleting an incident is not among them: the delete page is the core's, and it
answers to a Super Admin alone.

## The authority table

`tests/Functional/AuthorityTableTest.php` calls every route of this module as
every kind of person the core's `Uhifadhi\Test\AuthorityTableTestCase` knows,
in a kernel shaped like an installation and answered by the core's own voters,
and holds the outcomes against `tests/Functional/authority-table.md`. A change
in who may open what fails the build until the table is recorded again and its
diff is read:

```console
UHIFADHI_RECORD_AUTHORITY_TABLE=1 vendor/bin/phpunit tests/Functional/AuthorityTableTest.php
```

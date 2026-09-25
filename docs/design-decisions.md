# Design decisions

The deliberate modelling choices this module has made, why each was made, and
the trigger that reopens it — so that a later reader can tell a decision from
an accident, and nobody "fixes" one without knowing what it was for.

## Contents

- [The flat answer bag is dropped, not kept nullable](#the-flat-answer-bag-is-dropped-not-kept-nullable)
- [A word's questions are its blocks', and the field list is dropped](#a-words-questions-are-its-blocks-and-the-field-list-is-dropped)
- [Every chart is the atlas's, and a share is a stacked band](#every-chart-is-the-atlass-and-a-share-is-a-stacked-band)
- [The calendar is fed from the window's own rows, not from a feed of its own](#the-calendar-is-fed-from-the-windows-own-rows-not-from-a-feed-of-its-own)
- [A calendar mark cannot wear its kind's hue](#a-calendar-mark-cannot-wear-its-kinds-hue)

## The flat answer bag is dropped, not kept nullable

**Decision.** `incident.details` is removed outright by
`Version20260924000000`, together with the property that mapped it and its two
accessors.

**Why.** An incident keeps its answers per behaviour block, in the shape the
block asks in — `incident.block_answers`, added by `Version20260912103000`,
which moved every answer with a block to go to into it. The flat bag held one
name per answer and could never hold "twelve snares, two carcasses, one
bicycle" as the one record it is. Keeping the column nullable would leave a
second, staler answer reachable while pretending it had gone.

**Why in 0.4.1 and not earlier.** The version that stopped writing it named
it as what a later release drops, so an installation could read what a record
used to say and roll the code back in between. 0.4.1 is that release.

**Its own version.** `incident_taxonomy_subcategory.field_set` was named beside
`details` in the same deferral and is dropped by a version of its own — see the
next decision — so that a rollback of one is a rollback of one thing.

**Reopens when** a sub-category needs an answer that belongs to no block. That
is a new column with a new meaning, not this one coming back.

## A word's questions are its blocks', and the field list is dropped

**Decision.** `incident_taxonomy_subcategory.field_set` is removed outright by
`Version20260925000000`, on the 0.4 line after 0.4.1, together with the
property that mapped it and its two accessors.

**Why.** A sub-category asks exactly the questions of the behaviour blocks it
switches on (`incident_taxonomy_subcategory.blocks`). A typed list of field
names beside the blocks would let a word ask anything, and the kinds editor
offers no way to write one (`TaxonomyAdminServiceTest` asserts it). Nothing has
written the column since `Version20260912103000`, and nothing reads it.

**Reopens when** an area needs a question no block asks. That is a new block,
not a free-text field list coming back.

## Every chart is the atlas's, and a share is a stacked band

**Decision.** The five readings this dashboard used to draw as hand-rolled
`<svg class="ch">` — the trend line, the severity bars, the category share, the
status funnel and the zone bars — are stated as `AtlasChart`s
(`Uhifadhi\Incident\Model\IncidentCharts`) and drawn by `atlas_chart()`. The
macro file that held the arithmetic is deleted, not deprecated: nothing outside
this module ever imported it.

**Why.** Ruled: the atlas is the component library for every module visual, and
a module feeds data rather than drawing. Two modules that each own their line's
tension, grid and colours produce two products.

**The one thing that changed shape: the donut.** `ChartKind` has four shapes
and `pie` is deliberately not one of them — "a decision about presentation that
no module gets to make". So the month's mix is a **stacked band over one
period**: parts of a whole, one band per kind, each band wearing its kind's own
category, with the legend the component draws. The reading is the same and the
picture is not a ring.

**What went with it.** The vertical donut legend printing each kind's count and
percentage. The component's legend names the bands; a count per band is not
something `atlas_chart()` states today.

**Reopens when** the atlas grows a way to print a figure against a legend row,
or a share shape of its own. Either is a change in the atlas, and the module
picks it up by stating the same series.

## The calendar is fed from the window's own rows, not from a feed of its own

**Decision.** `IncidentCalendar::forWindow()` turns the incidents the dashboard
already loaded into a `CalendarMonth`, hung on `IncidentDashboard`. This module
implements **no** `Uhifadhi\Contracts\Atlas\CalendarFeedInterface`.

**Why.** One filter drives the map, the register and every chart on this
surface, and it has to drive the month too: a calendar that ran its own query
could show a day the register beside it does not. A feed answers
`month(YearMonth, ?string $scope)` on its own terms, which is exactly the second
answer this dashboard exists to avoid.

**The cost, stated.** The month cannot be stepped from inside the card. The
stepper's arrows are drawn and not offered, and the month is changed on the
shared filter row — which is what the design (IN·20) draws.

**Reopens when** a surface outside this dashboard wants an incidents month — a
handset, an org-wide calendar, another module's page. That surface has no
dashboard to read, so it needs the feed, and the feed is then implemented
alongside this rather than instead of it.

## A calendar mark cannot wear its kind's hue

**Decision.** Every mark on the incidents calendar wears `PillHue::Subject`.
The kind is in the mark's hover text instead.

**Why.** The design paints each mark with its kind's hue, the same position the
map pin and the register chip read. `Uhifadhi\Contracts\Atlas\PillHue`
publishes five **roles** — subject, good, attention, problem, quiet — and no
category, so a kind cannot be stated through it. Reaching a colour by calling
poaching `Good` would be this module lying about what a role means, and a hue
this module picked is a hue that is wrong in two of the three palettes.

**This is a gap in the contract, not a choice.** It is raised there rather than
worked around here.

**Reopens when** `CalendarPill` can carry a category the way `ChartSeries`
carries `cat`. The module then states the kind's position and the marks match
the pins again with no further change here.

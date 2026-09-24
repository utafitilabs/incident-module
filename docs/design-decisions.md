# Design decisions

The deliberate modelling choices this module has made, why each was made, and
the trigger that reopens it — so that a later reader can tell a decision from
an accident, and nobody "fixes" one without knowing what it was for.

## Contents

- [The flat answer bag is dropped, not kept nullable](#the-flat-answer-bag-is-dropped-not-kept-nullable)

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

**Why now and not earlier.** The version that stopped writing it said in as
many words that the release after it drops it, and kept it for one release so
an installation could read what a record used to say and roll the code back.
This is that release.

**What is deliberately still standing.**
`incident_taxonomy_subcategory.field_set` was named beside `details` in the
same deferral and is **not** dropped here. It is a decision of its own and
rides its own version, so that a rollback of one is a rollback of one thing.
Until it ships, `doctrine:migrations:diff` proposes dropping it — that proposal
is the deferral working, not drift.

**Reopens when** a sub-category needs an answer that belongs to no block. That
is a new column with a new meaning, not this one coming back.

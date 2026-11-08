import { Controller } from '@hotwired/stimulus';

/*
 * THE REPORT FLOW — one page, and the little it needs a round trip to avoid.
 *
 * The flow lives on ONE ROUTE, in ONE CONTAINER (the full page, ruled direction
 * A). There is no drawer to slide, dismiss or guard any more — a page cannot be
 * thrown away by a click beside it, so this controller no longer opens, closes or
 * animates anything. What it adds is only what the server cannot do without a
 * round trip: swapping the sub-category's own field set, and the gate.
 *
 * STEP 2 IS THE CATEGORY'S OWN, AND ITS QUESTIONS COME FROM ITS BLOCKS. Choosing a
 * kind of incident swaps in that word's own folds — one per behaviour block it
 * switched on — and the money fold is ABSENT, not disabled, for a sub-category
 * that carries no money. That is the design's contract and the template enforces
 * it by rendering no money fold there at all.
 *
 * AND THE RAIL BESIDE THE FORM IS MARKED FROM THE GATE'S OWN LIST. The checklist
 * names every answer the filing still owes; it is handed the very list the footer
 * was just given, so the rail and the File control can never disagree about the
 * same form. Choosing a word swaps the rail's card with step 2's field set, for the
 * same reason both exist: a form showing one sub-category's questions beside a card
 * describing another's would be two answers to one question.
 *
 * WHAT ELSE IT DOES IS WHAT A LIST NEEDS: a fold opens and shuts, and a repeating
 * block grows and loses rows. Neither reaches the server — a fold remembers a
 * person's reading habit, not a fact about the incident.
 *
 * AND IT ASKS THE AREA WHERE THE POINT LIES whenever the point changes, so the
 * banner over the Where row is there before anything is filed. The answer
 * arrives drawn by the same partials the server renders the form with — this
 * controller writes no word of it — and the server judges the point again when
 * the report is posted.
 *
 * THE WHOLE FLOW WORKS WITHOUT THIS CONTROLLER: the category is a radio in the
 * noscript block, one row of every repeating block is in the markup, the File
 * control ships disabled and the form posts normally to the same endpoint, which
 * refuses the same omissions.
 */
export default class extends Controller {
    static targets = [
        'category', 'fieldset', 'gate', 'hint', 'file', 'headline', 'rows',
        // The rail beside the form: one card per sub-category, plus the card that
        // stands in while nothing is chosen.
        'asks', 'asksEmpty', 'progress',
        // Where the point lies against the area: the slot over the Where row,
        // the note on it, and the point a refusing area still owes.
        'placement', 'where', 'refusedPoint',
    ];

    static values = { placementUrl: String };

    /** How long the typing settles before the area is asked about the point. */
    static SETTLE_MS = 350;

    /** The attribute a rail row carries the gate's own missing-answer label on. */
    static NEED = 'data-incident-report-need';

    connect() {
        this.asked = 0;
        this.gate();
        // A note copied in from an observation is routinely two lines long, so
        // the box is the size of its answer from the first paint — not after the
        // filer has scrolled a sentence sideways to read it.
        if (this.hasHeadlineTarget) {
            this.fit(this.headlineTarget);
        }
    }

    disconnect() {
        clearTimeout(this.settling);
    }

    /** THE POINT MOVED: ask the area once the typing settles. */
    place() {
        clearTimeout(this.settling);
        this.settling = setTimeout(() => this.judge(), this.constructor.SETTLE_MS);
    }

    /**
     * WHERE THE POINT LIES, asked of incident_placement. Only the latest
     * question's answer is drawn; a point that cannot be read, or an answer that
     * does not come, empties the slot, because the server judges the filing
     * either way. A moved point drops a choice to keep the last one outside.
     */
    async judge() {
        if (!this.hasPlacementUrlValue || !this.hasPlacementTarget) {
            return;
        }

        const asked = ++this.asked;
        if (!this.hasPosition()) {
            this.showPlacement('', '');
            return;
        }

        const url = new URL(this.placementUrlValue, window.location.href);
        url.searchParams.set('lat', this.answer('[name="lat"]'));
        url.searchParams.set('lng', this.answer('[name="lng"]'));

        let answer = null;
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            answer = response.ok ? await response.json() : null;
        } catch {
            answer = null;
        }

        if (asked === this.asked) {
            this.showPlacement(answer?.banner ?? '', answer?.where ?? '');
        }
    }

    showPlacement(banner, where) {
        this.placementTarget.innerHTML = banner;
        if (this.hasWhereTarget) {
            this.whereTarget.innerHTML = where;
            this.whereTarget.hidden = '' === where.trim();
        }
        this.gate();
    }

    /**
     * WHAT HAPPENED GROWS TO FIT WHAT HAPPENED. The answer is a sentence, and a
     * sentence that scrolls sideways past a cursor cannot be re-read before it is
     * filed. The `rows` attribute is the floor, so the box is never smaller than
     * the markup promised and never larger than its content needs.
     */
    grow(event) {
        this.fit(event.currentTarget);
    }

    fit(field) {
        field.style.height = 'auto';
        field.style.height = `${field.scrollHeight}px`;
    }

    /**
     * Choose a kind of incident, or a sibling sub-category within it: that
     * sub-category's own field set replaces whatever was there.
     *
     * A step-1 card carries its whole kind's slugs, so it stays lit while you move
     * between its siblings in step 2; a step-2 chip carries only its own.
     */
    choose(event) {
        event.preventDefault();
        const slug = event.currentTarget.dataset.subcategory;
        if (!slug) {
            return;
        }

        for (const option of this.categoryTargets) {
            const owns = (option.dataset.subcategories ?? '').split(',');
            option.classList.toggle('on', owns.includes(slug));
        }

        for (const fieldset of this.fieldsetTargets) {
            fieldset.hidden = fieldset.dataset.subcategory !== slug;
            // The hidden sets are DISABLED too, so the posted form carries only
            // the fields the chosen kind of incident actually asks for — and a
            // sub-category that carries no money has no money row in it at all.
            for (const input of fieldset.querySelectorAll('input, textarea, select')) {
                input.disabled = fieldset.hidden;
            }
        }

        // THE RAIL'S CARD SWAPS WITH THE FIELD SET. The card names the word and
        // prices it, so a form showing one sub-category's questions beside a card
        // describing another's would be two answers to one question.
        for (const asks of this.asksTargets) {
            asks.hidden = asks.dataset.incidentAsks !== slug;
        }
        if (this.hasAsksEmptyTarget) {
            this.asksEmptyTarget.hidden = true;
        }

        const chosen = this.element.querySelector('[data-incident-report-subcategory]');
        if (chosen) {
            chosen.value = slug;
        }

        this.gate();
    }

    /**
     * THE GATE. Three answers file an incident and nothing else does, so filing
     * stays shut until a kind is chosen, one line says what happened and the
     * place is marked — and a quiet line names whatever is still missing rather
     * than making anybody press a disabled button to find out.
     *
     * These are exactly the three the SERVER refuses a filing without — the
     * button ships disabled in the markup and only this can open it, so a
     * refusal never arrives as a surprise after the fact. The template renders
     * the same list on first paint, so a filing that arrived with its place
     * already answered is never asked for it again.
     */
    gate() {
        const missing = [];
        if ('' === this.answer('[data-incident-report-subcategory]')) {
            missing.push('choose a category');
        }
        if ('' === this.answer('[name="title"]')) {
            missing.push('describe what happened');
        }
        if (!this.hasPosition()) {
            missing.push('mark where it happened');
        }
        missing.push(...this.blocksMissing());
        // A POINT THE AREA REFUSES stands until the area's answer for a moved
        // point replaces it; the server judges the point again on filing.
        if (this.hasRefusedPointTarget) {
            missing.push(this.refusedPointTarget.dataset.need);
        }

        if (this.hasFileTarget) {
            this.fileTarget.disabled = 0 !== missing.length;
        }
        // The gate REPLACES the reassuring line rather than crowding in beside
        // it: both are the same quiet note in the same place, and only one of
        // them is worth reading at a time.
        if (this.hasGateTarget) {
            this.gateTarget.textContent = missing.join(' · ');
            this.gateTarget.hidden = 0 === missing.length;
        }
        if (this.hasHintTarget) {
            this.hintTarget.hidden = 0 !== missing.length;
        }

        this.markTheRail(missing);
    }

    /**
     * THE RAIL, MARKED FROM THE GATE'S OWN LIST.
     *
     * The checklist beside the form says whether each answer the filing owes has
     * arrived. It must never work that out for itself: a second reading of the same
     * form is a second opinion, and the day the two disagree the rail is telling
     * somebody they may file while the File control refuses to. So this is handed
     * the very list that was just written into the footer, and every row is marked
     * by matching its own `data-incident-report-need` against it.
     *
     * A row that is still owed is NUMBERED in the order it is owed, and one that has
     * arrived wears a tick — the same marks the state rail uses, so "answered" reads
     * identically wherever this module draws it.
     *
     * The rail informs and never gates: nothing here touches the File control.
     */
    markTheRail(missing) {
        const shown = this.asksTargets.find((asks) => !asks.hidden);
        if (!shown) {
            return;
        }

        const rows = [...shown.querySelectorAll('.rr-q')];
        let owed = 0;
        for (const row of rows) {
            const need = row.getAttribute(this.constructor.NEED);
            const done = !missing.includes(need);
            row.classList.toggle('done', done);
            row.classList.toggle('need', !done);

            const mark = row.querySelector(':scope > i');
            if (mark) {
                mark.textContent = done ? '✓' : `${++owed}`;
            }

            // "3 questions · folded" while it waits, "3 questions · answered" once
            // the answer is in — the count is the markup's, the second word is this.
            const note = row.querySelector('.n');
            if (note) {
                const base = note.dataset.incidentReportN ?? '';
                const fold = row.dataset.incidentReportFold ?? 'open';
                note.textContent = `${base} · ${done ? 'answered' : fold}`;
            }
        }

        const total = rows.filter((row) => row.hasAttribute(this.constructor.NEED)).length;
        const still = rows.filter((row) => missing.includes(row.getAttribute(this.constructor.NEED))).length;
        const progress = shown.querySelector('.rr-prog');
        if (!progress || 0 === total) {
            return;
        }

        const done = 0 === still;
        progress.classList.toggle('ok', done);
        const bar = progress.querySelector('.bar');
        if (bar) {
            bar.classList.toggle('ok', done);
            const fill = bar.querySelector('i');
            if (fill) {
                fill.style.width = `${Math.round(((total - still) / total) * 100)}%`;
            }
        }
        const count = progress.querySelector('b');
        if (count) {
            count.textContent = `${still} of ${total} still needed`;
        }
    }

    /**
     * EVERY SWITCHED-ON BLOCK'S OWN FIRST ANSWER, named the way it is missed —
     * and named whether or not the fold holding it is open, because a fold is an
     * invitation to skip and a hidden question may not hide a held-up filing.
     *
     * A fold says how it is missed on `data-need`. Inside it, a question asked
     * once carries `data-need-answer`; a block that is a row the filer adds to
     * carries `data-need-row` on the cells a row cannot be without, and ONE WHOLE
     * row satisfies it — half a row is no row. The sub-categories that were not
     * chosen are disabled, so their folds are not asked.
     */
    blocksMissing() {
        const missing = [];

        for (const fold of this.element.querySelectorAll('.fold[data-need]')) {
            // A sub-category that was not chosen is hidden, and its questions are
            // not asked — the same rule the posted form obeys.
            if (fold.closest('[hidden]')) {
                continue;
            }

            const answers = [...fold.querySelectorAll('[data-need-answer]')];
            if (answers.some((field) => '' === field.value.trim())) {
                missing.push(fold.dataset.need);
                continue;
            }

            const rows = [...fold.querySelectorAll('.rep')];
            if (0 === rows.length) {
                continue;
            }
            const whole = rows.some((row) => {
                const cells = [...row.querySelectorAll('[data-need-row]')];
                return 0 !== cells.length && !cells.some((cell) => '' === cell.value.trim());
            });
            if (!whole) {
                missing.push(fold.dataset.need);
            }
        }

        return missing;
    }

    /**
     * A BLOCK FOLDS. Nothing here reaches the server and no fold state is ever
     * filed: what a fold remembers is how somebody reads, not what happened.
     */
    fold(event) {
        event.currentTarget.parentNode.classList.toggle('shut');
    }

    /**
     * A ROW THAT REPEATS GROWS. The new row is the last one, emptied — so it
     * carries whatever the markup said a row is, and this controller never has an
     * opinion about which questions a block asks.
     */
    addRow(event) {
        event.preventDefault();
        const rows = this.rowsFor(event.currentTarget);
        const last = rows?.querySelector('.rep:last-of-type');
        if (!last) {
            return;
        }

        const row = last.cloneNode(true);
        const index = rows.querySelectorAll('.rep').length;

        for (const field of row.querySelectorAll('input, select')) {
            // The name says which row it is, and the new one is its own row or
            // the two would post as one.
            field.name = field.name.replace(/\[rows]\[\d+]/, `[rows][${index}]`);
            field.removeAttribute('id');
            if ('SELECT' === field.tagName) {
                field.selectedIndex = 0;
            } else {
                field.value = '';
            }
        }
        for (const pressed of row.querySelectorAll('.fyn > button.on')) {
            pressed.classList.remove('on');
        }

        const counter = row.querySelector('.ix');
        if (counter) {
            counter.textContent = `${index + 1}`;
        }

        rows.append(row);
        this.gate();
    }

    /**
     * AND LOSES ONE — except the last, because a block that repeats still asks its
     * questions. Emptying the last row is how somebody says they have nothing to
     * put in it.
     */
    removeRow(event) {
        event.preventDefault();
        const row = event.currentTarget.closest('.rep');
        const rows = row?.parentElement;
        if (!row || !rows) {
            return;
        }

        if (1 === rows.querySelectorAll('.rep').length) {
            for (const field of row.querySelectorAll('input, select')) {
                if ('SELECT' === field.tagName) {
                    field.selectedIndex = 0;
                } else {
                    field.value = '';
                }
            }
        } else {
            row.remove();
            this.renumber(rows);
        }

        this.gate();
    }

    /** The row set a control inside a block belongs to. */
    rowsFor(control) {
        const fold = control.closest('.fold');

        return fold ? fold.querySelector('.reps') : null;
    }

    /** One, two, three — and the names follow the numbers. */
    renumber(rows) {
        let index = 0;
        for (const row of rows.querySelectorAll('.rep')) {
            for (const field of row.querySelectorAll('input, select')) {
                field.name = field.name.replace(/\[rows]\[\d+]/, `[rows][${index}]`);
            }
            const counter = row.querySelector('.ix');
            if (counter) {
                counter.textContent = `${index + 1}`;
            }
            ++index;
        }
    }

    /**
     * YES OR NO, as a pair of halves and one posted value. A button posts nothing,
     * so the answer lives in the field behind them and pressing a half is what
     * writes it.
     */
    pick(event) {
        event.preventDefault();
        const pressed = event.currentTarget;
        const pair = pressed.parentElement;
        if (!pair) {
            return;
        }

        for (const half of pair.querySelectorAll('button')) {
            half.classList.toggle('on', half === pressed);
        }
        const field = pair.querySelector('input[type="hidden"]');
        if (field) {
            field.value = pressed.dataset.answer ?? '';
        }

        this.gate();
    }

    /**
     * What is currently ANSWERED for a field. The field sets that are not the
     * chosen kind's are disabled, so only the live one is asked — the same rule
     * the posted form obeys.
     */
    answer(selector) {
        for (const field of this.element.querySelectorAll(selector)) {
            if (!field.disabled) {
                return field.value.trim();
            }
        }

        return '';
    }

    /** A place is two readable numbers, which is what the server stores as a point. */
    hasPosition() {
        const lat = this.answer('[name="lat"]');
        const lng = this.answer('[name="lng"]');

        return '' !== lat && '' !== lng && !Number.isNaN(Number(lat)) && !Number.isNaN(Number(lng));
    }
}

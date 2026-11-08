<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Incidents Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Incident\Model;

use Symfony\Component\HttpFoundation\Request;
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;

/**
 * WHAT THE REPORT FORM HOLDS: the hand-off's guesses on a fresh form, and on a
 * form that comes back refused, exactly what was posted. A refused report is
 * drawn from this so nobody writes a report twice.
 *
 * The block answers are kept as they were typed, keyed block → question (and
 * block → rows → n → question for a block that repeats); only text survives.
 */
final readonly class ReportAnswers
{
    /**
     * @param array<string, array<mixed, mixed>> $blocks
     */
    public function __construct(
        public string $title = '',
        public string $latitude = '',
        public string $longitude = '',
        public ?\DateTimeImmutable $occurredAt = null,
        public IncidentSeverityEnum $severity = IncidentSeverityEnum::Moderate,
        public string $narrative = '',
        private array $blocks = [],
    ) {
    }

    public static function fromPrefill(IncidentPrefill $prefill): self
    {
        return new self(
            title: (string) $prefill->note,
            latitude: null === $prefill->latitude ? '' : (string) $prefill->latitude,
            longitude: null === $prefill->longitude ? '' : (string) $prefill->longitude,
            occurredAt: $prefill->occurredAt,
            narrative: (string) $prefill->note,
        );
    }

    /**
     * @param \DateTimeImmutable|null $occurredAt the posted clock, already read in the filer's zone
     */
    public static function fromPost(Request $request, ?\DateTimeImmutable $occurredAt): self
    {
        $blocks = [];
        foreach ($request->request->all('blocks') as $block => $answers) {
            if (\is_string($block) && \is_array($answers)) {
                $blocks[$block] = $answers;
            }
        }

        return new self(
            title: $request->request->getString('title'),
            latitude: trim($request->request->getString('lat')),
            longitude: trim($request->request->getString('lng')),
            occurredAt: $occurredAt,
            severity: IncidentSeverityEnum::tryFrom($request->request->getString('severity')) ?? IncidentSeverityEnum::Moderate,
            narrative: $request->request->getString('narrative'),
            blocks: $blocks,
        );
    }

    /** Whether a point was given at all, readable or not. */
    public function hasPoint(): bool
    {
        return '' !== $this->latitude && '' !== $this->longitude;
    }

    /** A question asked once, as it was answered; empty where it was not. */
    public function block(string $block, string $key): string
    {
        $answer = $this->blocks[$block][$key] ?? null;

        return \is_scalar($answer) ? (string) $answer : '';
    }

    /**
     * The rows of a block that repeats, as they were answered — and one empty
     * row where none was, because a block that repeats still asks its questions.
     *
     * @return non-empty-list<array<string, string>>
     */
    public function rows(string $block): array
    {
        $posted = $this->blocks[$block]['rows'] ?? null;
        $rows = [];
        foreach (\is_array($posted) ? $posted : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $cells = [];
            foreach ($row as $key => $cell) {
                if (\is_string($key) && \is_scalar($cell)) {
                    $cells[$key] = (string) $cell;
                }
            }
            $rows[] = $cells;
        }

        return [] === $rows ? [[]] : $rows;
    }
}

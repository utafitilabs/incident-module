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
use Uhifadhi\Incident\Enum\IncidentStatusEnum;

/**
 * THE ORGANIZATION REGISTER'S QUESTION, read off one GET.
 *
 * THE AREA IS THE SHELL'S PARAMETER. `?area=<uuid>` is the scope, chosen by the
 * shell's control or by the bar's area chip, and this object only carries it
 * along so a link never drops it. Every other axis is this module's own, with
 * the same names the area register reads.
 *
 * UNTRUSTED, so an unreadable value is no narrowing rather than an error.
 */
final readonly class IncidentOrgQuery
{
    public function __construct(
        public ?string $area,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public ?string $category = null,
        public ?IncidentStatusEnum $status = null,
        public ?string $department = null,
        public ?string $search = null,
    ) {
    }

    /**
     * @param string|null $area the scope's area, or null for the whole organization
     */
    public static function fromRequest(Request $request, ?string $area, \DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        return new self(
            $area,
            $from,
            $to,
            self::text($request, 'category'),
            IncidentStatusEnum::tryFrom($request->query->getString('status')),
            self::text($request, 'department'),
            self::text($request, 'q'),
        );
    }

    public function isNarrowed(): bool
    {
        return null !== $this->category || null !== $this->status || null !== $this->department || null !== $this->search;
    }

    /**
     * The query parameters that reproduce this question — what every option and
     * the pager link to.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'area' => $this->area,
            'category' => $this->category,
            'status' => $this->status?->value,
            'month' => $this->from->format('Y-m'),
            'department' => $this->department,
            'q' => $this->search,
        ], static fn (?string $value): bool => null !== $value);
    }

    /**
     * The same question with one axis changed, or cleared where the value is
     * null — what one dropdown option links to.
     *
     * @return array<string, string>
     */
    public function with(string $key, ?string $value): array
    {
        $query = $this->toQuery();
        unset($query[$key]);
        if (null !== $value && '' !== $value) {
            $query[$key] = $value;
        }

        return $query;
    }

    private static function text(Request $request, string $key): ?string
    {
        $value = trim($request->query->getString($key));

        return '' === $value ? null : $value;
    }
}

<?php

namespace DoctolibChecker\Dto;

use DateTimeImmutable;

final class Availabilities
{
    public function __construct(
        /** @var Availability[] */
        public readonly array $availabilities = [],
        public readonly int $total = 0,
        public readonly ?string $reason = null,
        public readonly ?string $message = null,
    ) {
    }

    /**
     * @return DateTimeImmutable[]
     */
    public function getSlots(?int $limit = null): array
    {
        $slots = array_reduce(
            $this->availabilities,
            fn (array $slots, Availability $availability) => [...$slots, ...$availability->slots],
            [],
        );

        if ($limit > 0) {
            $slots = array_slice($slots, 0, $limit);
        }

        return $slots;
    }
}

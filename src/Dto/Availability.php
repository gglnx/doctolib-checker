<?php

namespace DoctolibChecker\Dto;

use DateTime;
use DateTimeImmutable;

final class Availability
{
    public readonly DateTimeImmutable $date;

    public function __construct(
        DateTime $date,
        /** @var DateTimeImmutable[] */
        public readonly array $slots,
    ) {
        $this->date = DateTimeImmutable::createFromMutable($date->setTime(0, 0, 0));
    }
}

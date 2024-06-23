<?php

namespace DoctolibChecker\Dto;

final class Doctor
{
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly int $visitMotiveId,
        public readonly int $agendaId,
        public readonly int $practiceId,
        public readonly InsuranceSector $insuranceSector = InsuranceSector::Public,
    ) {
    }
}

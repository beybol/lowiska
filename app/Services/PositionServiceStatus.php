<?php

namespace App\Services;

use App\Models\AdditionalService;

/**
 * Usługa na stanowisku wraz z dostępnością w zadanej dobie albo zakresie dób (zadanie 020).
 */
final readonly class PositionServiceStatus
{
    /**
     * @param  array<int, PositionServiceProblem>  $problems  puste = usługa dostępna
     */
    public function __construct(
        public AdditionalService $service,
        public bool $isRequired,
        public array $problems,
    ) {}

    public function isAvailable(): bool
    {
        return $this->problems === [];
    }
}

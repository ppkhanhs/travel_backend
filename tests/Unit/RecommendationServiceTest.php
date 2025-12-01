<?php

namespace Tests\Unit;

use App\Services\RecommendationService;
use PHPUnit\Framework\TestCase;

class RecommendationServiceTest extends TestCase
{
    /**
     * @dataProvider decayProvider
     */
    public function testDecayFactor(int $days, float $expected, float $delta): void
    {
        $service = new RecommendationService();
        $result = $service->decayFactor($days);

        $this->assertEqualsWithDelta($expected, $result, $delta);
    }

    public function decayProvider(): array
    {
        return [
            'now (0 day)' => [0, 1.0, 0.0001],
            'half-life 14 days' => [14, 0.5, 0.0001],
            'double half-life 28 days' => [28, 0.25, 0.0001],
            'negative days treated as immediate' => [-3, 1.0, 0.0001],
        ];
    }
}

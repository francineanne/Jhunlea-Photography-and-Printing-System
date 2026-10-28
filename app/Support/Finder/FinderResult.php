<?php

namespace App\Support\Finder;

use App\Models\PhotographyPackage;

/** One ranked package: its score out of the most it could earn for the questions answered, and why. */
class FinderResult
{
    /**
     * @param  array<int, string>  $reasons  what matched
     * @param  array<int, string>  $misses  what did not
     */
    public function __construct(
        public readonly PhotographyPackage $package,
        public readonly int $score,
        public readonly int $maxScore,
        public readonly array $reasons,
        public readonly array $misses,
    ) {}

    /** The score as a percentage of the most possible. Null when nothing was asked. */
    public function percent(): ?int
    {
        return $this->maxScore > 0 ? (int) round($this->score / $this->maxScore * 100) : null;
    }

    public function isFullMatch(): bool
    {
        return $this->maxScore > 0 && $this->score === $this->maxScore;
    }
}

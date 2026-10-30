<?php

namespace Database\Factories;

use App\Models\Faq;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Faq> */
class FaqFactory extends Factory
{
    public function definition(): array
    {
        return [
            'question' => 'Sample question '.fake()->unique()->numberBetween(1, 999999).'?',
            'answer' => 'Sample answer.',
            'is_published' => true,
        ];
    }

    private static int $base = -1;

    private static int $offset = 0;

    /** Each new FAQ goes to the end, as FaqService would put it (count(3) makes all three before saving any). */
    public function configure(): static
    {
        return $this->afterMaking(function (Faq $faq) {
            if ($faq->position !== null) {
                return;
            }

            $max = (int) Faq::query()->max('position');
            [self::$base, self::$offset] = $max === self::$base ? [self::$base, self::$offset + 1] : [$max, 1];
            $faq->position = $max + self::$offset;
        });
    }

    public function hidden(): static
    {
        return $this->state(['is_published' => false]);
    }
}

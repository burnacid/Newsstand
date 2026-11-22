<?php

namespace Database\Factories;

use App\Models\Feed;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feed>
 */
class FeedFactory extends Factory
{
    public function definition(): array
    {
        return [
            'url' => 'https://'.fake()->unique()->domainName().'/feed.xml',
            'title' => fake()->company(),
        ];
    }
}

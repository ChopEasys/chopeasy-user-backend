<?php

namespace Database\Seeders;

use App\Models\Slide;
use Illuminate\Database\Seeder;

class TextOnlyHeroSlideSeeder extends Seeder
{
    public function run(): void
    {
        $slide = Slide::updateOrCreate(
            ['title' => 'Save Smart, Eat Well.'],
            [
                'description' => 'ChopEasy makes food shopping simpler. Explore fresh groceries and everyday essentials from trusted partner stores, choose flexible ways to pay, and have your order delivered safely to your door. Shop with confidence, stay in control of your food budget, and spend more time enjoying good food with the people who matter.',
                'button_text' => 'Shop Now',
                'layout' => 'text_only',
                'background_color' => '#FFC107',
                'image_path' => null,
                'type' => 'customer',
                'order' => 0,
                'is_active' => true,
                'url' => null,
            ]
        );

        Slide::where('id', '!=', $slide->id)->update(['is_active' => false]);
    }
}

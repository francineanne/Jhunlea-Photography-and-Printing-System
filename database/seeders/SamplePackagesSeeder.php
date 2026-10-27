<?php

namespace Database\Seeders;

use App\Models\PhotographyPackage;
use App\Models\Theme;
use Illuminate\Database\Seeder;

/**
 * SAMPLE DATA — placeholder content to be replaced with the shop's real catalog.
 *
 * Names, prices, inclusions and colors below are invented for development and demos. Safe to run more than once:
 * packages are matched by slug and themes by name, and are left alone when they already exist (edits and deleted
 * rows are kept), so nothing is duplicated or overwritten. A package's theme links are made only when the package is
 * created. Creates no users and no photos.
 */
class SamplePackagesSeeder extends Seeder
{
    public function run(): void
    {
        $themes = [];

        foreach ($this->themes() as $data) {
            // withTrashed: a sample theme the owner deleted stays deleted
            $themes[$data['name']] = Theme::withTrashed()->firstOrCreate(['name' => $data['name']], $data);
        }

        foreach ($this->packages() as $order => $data) {
            $themeNames = $data['themes'];
            unset($data['themes']);

            $package = PhotographyPackage::withTrashed()->firstOrCreate(
                ['slug' => $data['slug']],
                $data + ['sort_order' => ($order + 1) * 10],
            );

            if (! $package->wasRecentlyCreated) {
                continue;
            }

            $package->themes()->sync(collect($themeNames)->map(fn ($name) => $themes[$name]->id)->all());
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function themes(): array
    {
        return [
            ['name' => 'Classic Crimson', 'description' => 'Sample: a deep red backdrop for bold portraits.', 'color_swatches' => ['#C62828', '#8E0000', '#171717'], 'is_published' => true],
            ['name' => 'Warm Cream', 'description' => 'Sample: soft earth tones for family and milestone sessions.', 'color_swatches' => ['#F5EBE0', '#E3D5CA', '#D5BDAF'], 'is_published' => true],
            ['name' => 'Dark Charcoal', 'description' => 'Sample: a moody neutral backdrop for headshots and portraits.', 'color_swatches' => ['#262626', '#404040', '#A3A3A3'], 'is_published' => true],
            ['name' => 'Library Wood', 'description' => 'Sample: warm wood tones for graduation and formal portraits.', 'color_swatches' => ['#5C4033', '#8B6B4A', '#D9C5A0', '#2B1D14'], 'is_published' => true],
            ['name' => 'Soft White', 'description' => 'Sample: a clean, bright backdrop for any session.', 'color_swatches' => ['#FFFFFF', '#F5F5F5', '#E5E5E5'], 'is_published' => true],
            ['name' => 'Sage Garden', 'description' => 'Sample: a seasonal green set. Kept unpublished while it is being prepared.', 'color_swatches' => ['#8A9A86', '#B5C4B1', '#E8EFE3', '#4F5D4B', '#F5F5F5'], 'is_published' => false],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function packages(): array
    {
        return [
            [
                'name' => 'Solo Portrait Session',
                'slug' => 'solo-portrait-session',
                'category' => 'Portrait',
                'short_description' => 'Sample: a short studio session for one person.',
                'description' => 'Sample: a relaxed studio portrait session for one person, good for profile photos and personal portraits.',
                'price' => '1200.00',
                'price_requires_confirmation' => false,
                'duration_minutes' => 30,
                'max_people' => 1,
                'digital_outputs' => 5,
                'inclusions' => ['30 minutes in the studio', 'Studio lighting and backdrop', '5 edited digital photos', 'Online gallery for viewing', 'Free choice of one backdrop'],
                'terms' => 'Sample: please arrive 10 minutes before your session.',
                'is_published' => true,
                'is_featured' => false,
                'themes' => ['Soft White', 'Dark Charcoal', 'Warm Cream'],
            ],
            [
                'name' => 'Couple Session',
                'slug' => 'couple-session',
                'category' => 'Couple',
                'short_description' => 'Sample: a studio session for two, with the price confirmed after review.',
                'description' => 'Sample: a studio session for couples, including a few outfit changes and posing guidance.',
                'price' => '2500.00',
                'price_requires_confirmation' => true,
                'duration_minutes' => 60,
                'max_people' => 2,
                'digital_outputs' => 15,
                'inclusions' => ['60 minutes in the studio', 'Posing guidance', 'Up to 2 outfit changes', '15 edited digital photos', 'Choice of two backdrops', 'Online gallery for viewing'],
                'terms' => 'Sample: the final price depends on the outfits and props you bring.',
                'is_published' => true,
                'is_featured' => true,
                'themes' => ['Warm Cream', 'Sage Garden', 'Classic Crimson'],
            ],
            [
                'name' => 'Family Portrait',
                'slug' => 'family-portrait',
                'category' => 'Family',
                'short_description' => 'Sample: group portraits for the whole family.',
                'description' => 'Sample: a studio session for families of up to six people, with group and individual shots.',
                'price' => '3500.00',
                'price_requires_confirmation' => false,
                'duration_minutes' => 90,
                'max_people' => 6,
                'digital_outputs' => 20,
                'inclusions' => ['90 minutes in the studio', 'Group and individual shots', '20 edited digital photos', 'One printed 8R photo', 'Choice of three backdrops', 'Online gallery for viewing'],
                'terms' => null,
                'is_published' => true,
                'is_featured' => true,
                'themes' => ['Warm Cream', 'Soft White', 'Library Wood'],
            ],
            [
                'name' => 'Graduation Portraits',
                'slug' => 'graduation-portraits',
                'category' => 'Graduation',
                'short_description' => 'Sample: formal portraits in toga and regalia, with the price confirmed after review.',
                'description' => 'Sample: a graduation session with formal and casual poses. The price may change with the number of people.',
                'price' => '1850.00',
                'price_requires_confirmation' => true,
                'duration_minutes' => 60,
                'max_people' => 3,
                'digital_outputs' => 10,
                'inclusions' => ['60 minutes in the studio', 'Formal and casual poses', 'Family photo with up to 2 guests', '10 edited digital photos', 'Online gallery for viewing'],
                'terms' => 'Sample: bring your own toga and cap.',
                'is_published' => true,
                'is_featured' => false,
                'themes' => ['Classic Crimson', 'Library Wood', 'Dark Charcoal'],
            ],
            [
                'name' => 'Birthday and Kids Shoot',
                'slug' => 'birthday-and-kids-shoot',
                'category' => 'Kids and birthday',
                'short_description' => 'Sample: a fun session for kids and birthday celebrants. Ask for a price.',
                'description' => 'Sample: a playful session for birthdays and kids, with props. The price depends on what you need, so ask first.',
                'price' => null,
                'price_requires_confirmation' => false,
                'duration_minutes' => 120,
                'max_people' => 10,
                'digital_outputs' => 30,
                'inclusions' => ['Up to 2 hours in the studio', 'Props and backdrops', '30 edited digital photos', 'Group shots with guests'],
                'terms' => null,
                'is_published' => true,
                'is_featured' => false,
                'themes' => ['Soft White', 'Sage Garden'],
            ],
            [
                'name' => 'Small Event Coverage',
                'slug' => 'small-event-coverage',
                'category' => 'Event',
                'short_description' => 'Sample: coverage for a small event. Kept unpublished while the rates are being decided.',
                'description' => null,
                'price' => null,
                'price_requires_confirmation' => false,
                'duration_minutes' => 180,
                'max_people' => 30,
                'digital_outputs' => null,
                'inclusions' => ['Up to 3 hours of coverage', 'Edited digital photos', 'Online gallery for viewing'],
                'terms' => null,
                'is_published' => false,
                'is_featured' => false,
                'themes' => ['Dark Charcoal', 'Soft White'],
            ],
        ];
    }
}

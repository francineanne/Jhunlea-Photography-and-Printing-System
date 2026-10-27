<?php

namespace Database\Seeders;

use App\Enums\OptionGroup;
use App\Enums\PricingType;
use App\Models\PrintingService;
use Illuminate\Database\Seeder;

/**
 * SAMPLE DATA — placeholder content to be replaced with the shop's real catalog.
 *
 * Names, prices and options below are invented for development and demos. Safe to run more than once:
 * services are matched by slug and left alone when they already exist (edits, removed options and deleted
 * services are kept), so nothing is duplicated or overwritten. Creates no users.
 */
class SampleCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalog() as $sortOrder => $data) {
            $options = $data['options'];
            unset($data['options']);

            // withTrashed: a sample service the owner deleted stays deleted
            $service = PrintingService::withTrashed()->firstOrCreate(
                ['slug' => $data['slug']],
                $data + ['sort_order' => ($sortOrder + 1) * 10, 'max_file_size_mb' => 2],
            );

            // Only a newly created service gets its options, so a re-run never brings back options the owner removed
            if (! $service->wasRecentlyCreated) {
                continue;
            }

            foreach ($options as [$group, $label, $modifier]) {
                $service->options()->create(['group' => $group, 'label' => $label, 'price_modifier' => $modifier, 'is_active' => true]);
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function catalog(): array
    {
        return [
            [
                'name' => 'Document Printing',
                'slug' => 'document-printing',
                'category' => 'Documents',
                'description' => 'Sample: everyday printing for school and office documents.',
                'pricing_type' => PricingType::PerPage,
                'base_price' => '3.00',
                'allowed_file_types' => ['pdf', 'docx', 'pptx', 'xlsx', 'jpg', 'png'],
                'instructions' => 'Sample: please check the page order before sending your file.',
                'is_published' => true,
                'options' => [
                    [OptionGroup::PaperSize, 'Short (8.5 x 11 in)', '0.00'],
                    [OptionGroup::PaperSize, 'A4 (210 x 297 mm)', '0.00'],
                    [OptionGroup::PaperSize, 'Legal (8.5 x 13 in)', '1.00'],
                    [OptionGroup::PaperType, 'Bond paper 70 gsm', '0.00'],
                    [OptionGroup::PaperType, 'Bond paper 80 gsm', '1.00'],
                    [OptionGroup::ColorMode, 'Black and white', '0.00'],
                    [OptionGroup::ColorMode, 'Full color', '5.00'],
                    [OptionGroup::Sides, 'One side', '0.00'],
                    [OptionGroup::Sides, 'Both sides', '0.50'],
                ],
            ],
            [
                'name' => 'Photo Printing',
                'slug' => 'photo-printing',
                'category' => 'Photos',
                'description' => 'Sample: prints from your digital photos.',
                'pricing_type' => PricingType::PerCopy,
                'base_price' => '15.00',
                'allowed_file_types' => ['jpg', 'png', 'pdf'],
                'instructions' => 'Sample: send the original photo files for the best result.',
                'is_published' => true,
                'options' => [
                    [OptionGroup::PrintSize, '4R (4 x 6 in)', '0.00'],
                    [OptionGroup::PrintSize, '5R (5 x 7 in)', '10.00'],
                    [OptionGroup::PrintSize, '8R (8 x 10 in)', '35.00'],
                    [OptionGroup::PrintSize, 'A4 (8.3 x 11.7 in)', '60.00'],
                    [OptionGroup::PaperType, 'Glossy photo paper', '0.00'],
                    [OptionGroup::PaperType, 'Matte photo paper', '3.00'],
                    [OptionGroup::Finish, 'With white border', '0.00'],
                    [OptionGroup::Finish, 'Borderless', '2.00'],
                ],
            ],
            [
                'name' => 'ID and Passport Photos',
                'slug' => 'id-and-passport-photos',
                'category' => 'Photos',
                'description' => 'Sample: ID-size photo packs printed from your digital photo.',
                'pricing_type' => PricingType::PerCopy,
                'base_price' => '120.00',
                'allowed_file_types' => ['jpg', 'png'],
                'instructions' => 'Sample: use a plain, light background and a front-facing photo.',
                'is_published' => true,
                'options' => [
                    [OptionGroup::PrintSize, '1 x 1 in (pack of 8)', '0.00'],
                    [OptionGroup::PrintSize, '2 x 2 in (pack of 6)', '0.00'],
                    [OptionGroup::PrintSize, 'Passport size 35 x 45 mm (pack of 4)', '20.00'],
                    [OptionGroup::PaperType, 'Glossy photo paper', '0.00'],
                    [OptionGroup::PaperType, 'Matte photo paper', '0.00'],
                ],
            ],
            [
                'name' => 'Tarpaulin Printing',
                'slug' => 'tarpaulin-printing',
                'category' => 'Large format',
                'description' => 'Sample: banners and tarpaulins priced after the owner checks your request.',
                'pricing_type' => PricingType::Quotation,
                'base_price' => null,
                'allowed_file_types' => ['pdf', 'jpg', 'png'],
                'instructions' => 'Sample: send your layout at the final size, with the text kept away from the edges.',
                'is_published' => true,
                'options' => [
                    [OptionGroup::PrintSize, '2 x 3 ft', '0.00'],
                    [OptionGroup::PrintSize, '3 x 4 ft', '0.00'],
                    [OptionGroup::PrintSize, '4 x 6 ft', '0.00'],
                    [OptionGroup::ColorMode, 'Full color', '0.00'],
                    [OptionGroup::Finish, 'Hemmed edges with eyelets', '0.00'],
                    [OptionGroup::Finish, 'No finishing', '0.00'],
                ],
            ],
            [
                'name' => 'Sticker Printing',
                'slug' => 'sticker-printing',
                'category' => 'Stickers and labels',
                'description' => 'Sample: custom stickers cut to your design.',
                'pricing_type' => PricingType::PerCopy,
                'base_price' => '8.00',
                'allowed_file_types' => ['pdf', 'png', 'jpg'],
                'instructions' => 'Sample: send your design with the cut line on a separate layer.',
                'is_published' => true,
                'options' => [
                    [OptionGroup::PrintSize, 'Small (2 in)', '0.00'],
                    [OptionGroup::PrintSize, 'Medium (3 in)', '5.00'],
                    [OptionGroup::PrintSize, 'Large (4 in)', '10.00'],
                    [OptionGroup::PaperType, 'Glossy vinyl', '0.00'],
                    [OptionGroup::PaperType, 'Matte vinyl', '3.00'],
                    [OptionGroup::PaperType, 'Waterproof vinyl', '6.00'],
                    [OptionGroup::Finish, 'Kiss-cut', '0.00'],
                    [OptionGroup::Finish, 'Die-cut', '4.00'],
                ],
            ],
            [
                'name' => 'Lamination and Binding',
                'slug' => 'lamination-and-binding',
                'category' => 'Finishing',
                'description' => 'Sample: lamination and binding for documents and certificates.',
                'pricing_type' => PricingType::PerCopy,
                'base_price' => '20.00',
                'allowed_file_types' => ['pdf', 'docx'],
                'instructions' => 'Sample: bring the documents to the shop, or send the file if you also need printing.',
                'is_published' => true,
                'options' => [
                    [OptionGroup::PaperSize, 'Short (8.5 x 11 in)', '0.00'],
                    [OptionGroup::PaperSize, 'A4 (210 x 297 mm)', '0.00'],
                    [OptionGroup::PaperSize, 'Legal (8.5 x 13 in)', '2.00'],
                    [OptionGroup::Finish, 'Gloss lamination', '0.00'],
                    [OptionGroup::Finish, 'Matte lamination', '5.00'],
                    [OptionGroup::Finish, 'Spiral binding', '30.00'],
                ],
            ],
            [
                'name' => 'Large-Format Plotting',
                'slug' => 'large-format-plotting',
                'category' => 'Large format',
                'description' => 'Sample: plotted drawings and posters. Kept unpublished while the rates are being decided.',
                'pricing_type' => PricingType::PerPage,
                'base_price' => '45.00',
                'allowed_file_types' => ['pdf', 'png'],
                'instructions' => null,
                'is_published' => false,
                'options' => [
                    [OptionGroup::PaperSize, 'A2 (420 x 594 mm)', '0.00'],
                    [OptionGroup::PaperSize, 'A1 (594 x 841 mm)', '60.00'],
                    [OptionGroup::ColorMode, 'Black and white', '0.00'],
                    [OptionGroup::ColorMode, 'Full color', '40.00'],
                ],
            ],
        ];
    }
}

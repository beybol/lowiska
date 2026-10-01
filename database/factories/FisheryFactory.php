<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Fish;
use App\Models\Fishery;
use App\Models\State;
use App\Models\User;
use App\Services\FisheryImages;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\UploadedFile;

/**
 * @extends Factory<Fishery>
 */
class FisheryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->company(),
            'street' => $this->faker->streetName(),
            'building_number' => $this->faker->buildingNumber(),
            'town' => $this->faker->city(),
            'state_id' => State::factory(),
            'company_id' => Company::factory(),
            'directions' => $this->faker->sentence(),
            'description' => $this->faker->paragraph(),
            'zip_code' => $this->faker->postcode(),
            'area' => $this->faker->randomFloat(2, 1, 100),
            'avg_depth' => $this->faker->randomFloat(2, 1, 10),
            'max_depth' => $this->faker->randomFloat(2, 10, 50),
            'dominant_fish_id' => Fish::factory(),
            'records' => $this->faker->sentence(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'published_at' => now(),
        ]);
    }

    /**
     * Łowisko ze zdjęciami w medialibrary (zadanie 036): `$photos` zdjęć galerii o podanych wymiarach i mapa.
     *
     * ⚠️ Zapisuje PRAWDZIWE pliki i generuje warianty (libvips) na dysku medialibrary — test, który tego
     * używa, robi wcześniej `Storage::fake()` na tym dysku, inaczej pliki lądują w `storage/app/public`.
     */
    public function withPhotos(int $photos = 1, bool $map = true, int $width = 1200, int $height = 800): static
    {
        return $this->afterCreating(function (Fishery $fishery) use ($photos, $map, $width, $height): void {
            for ($i = 1; $i <= $photos; $i++) {
                // Plik trzymany w zmiennej: plik tymczasowy `fake()` znika razem z obiektem.
                $file = UploadedFile::fake()->image("photo-{$i}.jpg", $width, $height);
                $fishery->addMedia($file)
                    ->usingFileName("photo-{$i}.jpg")
                    ->toMediaCollection(FisheryImages::GALLERY);
            }

            if ($map) {
                $file = UploadedFile::fake()->image('map.jpg', $width, $height);
                $fishery->addMedia($file)
                    ->usingFileName('map.jpg')
                    ->toMediaCollection(FisheryImages::MAP);
            }
        });
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
        ]);
    }
}

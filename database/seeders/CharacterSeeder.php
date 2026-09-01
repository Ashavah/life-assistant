<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\CharacterProvisioner;
use Illuminate\Database\Seeder;

class CharacterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(CharacterProvisioner $provisioner): void
    {
        User::query()->each(
            fn (User $user) => $provisioner->provision($user),
        );
    }
}

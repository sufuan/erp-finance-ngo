<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed order follows the build steps: foundations first (branches),
     * then RBAC (Step 2), settings (Step 3), accounting seed (Step 3A),
     * geo masters (Step 4), HR masters (Step 5).
     */
    public function run(): void
    {
        $this->call([
            BranchSeeder::class,
        ]);
    }
}

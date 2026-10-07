<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('plans') as $code => $attributes) {
            // Re-running seeders never overwrites a plan customized in the database.
            Plan::firstOrCreate(['code' => $code], $attributes);
        }
    }
}

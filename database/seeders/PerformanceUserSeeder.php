<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PerformanceUserSeeder extends Seeder
{
    public function run(): void
    {
        DB::disableQueryLog();
        DB::statement('SET synchronous_commit = OFF');

        $batchSize = 5000;
        $totalRows = 1_000_000;
        $batches = $totalRows / $batchSize;
        $now = date('Y-m-d H:i:s');

        // Birthdate range: 1950-01-01 to 2005-12-31 as Unix timestamps
        $minTs = mktime(0, 0, 0, 1, 1, 1950);
        $maxTs = mktime(0, 0, 0, 12, 31, 2005);

        for ($batch = 0; $batch < $batches; $batch++) {
            $rows = [];

            for ($i = 0; $i < $batchSize; $i++) {
                $name = base_convert((string) rand(100000, 999999), 10, 36);
                $surname = base_convert((string) rand(100000, 999999), 10, 36);
                $email = $name . '.' . $surname . rand(1, 9999) . '@example.com';
                $birthdate = date('Y-m-d', rand($minTs, $maxTs));

                $rows[] = "('" . $name . "','" . $surname . "','" . $email . "','" . $birthdate . "','" . $now . "','" . $now . "')";
            }

            DB::unprepared(
                'INSERT INTO performance_users (name, surname, email, birthdate, created_at, updated_at) VALUES '
                . implode(',', $rows)
            );
        }
    }
}

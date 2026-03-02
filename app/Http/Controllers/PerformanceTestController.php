<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use PDO;

class PerformanceTestController extends Controller
{
    public function index(): Response
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $totalStart = microtime(true);
        $memAtStart = memory_get_usage();

        // Step 6 prep: generate 50 unique random IDs using mt_rand(0, 1000000) as specified
        $randomIds = [];
        while (count($randomIds) < 50) {
            $randomIds[mt_rand(0, 1_000_000)] = true;
        }
        $randomIds = array_keys($randomIds);

        // Steps 3–5: stream all 1M rows (id, name, surname, birthdate) in one query.
        // FETCH_NUM uses less memory than FETCH_OBJ/FETCH_ASSOC.
        // gc_disable() eliminates GC overhead inside the tight loop.
        $step45Start = microtime(true);

        $pdo = DB::connection()->getPdo();
        $pdo->exec("SET work_mem = '256MB'");

        $stmt = $pdo->prepare('SELECT id, name, surname, birthdate FROM performance_users');
        $stmt->execute();

        // Integer trick: (YYYYMMDD_today - YYYYMMDD_birth) / 10000 = age in years (leap-year-safe)
        $todayInt = (int) date('Ymd');

        $totalAge = 0;
        $count = 0;
        $userIndex = []; // in-memory index keyed by id for O(1) step 6 lookup

        gc_disable();

        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            // $row: [0]=id, [1]=name, [2]=surname, [3]=birthdate
            $birthdateInt = (int) str_replace('-', '', $row[3]);
            $totalAge += (int) (($todayInt - $birthdateInt) / 10000);
            $userIndex[(int) $row[0]] = $row[1] . ' ' . $row[2];
            $count++;
        }

        gc_enable();
        gc_collect_cycles();

        $meanAge = $count > 0 ? $totalAge / $count : 0;
        $step45Time = microtime(true) - $step45Start;

        // Step 6: in-memory lookup — no second DB query needed
        $step6Start = microtime(true);
        $randomUsers = [];
        foreach ($randomIds as $id) {
            if (isset($userIndex[$id])) {
                $randomUsers[] = $userIndex[$id];
            }
        }
        $step6Time = microtime(true) - $step6Start;

        $totalTime = microtime(true) - $totalStart;
        $peakMemoryMb = memory_get_peak_usage(true) / 1024 / 1024;
        $memUsedMb = (memory_get_usage() - $memAtStart) / 1024 / 1024;

        $output = "=== Performance Results ===\n";
        $output .= sprintf("Total time       : %.4f s\n", $totalTime);
        $output .= sprintf("Steps 4–5 time   : %.4f s  (fetch all + compute mean age)\n", $step45Time);
        $output .= sprintf("Step 6 time      : %.4f s  (in-memory lookup, no DB query)\n", $step6Time);
        $output .= sprintf("Peak memory      : %.2f MB\n", $peakMemoryMb);
        $output .= sprintf("Memory delta     : %.2f MB\n", $memUsedMb);
        $output .= sprintf("Rows processed   : %d\n", $count);
        $output .= sprintf("Ortalama Yaş     : %.2f\n", $meanAge);
        $output .= "\n=== 50 Rastgele Kullanıcı ===\n";

        foreach ($randomUsers as $fullName) {
            $output .= $fullName . "\n";
        }

        return response($output)->header('Content-Type', 'text/plain; charset=utf-8');
    }
}

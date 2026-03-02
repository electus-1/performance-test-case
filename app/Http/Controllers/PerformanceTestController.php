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

        // Steps 3–5: stream only birthdate — minimal network payload over remote DB.
        // PDO cursor reads one row at a time; PHP memory stays flat across 1M rows.
        $step45Start = microtime(true);

        $pdo = DB::connection()->getPdo();
        $pdo->exec("SET work_mem = '256MB'");

        $stmt = $pdo->prepare('SELECT birthdate FROM performance_users');
        $stmt->execute();

        // Integer trick: (YYYYMMDD_today - YYYYMMDD_birth) / 10000 = age in years (leap-year-safe)
        $todayInt = (int) date('Ymd');

        $totalAge = 0;
        $count = 0;

        gc_disable();

        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $birthdateInt = (int) str_replace('-', '', $row[0]);
            $totalAge += (int) (($todayInt - $birthdateInt) / 10000);
            $count++;
        }

        gc_enable();
        gc_collect_cycles();

        $meanAge = $count > 0 ? $totalAge / $count : 0;
        $step45Time = microtime(true) - $step45Start;

        // Step 6: single indexed query — 50 PK lookups, one round-trip
        $step6Start = microtime(true);
        $randomUsers = DB::table('performance_users')
            ->select(['name', 'surname'])
            ->whereIn('id', $randomIds)
            ->get();
        $step6Time = microtime(true) - $step6Start;

        $totalTime = microtime(true) - $totalStart;
        $peakMemoryMb = memory_get_peak_usage(true) / 1024 / 1024;
        $memUsedMb = (memory_get_usage() - $memAtStart) / 1024 / 1024;

        $output = "=== Performance Results ===\n";
        $output .= sprintf("Total time       : %.4f s\n", $totalTime);
        $output .= sprintf("Steps 4–5 time   : %.4f s  (fetch all + compute mean age)\n", $step45Time);
        $output .= sprintf("Step 6 time      : %.4f s  (WHERE id IN, PK index)\n", $step6Time);
        $output .= sprintf("Peak memory      : %.2f MB\n", $peakMemoryMb);
        $output .= sprintf("Memory delta     : %.2f MB\n", $memUsedMb);
        $output .= sprintf("Rows processed   : %d\n", $count);
        $output .= sprintf("Ortalama Yaş     : %.2f\n", $meanAge);
        $output .= "\n=== 50 Rastgele Kullanıcı ===\n";

        foreach ($randomUsers as $user) {
            $output .= $user->name . ' ' . $user->surname . "\n";
        }

        return response($output)->header('Content-Type', 'text/plain; charset=utf-8');
    }
}

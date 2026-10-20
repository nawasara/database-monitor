<?php

namespace Nawasara\DatabaseMonitor\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Menipiskan `nawasara_db_size_snapshots`.
 *
 * SyncDatabaseMetricsJob menulis satu baris per database setiap putaran
 * metrik; untuk 155 database itu ratusan ribu baris sebulan (811 ribu per
 * Oktober 2026). Detail per putaran berguna untuk beberapa minggu terakhir,
 * tetapi untuk tren berbulan-bulan cukup satu titik per hari.
 *
 * Maka: di hari yang lebih tua dari keep_detail_days, hanya snapshot TERAKHIR
 * tiap database pada hari itu yang disimpan. Tidak ada yang dihapus seluruhnya,
 * jadi grafik pertumbuhan jangka panjang tetap bisa digambar.
 *
 * Dikerjakan per hari, dan hanya hari yang memang masih punya lebih dari satu
 * snapshot per database. Hari yang sudah ditipiskan dilewati, sehingga putaran
 * harian berikutnya hanya menyentuh satu hari baru.
 */
class PruneSnapshotsCommand extends Command
{
    protected $signature = 'nawasara-database-monitor:prune-snapshots
        {--dry-run : Hitung saja, tidak menghapus apa pun}';

    protected $description = 'Downsample old database size snapshots to one per database per day';

    private const TABLE = 'nawasara_db_size_snapshots';

    public function handle(): int
    {
        $days = (int) config('nawasara-database-monitor.prune.keep_detail_days', 90);
        if ($days <= 0) {
            $this->warn('keep_detail_days 0, pembersihan dilewati.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days)->startOfDay();

        $dates = DB::table(self::TABLE)
            ->where('captured_at', '<', $cutoff)
            ->selectRaw('DATE(captured_at) AS d')
            ->groupBy('d')
            ->havingRaw('COUNT(*) > COUNT(DISTINCT database_id)')
            ->orderBy('d')
            ->pluck('d');

        $this->info('Hari yang perlu ditipiskan: '.$dates->count());

        $total = 0;

        foreach ($dates as $date) {
            $from = $date.' 00:00:00';
            $to = $date.' 23:59:59';

            $keep = DB::table(self::TABLE)
                ->whereBetween('captured_at', [$from, $to])
                ->selectRaw('MAX(id) AS id')
                ->groupBy('database_id')
                ->pluck('id')
                ->all();

            $query = DB::table(self::TABLE)
                ->whereBetween('captured_at', [$from, $to])
                ->whereNotIn('id', $keep);

            if ($dry) {
                $n = (clone $query)->count();
            } else {
                $n = 0;
                do {
                    $batch = (clone $query)->limit(5000)->delete();
                    $n += $batch;
                    if ($batch > 0) {
                        usleep(100000);
                    }
                } while ($batch > 0);
            }

            $total += $n;
            $this->line(($dry ? '[dry-run] ' : '')."{$date}: ".number_format($n).' dihapus, '.count($keep).' disimpan');
        }

        $this->info(($dry ? '[dry-run] ' : '').'Total: '.number_format($total));

        return self::SUCCESS;
    }
}

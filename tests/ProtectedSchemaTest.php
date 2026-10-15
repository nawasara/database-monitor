<?php

declare(strict_types=1);

namespace Nawasara\DatabaseMonitor\Tests;

use Nawasara\DatabaseMonitor\Services\MysqlAdmin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DROP / GRANT tidak boleh menyasar skema sistem (audit keamanan 15 Oktober
 * 2026, temuan S5). Server yang dikelola dipakai bersama 155 database:
 * DROP `mysql` mematikan semuanya, dan GRANT pada `mysql` sama dengan hak
 * mengubah tabel akun.
 */
class ProtectedSchemaTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function systemSchemas(): array
    {
        return [
            'mysql' => ['mysql'],
            'sys' => ['sys'],
            'information_schema' => ['information_schema'],
            'performance_schema' => ['performance_schema'],
            // lower_case_table_names di Windows/macOS: `MySQL` = `mysql`.
            'huruf besar' => ['MySQL'],
            'spasi' => [' sys '],
        ];
    }

    #[DataProvider('systemSchemas')]
    public function test_skema_sistem_selalu_dilindungi(string $name): void
    {
        $this->assertTrue(MysqlAdmin::isProtectedSchema($name));
    }

    public function test_database_user_tidak_dilindungi(): void
    {
        $this->assertFalse(MysqlAdmin::isProtectedSchema('simpeg'));
        $this->assertFalse(MysqlAdmin::isProtectedSchema('mysql_app'));
        $this->assertFalse(MysqlAdmin::isProtectedSchema('sys2'));
    }

    public function test_config_hanya_menambah(): void
    {
        $this->assertTrue(MysqlAdmin::isProtectedSchema('nawasara', ['nawasara']));

        // Daftar tambahan yang kosong tidak membuka skema sistem.
        $this->assertTrue(MysqlAdmin::isProtectedSchema('mysql', []));
    }

    /** Ketiga perintah tulis lewat penjaga yang sama. */
    public function test_drop_grant_revoke_memeriksa_skema(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__).'/src/Services/MysqlAdmin.php');

        foreach (['dropDatabase', 'grant', 'revokeAll'] as $method) {
            preg_match('/public function '.$method.'\(.*?\n    \}/s', $src, $m);

            $this->assertNotEmpty($m, "metode {$method}() tidak ditemukan");
            $this->assertStringContainsString('assertNotProtectedSchema(', $m[0], "{$method}() tanpa penjaga skema");
        }
    }

    /**
     * Nama database dari server yang dipantau tidak boleh dicetak di dalam
     * kutip JavaScript `wire:click` (S7).
     */
    public function test_blade_tidak_mencetak_nama_di_wire_click(): void
    {
        $views = dirname(__DIR__).'/resources/views';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views));

        foreach ($iterator as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());

            $this->assertDoesNotMatchRegularExpression(
                '/(wire:click|x-on:click|@click)="[^"]*\'\{\{/',
                $src,
                $file->getPathname().' mencetak echo Blade di dalam kutip JS',
            );
        }
    }
}

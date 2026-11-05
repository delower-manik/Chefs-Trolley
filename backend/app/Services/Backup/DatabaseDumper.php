<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/**
 * Writes the whole database as a SQL script. Uses mysqldump when it exists (consistent snapshot, fast); otherwise a
 * built-in PHP dump, because shared hosting often has no mysqldump. Sessions (personal_access_tokens) are left out:
 * a restore should sign everyone out, not resurrect old tokens.
 */
class DatabaseDumper
{
    private const SKIP = ['personal_access_tokens', 'cache', 'cache_locks', 'sessions'];

    public function dump(string $path): string
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true) && $this->mysqldump($path)) {
            return 'mysql (mysqldump)';
        }
        $this->phpDump($path, $driver);

        return $driver.' (php)';
    }

    private function mysqldump(string $path): bool
    {
        $c = config('database.connections.'.config('database.default'));
        $args = [config('backup.mysqldump_binary'), '--single-transaction', '--quick', '--skip-lock-tables', '--routines', '--triggers',
            '-h', (string) $c['host'], '-P', (string) $c['port'], '-u', (string) $c['username'], '--result-file='.$path];
        foreach (self::SKIP as $t) {
            $args[] = '--ignore-table='.$c['database'].'.'.$t;
        }
        $args[] = (string) $c['database'];
        try {
            $p = new Process($args, null, ['MYSQL_PWD' => (string) $c['password']], null, 600); // password via env, never on the command line
            $p->run();

            return $p->isSuccessful() && is_file($path) && filesize($path) > 0;
        } catch (\Throwable) {
            return false; // binary missing → fall back to the PHP dump
        }
    }

    private function phpDump(string $path, string $driver): void
    {
        $out = fopen($path, 'wb');
        $pdo = DB::connection()->getPdo();
        fwrite($out, '-- Chefs Trolley database dump ('.now()->toIso8601String().", {$driver})\n");
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");
        } else {
            fwrite($out, "PRAGMA foreign_keys=OFF;\n\n");
        }

        $tables = collect(Schema::getTables())->pluck('name')->reject(fn ($t) => in_array($t, self::SKIP, true) || str_starts_with($t, 'sqlite_'))->sort()->values();
        foreach ($tables as $table) {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $row = (array) DB::selectOne('SHOW CREATE TABLE `'.str_replace('`', '', $table).'`');
                fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n".($row['Create Table'] ?? '').";\n\n");
            } else {
                $rows = DB::select("select sql from sqlite_master where tbl_name = ? and sql is not null and type in ('table','index') order by type desc", [$table]);
                fwrite($out, "DROP TABLE IF EXISTS \"{$table}\";\n");
                foreach ($rows as $r) {
                    fwrite($out, $r->sql.";\n");
                }
                fwrite($out, "\n");
            }

            $q = in_array($driver, ['mysql', 'mariadb'], true) ? '`' : '"';
            $batch = [];
            $cols = null;
            foreach (DB::table($table)->cursor() as $row) {
                $row = (array) $row;
                $cols ??= implode(',', array_map(fn ($c) => $q.$c.$q, array_keys($row)));
                $batch[] = '('.implode(',', array_map(fn ($v) => $this->literal($pdo, $v), $row)).')';
                if (count($batch) >= 200) {
                    fwrite($out, "INSERT INTO {$q}{$table}{$q} ({$cols}) VALUES\n".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                fwrite($out, "INSERT INTO {$q}{$table}{$q} ({$cols}) VALUES\n".implode(",\n", $batch).";\n");
            }
            fwrite($out, "\n");
        }

        if ($driver === 'sqlite') {
            foreach (DB::select("select sql from sqlite_master where type = 'trigger' and sql is not null") as $r) {
                fwrite($out, $r->sql.";\n");
            }
        }
        fwrite($out, in_array($driver, ['mysql', 'mariadb'], true) ? "SET FOREIGN_KEY_CHECKS=1;\n" : "PRAGMA foreign_keys=ON;\n");
        fclose($out);
    }

    private function literal(\PDO $pdo, mixed $v): string
    {
        return match (true) {
            $v === null => 'NULL',
            is_bool($v) => $v ? '1' : '0',
            is_int($v) => (string) $v,
            is_float($v) => rtrim(rtrim(number_format($v, 10, '.', ''), '0'), '.') ?: '0',
            default => (string) $pdo->quote((string) $v),
        };
    }
}

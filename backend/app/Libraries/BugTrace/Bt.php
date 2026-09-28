<?php

namespace App\Libraries\BugTrace;

use CodeIgniter\Database\BaseConnection;

/**
 * Small shared helpers for the Bug Trace module (JSON columns, timestamps,
 * portable counters/upserts that work on MariaDB and SQLite).
 */
final class Bt
{
    public const UNRESOLVED = ['NEW', 'TRIAGED', 'IN_PROGRESS', 'REGRESSED'];
    public const STATUSES   = ['NEW', 'TRIAGED', 'IN_PROGRESS', 'RESOLVED', 'REGRESSED', 'IGNORED', 'DUPLICATE', 'WONT_FIX'];
    public const PRIORITIES = ['P0', 'P1', 'P2', 'P3', 'P4'];

    public static function db(): BaseConnection
    {
        return db_connect();
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function json(mixed $v): ?string
    {
        return $v === null ? null : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    public static function decode(?string $v, mixed $default = []): mixed
    {
        if ($v === null || $v === '') {
            return $default;
        }
        $d = json_decode($v, true);

        return $d ?? $default;
    }

    public static function iso(?string $dt): ?string
    {
        if ($dt === null || $dt === '') {
            return null;
        }
        $t = strtotime($dt . (preg_match('/[zZ]|[+-]\d\d:?\d\d$/', $dt) ? '' : ' UTC'));

        return $t === false ? null : gmdate('Y-m-d\TH:i:s\Z', $t);
    }

    /** Parse a client timestamp (ISO string or unix seconds/ms) into a DB datetime, clamped to a sane window. */
    public static function clientTime(mixed $ts, ?int $now = null): string
    {
        $now ??= time();
        $t = null;
        if (is_numeric($ts)) {
            $t = (float) $ts > 1e11 ? (int) ((float) $ts / 1000) : (int) $ts;
        } elseif (is_string($ts) && $ts !== '') {
            $t = strtotime($ts) ?: null;
        }
        if ($t === null || $t > $now + 300 || $t < $now - 30 * 86400) {
            $t = $now;
        }

        return gmdate('Y-m-d H:i:s', $t);
    }

    /**
     * Next value of a named counter (atomic on MySQL via LAST_INSERT_ID, serialized on SQLite).
     */
    public static function nextCounter(string $name, int $start = 1): int
    {
        $db = self::db();
        if (str_contains(strtolower($db->DBDriver), 'mysql')) {
            $db->query('INSERT IGNORE INTO bt_counters (name, value) VALUES (?, ?)', [$name, $start - 1]);
            $db->query('UPDATE bt_counters SET value = LAST_INSERT_ID(value + 1) WHERE name = ?', [$name]);

            return (int) $db->query('SELECT LAST_INSERT_ID() AS v')->getRow('v');
        }
        $db->transStart();
        $db->table('bt_counters')->ignore(true)->insert(['name' => $name, 'value' => $start - 1]);
        $db->table('bt_counters')->where('name', $name)->set('value', 'value + 1', false)->update();
        $v = (int) $db->table('bt_counters')->select('value')->where('name', $name)->get()->getRow('value');
        $db->transComplete();

        return $v;
    }

    /**
     * UPDATE … SET col = col + n; INSERT when no row matched (portable upsert-increment).
     *
     * @param array<string, scalar|null> $where   unique key columns
     * @param array<string, int>         $incr    column => delta
     * @param array<string, mixed>       $set     plain columns to set on update and insert
     * @param array<string, mixed>       $insert  extra columns for the insert only
     */
    public static function increment(string $table, array $where, array $incr, array $set = [], array $insert = []): void
    {
        $db = self::db();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $b = $db->table($table)->where($where);
            foreach ($incr as $col => $n) {
                $b->set($col, $db->protectIdentifiers($col) . ' + ' . (int) $n, false);
            }
            foreach ($set as $col => $v) {
                $b->set($col, $v);
            }
            $b->update();
            if ($db->affectedRows() > 0) {
                return;
            }

            try {
                $db->table($table)->insert($where + $incr + $set + $insert);

                return;
            } catch (\Throwable) {
                // Lost a race with a concurrent insert — retry the update.
            }
        }
    }

    /**
     * A usable multipart upload. is_uploaded_file() is false for files injected
     * by the CLI test runner, so ENVIRONMENT=testing accepts any readable temp file.
     */
    public static function uploadOk(?\CodeIgniter\HTTP\Files\UploadedFile $f): bool
    {
        if ($f === null) {
            return false;
        }

        return $f->isValid() || (ENVIRONMENT === 'testing' && $f->getError() === UPLOAD_ERR_OK && is_file($f->getTempName()));
    }

    public static function base36(int $n): string
    {
        return strtoupper(base_convert((string) $n, 10, 36));
    }

    /** Project public id: btp_web, btp_dsk … derived from the issue prefix. */
    public static function projectPublicId(array $project): string
    {
        return 'btp_' . strtolower(preg_replace('/^WS-/i', '', (string) $project['issue_prefix']));
    }

    /** Deterministic sampling decision in [0,1) from an id. */
    public static function sampleValue(string $id): float
    {
        return hexdec(substr(hash('sha256', $id), 0, 8)) / 4294967296;
    }

    /** @return array{id: string, name: string}|null */
    public static function userRef(?int $userId): ?array
    {
        if ($userId === null || $userId === 0) {
            return null;
        }
        $u = self::db()->table('users')->select('public_id, name, email')->where('id', $userId)->get()->getRowArray();

        return $u ? ['id' => (string) $u['public_id'], 'name' => (string) ($u['name'] ?: $u['email'])] : null;
    }

    /** Settings table JSON value (key => JSON) merged over defaults. */
    public static function setting(string $key, array $defaults): array
    {
        $row = self::db()->table('settings')->where('key', $key)->get()->getRowArray();
        $val = $row ? self::decode($row['value'], []) : [];

        return array_replace($defaults, is_array($val) ? $val : []);
    }

    public static function saveSetting(string $key, array $value): void
    {
        $db  = self::db();
        $now = self::now();
        if ($db->table('settings')->where('key', $key)->countAllResults() > 0) {
            $db->table('settings')->where('key', $key)->update(['value' => self::json($value), 'updated_at' => $now]);
        } else {
            $db->table('settings')->insert(['key' => $key, 'value' => self::json($value), 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}

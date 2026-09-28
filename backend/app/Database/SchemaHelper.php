<?php

namespace App\Database;

/**
 * Compact column builders shared by migrations. Types are chosen so the same
 * migrations run on MariaDB/MySQL (production) and SQLite (tests).
 * JSON columns are stored as TEXT (MariaDB's JSON is an alias of LONGTEXT).
 */
trait SchemaHelper
{
    protected function pk(): array
    {
        return ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true];
    }

    protected function fk(bool $null = false): array
    {
        return ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => $null];
    }

    protected function str(int $len = 191, bool $null = false, ?string $default = null): array
    {
        $col = ['type' => 'VARCHAR', 'constraint' => $len, 'null' => $null];
        if ($default !== null) {
            $col['default'] = $default;
        }

        return $col;
    }

    protected function text(bool $null = true): array
    {
        return ['type' => 'TEXT', 'null' => $null];
    }

    protected function longtext(bool $null = true): array
    {
        return ['type' => 'LONGTEXT', 'null' => $null];
    }

    protected function int(bool $null = false, ?int $default = 0): array
    {
        $col = ['type' => 'INT', 'constraint' => 11, 'null' => $null];
        if ($default !== null) {
            $col['default'] = $default;
        }

        return $col;
    }

    protected function bigint(bool $null = false, ?int $default = 0): array
    {
        $col = ['type' => 'BIGINT', 'constraint' => 20, 'null' => $null];
        if ($default !== null) {
            $col['default'] = $default;
        }

        return $col;
    }

    protected function bool(bool $default = false): array
    {
        return ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => $default ? 1 : 0];
    }

    protected function dt(bool $null = true): array
    {
        return ['type' => 'DATETIME', 'null' => $null];
    }

    protected function decimal(bool $null = true): array
    {
        return ['type' => 'DECIMAL', 'constraint' => '8,4', 'null' => $null];
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param list<string|list<string>>           $keys
     * @param list<string|list<string>>           $unique
     */
    protected function table(string $name, array $fields, array $keys = [], array $unique = [], bool $timestamps = true, bool $autoPk = true): void
    {
        if ($autoPk) {
            $fields = ['id' => $this->pk()] + $fields;
        }
        if ($timestamps) {
            $fields['created_at'] = $this->dt();
            $fields['updated_at'] = $this->dt();
        }
        $this->forge->addField($fields);
        if ($autoPk) {
            $this->forge->addPrimaryKey('id');
        }
        foreach ($keys as $k) {
            $this->forge->addKey($k);
        }
        foreach ($unique as $u) {
            $this->forge->addUniqueKey($u);
        }
        $this->forge->createTable($name, true);
    }
}

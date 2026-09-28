<?php

namespace App\Services;

use App\Exceptions\ApiException;

/**
 * Lookups by public identifier / slug / code shared by the services.
 */
final class Catalog
{
    public static function app(string $idOrSlug): array
    {
        $db  = db_connect();
        $row = $db->table('applications')->groupStart()->where('public_id', $idOrSlug)->orWhere('slug', $idOrSlug)->orWhere('app_identifier', $idOrSlug)->groupEnd()->get()->getRowArray();

        return $row ?? throw ApiException::notFound('APP_NOT_FOUND', 'Unknown application');
    }

    public static function appById(int $id): array
    {
        return db_connect()->table('applications')->where('id', $id)->get()->getRowArray() ?? throw ApiException::notFound('APP_NOT_FOUND', 'Unknown application');
    }

    public static function channel(string $code, ?int $appId = null): array
    {
        $code = strtoupper(trim($code));
        $db   = db_connect();
        if ($appId !== null) {
            $row = $db->table('release_channels')->where('code', $code)->where('application_id', $appId)->get()->getRowArray();
            if ($row) {
                return $row;
            }
        }

        return $db->table('release_channels')->where('code', $code)->where('application_id', null)->get()->getRowArray()
            ?? throw new ApiException('CHANNEL_NOT_FOUND', "Unknown channel {$code}", 404);
    }

    public static function channelById(int $id): array
    {
        return self::cache('ch' . $id, static fn () => db_connect()->table('release_channels')->where('id', $id)->get()->getRowArray()) ?? throw new ApiException('CHANNEL_NOT_FOUND', 'Unknown channel', 404);
    }

    public static function release(string $publicId): array
    {
        return db_connect()->table('releases')->where('public_id', $publicId)->get()->getRowArray() ?? throw ApiException::notFound('RELEASE_NOT_FOUND', 'Release not found');
    }

    public static function releaseById(int $id): array
    {
        return db_connect()->table('releases')->where('id', $id)->get()->getRowArray() ?? throw ApiException::notFound('RELEASE_NOT_FOUND', 'Release not found');
    }

    /** @return list<array<string,mixed>> */
    public static function platforms(int $appId): array
    {
        $rows = db_connect()->table('application_platforms')->where('application_id', $appId)->orderBy('id')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['package_types'] = json_decode((string) $r['package_types'], true) ?: [];
        }

        return $rows;
    }

    public static function activeStorage(): array
    {
        return db_connect()->table('storage_providers')->where('is_active', 1)->orderBy('id', 'DESC')->get()->getRowArray()
            ?? throw new ApiException('STORAGE_NOT_CONFIGURED', 'No active storage provider', 503);
    }

    public static function storageById(int $id): array
    {
        return db_connect()->table('storage_providers')->where('id', $id)->get()->getRowArray() ?? throw new ApiException('STORAGE_NOT_FOUND', 'Storage provider not found', 404);
    }

    public static function userLabel(?int $userId): ?string
    {
        if (! $userId) {
            return null;
        }

        return self::cache('u' . $userId, static fn () => db_connect()->table('users')->select('name')->where('id', $userId)->get()->getRow('name'));
    }

    /** @var array<string, mixed> */
    private static array $memo = [];

    private static function cache(string $key, callable $fn): mixed
    {
        if (! array_key_exists($key, self::$memo)) {
            self::$memo[$key] = $fn();
        }

        return self::$memo[$key];
    }

    public static function flush(): void
    {
        self::$memo = [];
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function iso(?string $dt): ?string
    {
        if ($dt === null || $dt === '') {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', strtotime($dt . ' UTC'));
    }

    public static function toDb(?string $iso): ?string
    {
        if ($iso === null || trim($iso) === '') {
            return null;
        }
        $t = strtotime($iso);
        if ($t === false) {
            throw ApiException::validation(['date' => "Invalid date {$iso}"]);
        }

        return gmdate('Y-m-d H:i:s', $t);
    }
}

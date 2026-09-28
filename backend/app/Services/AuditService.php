<?php

namespace App\Services;

use App\Libraries\CanonicalJson;

/**
 * Append-only audit log with hash chaining (FR-UC-AUD-01..03, NFR-C09).
 * hash = SHA-256(prev_hash ∥ canonical(entry)). verifyChain() detects edits.
 */
class AuditService
{
    public function __construct(private readonly AuthContext $auth)
    {
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public function record(string $action, ?string $entityType = null, ?string $entityId = null, ?array $before = null, ?array $after = null, ?string $reason = null, ?array $actorOverride = null): array
    {
        $db   = db_connect();
        $req  = service('request');
        $ip   = method_exists($req, 'getIPAddress') ? $req->getIPAddress() : null;
        $ua   = method_exists($req, 'getUserAgent') ? substr((string) $req->getUserAgent(), 0, 255) : null;
        $prev = $db->table('audit_logs')->select('hash')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        $entry = [
            'actor_type'  => $actorOverride['type'] ?? $this->auth->actorType(),
            'actor_id'    => $actorOverride['id'] ?? $this->auth->actorId(),
            'actor_label' => $actorOverride['label'] ?? $this->auth->actorLabel(),
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'before'      => $before === null ? null : CanonicalJson::encode($before),
            'after'       => $after === null ? null : CanonicalJson::encode($after),
            'reason'      => $reason,
            'ip'          => $ip,
            'user_agent'  => $ua,
            'prev_hash'   => $prev['hash'] ?? null,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ];
        $entry['hash'] = self::hashEntry($entry);
        $db->table('audit_logs')->insert($entry);
        $entry['id'] = $db->insertID();

        return $entry;
    }

    /** @param array<string,mixed> $e */
    public static function hashEntry(array $e): string
    {
        $fields = ['actor_type', 'actor_id', 'actor_label', 'action', 'entity_type', 'entity_id', 'before', 'after', 'reason', 'ip', 'user_agent', 'created_at'];
        $data   = [];
        foreach ($fields as $f) {
            $data[$f] = $e[$f] ?? null;
        }

        return hash('sha256', ($e['prev_hash'] ?? '') . '|' . CanonicalJson::encode($data));
    }

    /** @return array{ok: bool, checked: int, broken_at: ?int} */
    public function verifyChain(): array
    {
        $db    = db_connect();
        $prev  = null;
        $count = 0;
        $last  = 0;
        while (true) {
            $rows = $db->table('audit_logs')->where('id >', $last)->orderBy('id')->limit(1000)->get()->getResultArray();
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                $count++;
                if (($r['prev_hash'] ?? null) !== $prev || self::hashEntry($r) !== $r['hash']) {
                    return ['ok' => false, 'checked' => $count, 'broken_at' => (int) $r['id']];
                }
                $prev = $r['hash'];
                $last = (int) $r['id'];
            }
        }

        return ['ok' => true, 'checked' => $count, 'broken_at' => null];
    }
}

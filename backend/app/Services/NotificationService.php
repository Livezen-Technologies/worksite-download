<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Jobs\SendWebhookJob;
use App\Libraries\Queue;

/**
 * Internal notifications (FR-UC-NTF-01/02): in-app centre + email, optional
 * Slack / WorkSuite Chat webhook. All outbound delivery is queued.
 */
class NotificationService
{
    /** kind => permission whose holders receive it */
    private const AUDIENCE = [
        'release.ready_for_qa'         => 'release.approve.qa',
        'release.qa_passed'            => 'release.approve',
        'release.qa_failed'            => 'release.edit',
        'release.approved'             => 'release.publish',
        'release.published'            => 'release.view',
        'release.publish_failed'       => 'release.publish',
        'release.withdrawn'            => 'release.view',
        'rollout.paused'               => 'rollout.manage',
        'rollout.alert'                => 'rollout.manage',
        'package.verification_failed'  => 'release.upload',
        'upload.failed'                => 'release.upload',
        'storage.problem'              => 'storage.manage',
        'distribution.degraded'        => 'release.publish',
        'bugtrace.alert'               => 'bugtrace.triage',
        'server.installation_failed'   => 'installations.view',
    ];

    /**
     * @param array<string,mixed> $payload title, body, link …
     * @param list<int>|null      $userIds explicit recipients (else by permission)
     */
    public function notify(string $kind, array $payload, ?array $userIds = null, bool $email = true): int
    {
        $db = db_connect();
        if ($userIds === null) {
            $perm    = self::AUDIENCE[$kind] ?? 'release.view';
            $userIds = array_map('intval', array_column($db->table('users u')->distinct()->select('u.id')
                ->join('user_roles ur', 'ur.user_id = u.id')->join('role_permissions rp', 'rp.role_id = ur.role_id')
                ->join('permissions p', 'p.id = rp.permission_id')->where('p.slug', $perm)->where('u.status', 'ACTIVE')->where('u.kind', 'STAFF')
                ->get()->getResultArray(), 'id'));
        }
        $now = gmdate('Y-m-d H:i:s');
        foreach (array_unique($userIds) as $uid) {
            $db->table('notifications')->insert(['user_id' => $uid, 'kind' => $kind, 'payload' => json_encode($payload), 'created_at' => $now, 'updated_at' => $now]);
            if ($email) {
                $u = $db->table('users')->select('email')->where('id', $uid)->get()->getRowArray();
                if ($u) {
                    $this->email($u['email'], (string) ($payload['title'] ?? $kind), (string) ($payload['body'] ?? '') . (isset($payload['link']) ? "\n\n" . config('UpdateCenter')->publicBaseUrl . $payload['link'] : ''));
                }
            }
        }
        $chat = env('updatecenter.chatWebhookUrl');
        if ($chat) {
            Queue::push(SendWebhookJob::class, ['url' => $chat, 'event' => $kind, 'payload' => ['text' => ($payload['title'] ?? $kind) . ' — ' . ($payload['body'] ?? '')], 'secret_ref' => null], 'notifications');
        }

        return count($userIds);
    }

    public function email(string $to, string $subject, string $body): void
    {
        Queue::push(SendEmailJob::class, ['to' => $to, 'subject' => $subject, 'body' => $body], 'notifications');
    }
}

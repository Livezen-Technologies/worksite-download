<?php

namespace App\Jobs;

use App\Libraries\SecretStore;

/**
 * Signed outbound webhook (FR-UC-NTF-03). Header
 * X-WorkSuite-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, t.body)>.
 * Retries with exponential backoff via the queue; deliveries are recorded.
 */
class SendWebhookJob implements JobInterface
{
    public function handle(array $payload): void
    {
        $db         = db_connect();
        $deliveryId = $payload['delivery_id'] ?? null;
        $body       = json_encode(['event' => $payload['event'], 'id' => $payload['id'] ?? bin2hex(random_bytes(8)), 'created_at' => gmdate('c'), 'data' => $payload['payload']], JSON_UNESCAPED_SLASHES);
        $t          = time();
        $headers    = ['Content-Type: application/json', 'User-Agent: WorkSuite-UpdateCenter/1.0', 'X-WorkSuite-Event: ' . $payload['event']];
        $secret     = SecretStore::get($payload['secret_ref'] ?? null);
        if ($secret !== null) {
            $headers[] = 'X-WorkSuite-Signature: t=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);
        }

        $ch = curl_init($payload['url']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $ok  = $res !== false && $code >= 200 && $code < 300;
        $now = gmdate('Y-m-d H:i:s');

        if ($deliveryId) {
            $db->table('webhook_deliveries')->where('id', $deliveryId)->set('attempts', 'attempts + 1', false)->update([
                'status'       => $ok ? 'DELIVERED' : 'PENDING',
                'last_status'  => $code ?: null,
                'last_error'   => $ok ? null : ($err ?: "HTTP {$code}"),
                'delivered_at' => $ok ? $now : null,
                'updated_at'   => $now,
            ]);
        }
        if (! empty($payload['webhook_id'])) {
            $db->table('tenant_webhooks')->where('id', $payload['webhook_id'])->update($ok
                ? ['last_success_at' => $now, 'failure_count' => 0]
                : ['last_failure_at' => $now]);
            if (! $ok) {
                $db->table('tenant_webhooks')->where('id', $payload['webhook_id'])->set('failure_count', 'failure_count + 1', false)->update();
            }
        }
        if (! $ok) {
            throw new \RuntimeException("Webhook delivery failed: {$payload['url']} ({$code}) {$err}");
        }
    }
}

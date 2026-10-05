<?php
/**
 * MailboxHealthService
 * Monitors mailbox bounce rates, spam complaint thresholds, daily volume limits, and triggers automatic pauses.
 */

require_once __DIR__ . '/../db.php';

class MailboxHealthService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo) {
            $this->pdo = $pdo;
        } else {
            global $pdo;
            $this->pdo = $pdo;
        }
    }

    /**
     * Checks if a mailbox has exceeded daily sending cap
     */
    public function isDailyLimitReached(int $mailboxId): bool
    {
        $mbStmt = $this->pdo->prepare("SELECT daily_send_limit, is_active FROM mailboxes WHERE id = ?");
        $mbStmt->execute([$mailboxId]);
        $mb = $mbStmt->fetch(PDO::FETCH_ASSOC);

        if (!$mb || !$mb['is_active']) {
            return true;
        }

        $limit = (int)($mb['daily_send_limit'] ?? 40);

        $sentStmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM email_queue 
            WHERE mailbox_id = ? 
              AND status = 'sent' 
              AND DATE(sent_at) = CURDATE()
        ");
        $sentStmt->execute([$mailboxId]);
        $sentCount = (int)$sentStmt->fetchColumn();

        return $sentCount >= $limit;
    }

    /**
     * Gets today's sent email count for a mailbox
     */
    public function getDailySentCount(int $mailboxId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM email_queue 
            WHERE mailbox_id = ? 
              AND status = 'sent' 
              AND DATE(sent_at) = CURDATE()
        ");
        $stmt->execute([$mailboxId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Calculates rolling bounce rate over the last 100 sent emails
     * Max allowed: 2.0%
     */
    public function getRollingBounceRate(int $mailboxId): float
    {
        $stmt = $this->pdo->prepare("
            SELECT status, bounce_type 
            FROM email_queue 
            WHERE mailbox_id = ? 
              AND status IN ('sent', 'failed')
            ORDER BY id DESC 
            LIMIT 100
        ");
        $stmt->execute([$mailboxId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) === 0) {
            return 0.0;
        }

        $bounces = 0;
        foreach ($rows as $row) {
            if ($row['status'] === 'failed' || !empty($row['bounce_type'])) {
                $bounces++;
            }
        }

        return round(($bounces / count($rows)) * 100, 2);
    }

    /**
     * Evaluates health metrics and auto-pauses mailbox if thresholds are breached.
     * Thresholds: Bounce > 2.0%, Spam Complaint > 0.08%
     */
    public function checkAndEnforceHealth(int $mailboxId): array
    {
        $bounceRate = $this->getRollingBounceRate($mailboxId);

        // Check recent spam complaints
        $spamStmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM email_health_events 
            WHERE mailbox_id = ? 
              AND event_type = 'spam_complaint' 
              AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $spamStmt->execute([$mailboxId]);
        $spamComplaints = (int)$spamStmt->fetchColumn();

        $sentStmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM email_queue 
            WHERE mailbox_id = ? AND status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $sentStmt->execute([$mailboxId]);
        $sent7Days = max(1, (int)$sentStmt->fetchColumn());
        $spamRate = round(($spamComplaints / $sent7Days) * 100, 3);

        $autoPause = false;
        $reason = '';

        if ($bounceRate > 2.0) {
            $autoPause = true;
            $reason = "Auto-paused: Rolling bounce rate is {$bounceRate}% (exceeds 2.0% safety threshold).";
        } elseif ($spamRate > 0.08) {
            $autoPause = true;
            $reason = "Auto-paused: 7-day spam complaint rate is {$spamRate}% (exceeds 0.08% threshold).";
        }

        if ($autoPause) {
            $this->pauseMailbox($mailboxId, $reason);
        }

        // Calculate aggregate health score (100 - bounce% * 10 - spam% * 100)
        $score = max(0, min(100, 100 - ($bounceRate * 10) - ($spamRate * 100)));

        $upStmt = $this->pdo->prepare("
            UPDATE mailboxes 
            SET health_score = ?, last_health_check = NOW() 
            WHERE id = ?
        ");
        $upStmt->execute([$score, $mailboxId]);

        return [
            'mailbox_id' => $mailboxId,
            'bounce_rate' => $bounceRate,
            'spam_rate' => $spamRate,
            'health_score' => $score,
            'is_healthy' => !$autoPause,
            'pause_reason' => $reason
        ];
    }

    /**
     * Pauses a mailbox and logs a health event
     */
    public function pauseMailbox(int $mailboxId, string $reason): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE mailboxes 
            SET is_active = 0, pause_reason = ? 
            WHERE id = ?
        ");
        $stmt->execute([$reason, $mailboxId]);

        $this->logHealthEvent($mailboxId, 'auto_pause', ['reason' => $reason]);
        $this->sendWebhookAlert($mailboxId, "🚨 **Mailbox Auto-Paused!**\nReason: $reason");
    }

    /**
     * Logs an email health event (bounce, complaint, pause)
     */
    public function logHealthEvent(int $mailboxId, string $eventType, array $data = [], ?int $relatedEmailId = null): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO email_health_events (mailbox_id, event_type, event_data, related_email_id, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $mailboxId,
            $eventType,
            json_encode($data),
            $relatedEmailId
        ]);
    }

    /**
     * Dispatches emergency alert to Discord/Telegram webhook
     */
    private function sendWebhookAlert(int $mailboxId, string $message): void
    {
        if (!defined('ADMIN_WEBHOOK_URL') || empty(ADMIN_WEBHOOK_URL)) {
            return;
        }

        $ch = curl_init(ADMIN_WEBHOOK_URL);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['content' => $message]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        curl_close($ch);
    }
}

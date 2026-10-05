<?php
/**
 * WarmupService
 * Manages the 28-day email warmup ramp-up schedule to build mailbox & domain sending reputation safely.
 */

require_once __DIR__ . '/../db.php';

class WarmupService
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
     * Recommended daily limit based on warmup day
     * Schedule:
     * Days 1-7:   5 cold emails/day
     * Days 8-14:  12 cold emails/day
     * Days 15-21: 25 cold emails/day
     * Days 22-28: 35 cold emails/day
     * Days 29+:   40 cold emails/day (Ready)
     */
    public function getRecommendedLimitForDay(int $day): int
    {
        if ($day <= 0) return 5;
        if ($day <= 7) return 5;
        if ($day <= 14) return 12;
        if ($day <= 21) return 25;
        if ($day <= 28) return 35;
        return 40;
    }

    /**
     * Starts warmup for a mailbox
     */
    public function startWarmup(int $mailboxId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE mailboxes 
            SET warmup_status = 'warming', 
                warmup_started_at = NOW(), 
                warmup_day = 1, 
                daily_send_limit = 5 
            WHERE id = ?
        ");
        return $stmt->execute([$mailboxId]);
    }

    /**
     * Advances warmup day for all active warming mailboxes (called daily via cron)
     */
    public function advanceWarmupDays(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, email, warmup_day, daily_send_limit 
            FROM mailboxes 
            WHERE warmup_status = 'warming' AND is_active = 1
        ");
        $mailboxes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($mailboxes as $mb) {
            $newDay = (int)$mb['warmup_day'] + 1;
            $newLimit = $this->getRecommendedLimitForDay($newDay);
            $newStatus = ($newDay > 28) ? 'ready' : 'warming';

            $upStmt = $this->pdo->prepare("
                UPDATE mailboxes 
                SET warmup_day = ?, daily_send_limit = ?, warmup_status = ? 
                WHERE id = ?
            ");
            $upStmt->execute([$newDay, $newLimit, $newStatus, $mb['id']]);

            $results[] = [
                'mailbox_id' => $mb['id'],
                'email' => $mb['email'],
                'day' => $newDay,
                'new_limit' => $newLimit,
                'status' => $newStatus
            ];
        }

        return $results;
    }

    /**
     * Gets warmup status details for a mailbox
     */
    public function getWarmupStatus(int $mailboxId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, email, warmup_status, warmup_started_at, warmup_day, daily_send_limit 
            FROM mailboxes 
            WHERE id = ?
        ");
        $stmt->execute([$mailboxId]);
        $mb = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$mb) return null;

        $progressPercent = min(100, round(((int)$mb['warmup_day'] / 28) * 100));

        return [
            'mailbox_id' => $mb['id'],
            'email' => $mb['email'],
            'warmup_status' => $mb['warmup_status'],
            'warmup_day' => (int)$mb['warmup_day'],
            'daily_send_limit' => (int)$mb['daily_send_limit'],
            'progress_percent' => $progressPercent,
            'is_ready' => $mb['warmup_status'] === 'ready'
        ];
    }
}

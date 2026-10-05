<?php
/**
 * AI Mailbox Pro - Cron Sync Script
 * Periodically connects via IMAP to all active mailboxes and syncs inbound emails into the database.
 * Run this via CLI or CRON every 2 to 5 minutes:
 * Example: * /5 * * * * php /path/to/backend/cron/sync_mailbox.php
 */

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/classes/MailboxManager.php';
require_once dirname(__DIR__) . '/services/MailboxService.php';

try {
    echo "[" . date('Y-m-d H:i:s') . "] Starting Mailbox IMAP Sync...\n";
    $service = new MailboxService($pdo);
    $mailboxes = $service->getAllMailboxes();
    
    foreach ($mailboxes as $mailboxConfig) {
        $isActive = $mailboxConfig['is_active'] ?? 0;
        if (!$isActive) continue;
        
        $mbId = (int)$mailboxConfig['id'];
        $email = $mailboxConfig['email'];
        echo "Syncing mailbox: {$email} (ID: {$mbId})...\n";

        try {
            $rawPass = $service->getMailboxById($mbId)['password_encrypted'] ?? '';
            $password = $service->decryptPassword($rawPass);
            if (!$password) {
                echo "[WARN] Could not decrypt credentials for {$email}\n";
                continue;
            }

            $manager = new MailboxManager([
                'host' => $mailboxConfig['imap_host'] ?? $mailboxConfig['host'] ?? '',
                'port' => $mailboxConfig['imap_port'] ?? $mailboxConfig['port'] ?? 993,
                'encryption' => 'ssl',
                'username' => $email,
                'password' => $password
            ]);
            
            $manager->syncInbox($pdo, $mbId);
            $pdo->prepare("UPDATE mailboxes SET last_synced_at = NOW() WHERE id = ?")->execute([$mbId]);
            echo "[OK] Successfully synced: {$email}\n";
        } catch (\Throwable $e) {
            echo "[ERROR] Failed to sync {$email}: " . $e->getMessage() . "\n";
            error_log("IMAP Sync error for {$email}: " . $e->getMessage());
        }
    }
    echo "[" . date('Y-m-d H:i:s') . "] Finished Mailbox IMAP Sync.\n";
} catch (\Throwable $e) {
    echo "[FATAL] " . $e->getMessage() . "\n";
    exit(1);
}

<?php
/**
 * AI Mailbox Pro - Cron Outgoing Queue Processor
 * Dispatches queued outbound emails via SMTP using PHPMailer.
 * Respects daily limits and records deliverability health metrics.
 * Run this via CLI or CRON every 1 minute:
 * Example: * * * * * php /path/to/backend/cron/process_mail_queue.php
 */

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/libs/PHPMailer/src/Exception.php';
require_once dirname(__DIR__) . '/libs/PHPMailer/src/PHPMailer.php';
require_once dirname(__DIR__) . '/libs/PHPMailer/src/SMTP.php';
require_once dirname(__DIR__) . '/services/MailboxService.php';
require_once dirname(__DIR__) . '/services/MailboxHealthService.php';
require_once dirname(__DIR__) . '/classes/EmailVerifier.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

set_time_limit(0);

$batchSize = 25;
$mailboxService = new MailboxService($pdo);
$healthService = new MailboxHealthService($pdo);
$emailVerifier = new EmailVerifier($pdo);

try {
    $stmt = $pdo->prepare("
        SELECT eq.*
        FROM email_queue eq
        WHERE eq.status = 'pending' 
          AND (eq.scheduled_for IS NULL OR eq.scheduled_for <= NOW())
        ORDER BY eq.id ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($emails)) {
        echo "[" . date('Y-m-d H:i:s') . "] Queue is empty.\n";
        exit;
    }

    foreach ($emails as $emailJob) {
        $jobId = (int)$emailJob['id'];
        $toEmail = $emailJob['to_email'];
        $mailboxId = (int)($emailJob['mailbox_id'] ?? 0);

        echo "[" . date('Y-m-d H:i:s') . "] Sending Job #{$jobId} to {$toEmail}...\n";

        // Validate recipient address syntax & MX
        $verify = $emailVerifier->verify($toEmail);
        if (!$verify['is_valid']) {
            $pdo->prepare("UPDATE email_queue SET status = 'failed', error_message = ? WHERE id = ?")
                ->execute(['Verification failed: ' . $verify['reason'], $jobId]);
            echo "[SKIP] Invalid recipient address: {$verify['reason']}\n";
            continue;
        }

        // Get mailbox config
        $mbConfig = $mailboxId ? $mailboxService->getMailboxById($mailboxId) : null;
        if (!$mbConfig) {
            $all = $mailboxService->getAllMailboxes();
            $mbConfig = $all[0] ?? null;
        }

        if (!$mbConfig) {
            $pdo->prepare("UPDATE email_queue SET status = 'failed', error_message = 'No active mailbox configured' WHERE id = ?")->execute([$jobId]);
            echo "[ERR] No active mailbox found.\n";
            continue;
        }

        $mbId = (int)$mbConfig['id'];

        // Check daily sending quota
        if ($healthService->isDailyLimitReached($mbId)) {
            echo "[PAUSE] Mailbox #{$mbId} daily limit reached.\n";
            continue;
        }

        $rawPass = $mailboxService->getMailboxById($mbId)['password_encrypted'] ?? '';
        $smtpPass = $mailboxService->decryptPassword($rawPass);

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $mbConfig['smtp_host'] ?? $mbConfig['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $mbConfig['email'];
            $mail->Password   = $smtpPass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = (int)($mbConfig['smtp_port'] ?? 465);
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom($mbConfig['email'], $mbConfig['name'] ?: 'AI Mailbox Pro');
            $mail->addAddress($toEmail);

            $mail->isHTML(true);
            $mail->Subject = $emailJob['subject'];
            $mail->Body    = nl2br($emailJob['body']);
            $mail->AltBody = strip_tags($emailJob['body']);

            $mail->send();

            // Mark job sent
            $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW() WHERE id = ?")->execute([$jobId]);

            // Save outbound message to email_messages table
            $pdo->prepare("
                INSERT INTO email_messages 
                (mailbox_id, thread_id, subject, from_email, to_email, body_text, body_html, direction, is_read, received_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'outbound', 1, NOW())
            ")->execute([
                $mbId,
                $emailJob['thread_id'] ?: ('manual_' . $jobId),
                $emailJob['subject'],
                $mbConfig['email'],
                $toEmail,
                $emailJob['body'],
                nl2br($emailJob['body'])
            ]);

            echo "[SUCCESS] Email sent successfully to {$toEmail}!\n";
        } catch (\Throwable $e) {
            $pdo->prepare("UPDATE email_queue SET status = 'failed', error_message = ? WHERE id = ?")
                ->execute([$e->getMessage(), $jobId]);
            echo "[ERROR] PHPMailer Exception: " . $e->getMessage() . "\n";
        }
    }
} catch (\Throwable $e) {
    echo "[FATAL] Queue process failed: " . $e->getMessage() . "\n";
}

<?php
/**
 * AI Mailbox Pro - REST API Endpoint
 * Handles mailbox accounts, threads, messages, draft approvals, and deliverability stats.
 */

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/classes/MailboxManager.php';
require_once dirname(__DIR__) . '/services/MailboxService.php';
require_once dirname(__DIR__) . '/services/MailboxHealthService.php';
require_once dirname(__DIR__) . '/services/WarmupService.php';

// CSRF Protection for state-changing requests (if active session exists)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['csrf_token'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrf) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or missing CSRF token.']);
        exit;
    }
}

try {
    $action = $_GET['action'] ?? '';
    
    // Parse JSON or form payload
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?? [];
    if (empty($input) && !empty($_POST)) {
        $input = $_POST;
    }

    $service = new MailboxService($pdo);
    $healthService = new MailboxHealthService($pdo);

    switch ($action) {
        case 'list':
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
            $mailboxId = isset($_GET['mailbox_id']) ? (int)$_GET['mailbox_id'] : ($input['mailbox_id'] ?? null);

            if (!$mailboxId) {
                // If no mailbox specified, get default or first active
                $stmtFirst = $pdo->query("SELECT id FROM mailboxes WHERE is_active = 1 ORDER BY is_default DESC, id ASC LIMIT 1");
                $mailboxId = $stmtFirst->fetchColumn() ?: null;
            }

            if ($mailboxId) {
                // Trigger IMAP synchronization
                try {
                    $mbConfig = $service->getMailboxById((int)$mailboxId);
                    if ($mbConfig && !empty($mbConfig['is_active'])) {
                        $password = $service->decryptPassword($mbConfig['password_encrypted'] ?? '');
                        if ($password) {
                            $manager = new MailboxManager([
                                'host' => $mbConfig['imap_host'] ?? $mbConfig['host'],
                                'port' => $mbConfig['imap_port'] ?? $mbConfig['port'] ?? 993,
                                'encryption' => $mbConfig['imap_encryption'] ?? 'ssl',
                                'username' => $mbConfig['email'],
                                'password' => $password
                            ]);
                            $manager->syncInbox($pdo, (int)$mailboxId);
                        }
                    }
                } catch (\Throwable $e) {
                    error_log("IMAP Sync warning: " . $e->getMessage());
                }

                // Fetch our mailbox address for threading resolution
                $stmtMb = $pdo->prepare("SELECT email FROM mailboxes WHERE id = ?");
                $stmtMb->execute([$mailboxId]);
                $ourEmail = $stmtMb->fetchColumn() ?: '';

                // Group messages by thread_id to return conversation heads
                $stmt = $pdo->prepare("
                    SELECT id, message_uid as message_id, thread_id, subject, from_email as `from`, to_email as `to`, received_at as date, 
                           is_read, 0 as is_draft,
                           (SELECT COUNT(*) FROM email_messages t2 WHERE t2.thread_id = t1.thread_id) as thread_count,
                           IF(t1.from_email = ?, t1.to_email, t1.from_email) as contact_email
                    FROM email_messages t1
                    WHERE mailbox_id = ? 
                    AND id IN (
                       SELECT MAX(id) FROM email_messages WHERE mailbox_id = ? GROUP BY thread_id
                    )
                    ORDER BY received_at DESC LIMIT ?
                ");
                $stmt->execute([$ourEmail, $mailboxId, $mailboxId, $limit]);
                $threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'data' => $threads]);
            } else {
                echo json_encode(['success' => true, 'data' => []]);
            }
            break;

        case 'read':
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
            if (!$id) {
                throw new InvalidArgumentException("Message ID is required.");
            }

            $stmt = $pdo->prepare("SELECT thread_id FROM email_messages WHERE id = ?");
            $stmt->execute([$id]);
            $threadId = $stmt->fetchColumn();

            if ($threadId) {
                $stmt = $pdo->prepare("
                    SELECT id, subject, from_email as `from`, to_email as `to`, body_text, body_html, received_at as date, is_read, direction 
                    FROM email_messages 
                    WHERE thread_id = ? 
                    ORDER BY received_at ASC
                ");
                $stmt->execute([$threadId]);
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Mark entire thread as read
                $pdo->prepare("UPDATE email_messages SET is_read = 1 WHERE thread_id = ?")->execute([$threadId]);

                echo json_encode(['success' => true, 'data' => ['id' => $id, 'messages' => $messages]]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Thread not found']);
            }
            break;

        case 'compose':
            $to = trim($input['to'] ?? '');
            $subject = trim($input['subject'] ?? '');
            $body = trim($input['body'] ?? '');
            $mailboxId = $input['mailbox_id'] ?? null;

            if (empty($to) || empty($subject) || empty($body)) {
                throw new InvalidArgumentException("Recipient, subject, and body are required.");
            }

            $stmt = $pdo->prepare("
                INSERT INTO email_queue (mailbox_id, to_email, subject, body, status, source) 
                VALUES (?, ?, ?, ?, 'pending', 'manual')
            ");
            $stmt->execute([$mailboxId, $to, $subject, $body]);

            echo json_encode(['success' => true, 'message' => 'Email queued for sending.']);
            break;

        case 'drafts':
            $stmt = $pdo->query("
                SELECT id, to_email as `to`, subject, body, created_at as date, ai_reasoning, status
                FROM email_queue 
                WHERE status = 'draft' 
                ORDER BY created_at DESC
            ");
            $drafts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $drafts]);
            break;

        case 'approve':
            $id = $input['id'] ?? null;
            $bodyText = $input['bodyText'] ?? null;
            if (!$id) {
                throw new InvalidArgumentException("Draft ID is required.");
            }

            if ($bodyText !== null) {
                $stmt = $pdo->prepare("UPDATE email_queue SET status = 'pending', body = ? WHERE id = ? AND status = 'draft'");
                $stmt->execute([$bodyText, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE email_queue SET status = 'pending' WHERE id = ? AND status = 'draft'");
                $stmt->execute([$id]);
            }

            echo json_encode(['success' => true, 'message' => 'Draft approved and queued.']);
            break;

        case 'accounts':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $res = $service->createMailbox([
                    'name' => $input['name'] ?? '',
                    'email' => $input['email'] ?? '',
                    'password' => $input['password'] ?? '',
                    'host' => $input['host'] ?? '',
                    'port' => (int)($input['port'] ?? 993),
                    'smtp_host' => $input['smtp_host'] ?? ($input['host'] ?? ''),
                    'smtp_port' => (int)($input['smtp_port'] ?? 465)
                ]);
                echo json_encode($res);
            } else {
                $accounts = $service->getAllMailboxes(true);
                foreach ($accounts as &$account) {
                    $mbId = (int)$account['id'];
                    $account['bounce_rate'] = $healthService->getRollingBounceRate($mbId);
                    $account['daily_sent'] = $healthService->getDailySentCount($mbId);
                }
                echo json_encode(['success' => true, 'data' => $accounts]);
            }
            break;

        case 'delete_account':
            $id = $input['id'] ?? null;
            if (!$id) {
                throw new InvalidArgumentException("Account ID required.");
            }
            $res = $service->deleteMailbox((int)$id);
            echo json_encode($res);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid or unrecognized API action.']);
            break;
    }
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

<?php
/**
 * AI Mailbox Pro - Mailbox Service
 * Handles mailbox CRUD, encryption, and connectivity tests.
 */

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/config.php';

class MailboxService {
    private PDO $conn;
    private bool $imapAvailable;
    
    public function __construct(?PDO $conn = null) {
        if ($conn) {
            $this->conn = $conn;
        } else {
            global $pdo;
            $this->conn = $pdo;
        }
        $this->imapAvailable = function_exists('imap_open');
    }
    
    /**
     * Encrypt password using AES-256-CBC
     */
    public function encryptPassword(string $password): string {
        $rawKey = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 'fallback_key_please_define_env';
        $encKey = hash('sha256', $rawKey, true);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($password, 'AES-256-CBC', $encKey, OPENSSL_RAW_DATA, $iv);
        return base64_encode('CBC:' . $iv . $encrypted);
    }
    
    /**
     * Decrypt password
     */
    public function decryptPassword(string $encrypted): string|false {
        $rawKey = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 'fallback_key_please_define_env';
        $decoded = base64_decode($encrypted);

        if (strpos($decoded, 'CBC:') === 0) {
            $data = substr($decoded, 4);
            $iv = substr($data, 0, 16);
            $ciphertext = substr($data, 16);
            $encKey = hash('sha256', $rawKey, true);
            return openssl_decrypt($ciphertext, 'AES-256-CBC', $encKey, OPENSSL_RAW_DATA, $iv);
        }

        // Fallback decryption
        return openssl_decrypt($decoded, 'AES-128-ECB', $rawKey);
    }
    
    public function isImapAvailable(): bool {
        return $this->imapAvailable;
    }
    
    /**
     * Get all connected mailboxes
     */
    public function getAllMailboxes(bool $checkConnection = false): array {
        try {
            $stmt = $this->conn->query("
                SELECT id, name, email, 
                       COALESCE(imap_host, '') as host,
                       COALESCE(imap_port, 993) as port,
                       COALESCE(smtp_host, '') as smtp_host,
                       COALESCE(smtp_port, 465) as smtp_port,
                       is_active, daily_send_limit, warmup_status, warmup_day, 
                       health_score, pause_reason, created_at
                FROM mailboxes 
                ORDER BY created_at DESC
            ");
            $mailboxes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($checkConnection && $this->imapAvailable) {
                foreach ($mailboxes as &$mailbox) {
                    $mailbox['connected'] = $this->testConnection((int)$mailbox['id']);
                }
            } else {
                foreach ($mailboxes as &$mailbox) {
                    $mailbox['connected'] = false;
                }
            }
            
            return $mailboxes;
        } catch (Exception $e) {
            error_log("MailboxService::getAllMailboxes error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get mailbox by ID
     */
    public function getMailboxById(int $id): ?array {
        try {
            $stmt = $this->conn->prepare("SELECT * FROM mailboxes WHERE id = ?");
            $stmt->execute([$id]);
            $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$mailbox) {
                return null;
            }
            
            return $mailbox;
        } catch (Exception $e) {
            error_log("MailboxService::getMailboxById error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Test connection to IMAP
     */
    public function testConnection(int $id): bool {
        if (!$this->imapAvailable) {
            return false;
        }
        
        try {
            $mailbox = $this->getMailboxById($id);
            if (!$mailbox || empty($mailbox['is_active'])) {
                return false;
            }
            
            if (empty($mailbox['password_encrypted'])) {
                return false;
            }
            
            $password = $this->decryptPassword($mailbox['password_encrypted']);
            if ($password === false) {
                return false;
            }
            
            $host = $mailbox['imap_host'] ?? $mailbox['host'] ?? '';
            $port = $mailbox['imap_port'] ?? $mailbox['port'] ?? 993;
            $connectionString = "{" . $host . ":" . $port . "/imap/ssl/novalidate-cert}INBOX";
            $connection = @imap_open($connectionString, $mailbox['email'], $password);
            
            if ($connection) {
                imap_close($connection);
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("MailboxService::testConnection error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create new mailbox account
     */
    public function createMailbox(array $data): array {
        try {
            if (empty($data['name']) || empty($data['email']) || empty($data['password'])) {
                return [
                    'success' => false,
                    'error' => 'Name, email, and password are required'
                ];
            }
            
            $encryptedPassword = $this->encryptPassword($data['password']);
            
            $stmt = $this->conn->prepare("
                INSERT INTO mailboxes 
                (name, email, imap_host, imap_port, smtp_host, smtp_port, username, password_encrypted, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            
            $stmt->execute([
                $data['name'],
                $data['email'],
                $data['host'] ?? $data['imap_host'] ?? '',
                $data['port'] ?? $data['imap_port'] ?? 993,
                $data['smtp_host'] ?? ($data['host'] ?? ''),
                $data['smtp_port'] ?? 465,
                $data['email'],
                $encryptedPassword
            ]);
            
            return [
                'success' => true,
                'id' => (int)$this->conn->lastInsertId(),
                'message' => 'Mailbox created successfully'
            ];
        } catch (Exception $e) {
            error_log("MailboxService::createMailbox error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to create mailbox: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete mailbox account
     */
    public function deleteMailbox(int $id): array {
        try {
            $stmt = $this->conn->prepare("DELETE FROM mailboxes WHERE id = ?");
            $stmt->execute([$id]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

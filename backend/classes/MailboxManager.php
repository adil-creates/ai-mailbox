<?php

/**
 * Mailbox Manager Class
 * Handles email retrieval, MIME parsing, and management via PHP IMAP extension.
 */

class MailboxManager
{
    private string $server;
    private string $username;
    private string $password;
    private $connection = null;

    public function __construct(array $config)
    {
        $host = $config['host'] ?? '';
        $port = (int)($config['port'] ?? 993);
        $encryption = $config['encryption'] ?? 'ssl';
        $this->username = $config['username'] ?? '';
        $this->password = $config['password'] ?? '';

        if (empty($host) || empty($this->username)) {
            throw new InvalidArgumentException("IMAP host and username are required.");
        }

        $this->server = "{" . $host . ":" . $port . "/imap/" . $encryption . "/novalidate-cert}INBOX";
    }

    private function connect()
    {
        if (!function_exists('imap_open')) {
            throw new RuntimeException("PHP IMAP extension is not enabled. Please enable php_imap in your php.ini.");
        }

        if (!$this->connection) {
            $this->connection = @imap_open($this->server, $this->username, $this->password);
            if (!$this->connection) {
                throw new RuntimeException("IMAP connection failed: " . imap_last_error());
            }
        }
        return $this->connection;
    }

    /**
     * Sync Inbox messages to database
     */
    public function syncInbox(\PDO $db, int $mailboxId)
    {
        $conn = $this->connect();
        
        $msgCount = imap_num_msg($conn);
        if ($msgCount === 0) {
            return;
        }

        $limit = 30; // Fetch latest 30 messages per batch
        $start = max(1, $msgCount - $limit + 1);
        
        $insertStmt = $db->prepare("
            INSERT INTO email_messages 
            (mailbox_id, message_uid, thread_id, in_reply_to, references_header, subject, from_email, to_email, received_at, is_read, body_text, body_html) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $checkStmt = $db->prepare("SELECT id FROM email_messages WHERE message_uid = ? AND mailbox_id = ? LIMIT 1");

        for ($i = $msgCount; $i >= $start; $i--) {
            $header = imap_headerinfo($conn, $i);
            $rawHeader = imap_fetchheader($conn, $i);
            
            $messageUid = $this->parseHeaderValue('Message-ID', $rawHeader) ?? (string)imap_uid($conn, $i);
            
            // Skip already ingested messages
            $checkStmt->execute([$messageUid, $mailboxId]);
            if ($checkStmt->fetchColumn()) {
                continue; 
            }

            $subject = $this->decodeSubject($header->subject ?? '(No Subject)');
            $cleanSubject = trim(preg_replace('/^(Re|Fwd|Fw):\s*/i', '', $subject));
            
            $inReplyTo = $this->parseHeaderValue('In-Reply-To', $rawHeader);
            $references = $this->parseHeaderValue('References', $rawHeader);
            
            $from = $header->fromaddress ?? '';
            $to = $header->toaddress ?? '';
            $dateReceived = date('Y-m-d H:i:s', $header->udate ?? time());
            $isRead = ($header->Unseen == 'U' || $header->Recent == 'N') ? 0 : 1;
            
            $threadId = null;
            if ($inReplyTo) {
                $threadId = $inReplyTo;
            } else {
                $subStmt = $db->prepare("SELECT thread_id FROM email_messages WHERE mailbox_id = ? AND subject LIKE ? AND from_email = ? LIMIT 1");
                $subStmt->execute([$mailboxId, "%" . $cleanSubject . "%", $from]);
                $existingThread = $subStmt->fetchColumn();
                $threadId = $existingThread ?: $messageUid;
            }
            
            $bodyData = $this->getBody($i);
            
            $insertStmt->execute([
                $mailboxId,
                $messageUid,
                $threadId,
                $inReplyTo,
                $references,
                $subject,
                $from,
                $to,
                $dateReceived,
                $isRead,
                !$bodyData['is_html'] ? $bodyData['content'] : strip_tags($bodyData['content']),
                $bodyData['is_html'] ? $bodyData['content'] : null
            ]);
        }
    }
    
    private function parseHeaderValue(string $headerName, string $rawHeaders): ?string
    {
        if (preg_match('/^' . preg_quote($headerName, '/') . ':\s*(.*)$/mi', $rawHeaders, $matches)) {
            return trim($matches[1], " \t\n\r\0\x0B<>"); 
        }
        return null;
    }

    public function getInbox(int $limit = 20): array
    {
        $conn = $this->connect();
        $emails = [];

        $msgCount = imap_num_msg($conn);
        $start = max(1, $msgCount - $limit + 1);

        for ($i = $msgCount; $i >= $start; $i--) {
            $header = imap_headerinfo($conn, $i);
            $structure = imap_fetchstructure($conn, $i);

            $emails[] = [
                'id' => $i,
                'uid' => imap_uid($conn, $i),
                'subject' => $this->decodeSubject($header->subject ?? '(No Subject)'),
                'from' => $header->fromaddress ?? '',
                'date' => $header->date ?? '',
                'timestamp' => $header->udate ?? 0,
                'is_read' => !($header->Unseen == 'U' || $header->Recent == 'N'),
                'has_attachments' => isset($structure->parts) && count($structure->parts) > 1
            ];
        }

        return $emails;
    }

    public function getMessage(int $id): array
    {
        $conn = $this->connect();
        $header = imap_headerinfo($conn, $id);
        $body = $this->getBody($id);

        return [
            'id' => $id,
            'subject' => $this->decodeSubject($header->subject ?? '(No Subject)'),
            'from' => $header->fromaddress ?? '',
            'to' => $header->toaddress ?? '',
            'date' => $header->date ?? '',
            'body' => $body['content'],
            'is_html' => $body['is_html']
        ];
    }

    public function deleteMessage(int $id): bool
    {
        $conn = $this->connect();
        imap_delete($conn, $id);
        return imap_expunge($conn);
    }

    public function markAsRead(int $id): bool
    {
        $conn = $this->connect();
        return (bool)imap_setflag_full($conn, (string)$id, "\\Seen");
    }

    private function decodeSubject(string $subject): string
    {
        $elements = imap_mime_header_decode($subject);
        $decoded = "";
        foreach ($elements as $element) {
            $decoded .= $element->text;
        }
        return $decoded;
    }

    private function getBody(int $msgNumber): array
    {
        $conn = $this->connect();
        $structure = imap_fetchstructure($conn, $msgNumber);

        if (!isset($structure->parts)) {
            $body = imap_fetchbody($conn, $msgNumber, 1);
            return ['content' => $this->decodeBody($body, $structure->encoding ?? 0), 'is_html' => false];
        }

        $data = $this->findPart($conn, $msgNumber, $structure->parts);
        return ['content' => $data['content'], 'is_html' => $data['is_html']];
    }

    private function findPart($conn, int $msgNumber, array $parts, string $prefix = ""): array
    {
        $htmlPart = null;
        $textPart = null;

        foreach ($parts as $index => $part) {
            $partNumber = $prefix . ($index + 1);

            if ($part->type == 0) { // Text
                if (strtolower($part->subtype ?? '') === 'html') {
                    $htmlPart = ['num' => $partNumber, 'encoding' => $part->encoding];
                } else {
                    $textPart = ['num' => $partNumber, 'encoding' => $part->encoding];
                }
            } elseif ($part->type == 1 && isset($part->parts)) { // Multipart
                $res = $this->findPart($conn, $msgNumber, $part->parts, $partNumber . ".");
                if ($res['is_html']) return $res;
                if (!empty($res['content'])) $textPart = $res;
            }
        }

        if ($htmlPart) {
            return [
                'content' => $this->decodeBody(imap_fetchbody($conn, $msgNumber, $htmlPart['num']), $htmlPart['encoding']),
                'is_html' => true
            ];
        }

        if ($textPart) {
            return [
                'content' => is_array($textPart) && isset($textPart['content']) 
                    ? $textPart['content'] 
                    : $this->decodeBody(imap_fetchbody($conn, $msgNumber, $textPart['num']), $textPart['encoding']),
                'is_html' => false
            ];
        }

        return ['content' => '', 'is_html' => false];
    }

    private function decodeBody(string $content, int $encoding): string
    {
        if ($encoding === 3) return imap_base64($content);
        if ($encoding === 4) return imap_qprint($content);
        return $content;
    }

    public function __destruct()
    {
        if ($this->connection) {
            @imap_close($this->connection);
        }
    }
}

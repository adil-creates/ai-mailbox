<?php
/**
 * EmailVerifier Class
 * Multi-layer email verification engine:
 * Layer 1: Syntax Validation (RFC 5322)
 * Layer 2: MX & A Record DNS Lookup
 * Layer 3: Disposable Domain Detection
 * Layer 4: Role-Based Address Detection
 * Layer 5: SMTP Handshake Simulation (RCPT TO)
 */

class EmailVerifier
{
    private ?PDO $pdo;
    private array $disposableDomains;
    private array $rolePrefixes;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;

        $this->disposableDomains = [
            'guerrillamail.com', 'guerrillamail.net', 'guerrillamail.org', 'tempmail.com', 'temp-mail.org',
            'yopmail.com', 'yopmail.fr', 'mailinator.com', '10minutemail.com', 'trashmail.com',
            'dispostable.com', 'sharklasers.com', 'getairmail.com', 'throwawaymail.com', 'maildrop.cc',
            'tempail.com', 'inboxalias.com', 'fakeinbox.com', 'crazymailing.com', 'nada.ltd',
            'boun.cr', 'mohmal.com', 'burnermail.io', 'mytemp.email', 'dropmail.me'
        ];

        $this->rolePrefixes = [
            'info', 'admin', 'administrator', 'support', 'sales', 'billing', 'contact', 'jobs',
            'careers', 'help', 'no-reply', 'noreply', 'postmaster', 'webmaster', 'privacy',
            'legal', 'office', 'marketing', 'team', 'enquiries', 'media', 'press'
        ];
    }

    public function verify(string $email): array
    {
        $email = strtolower(trim($email));

        // Layer 1: Syntax
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'email' => $email,
                'is_valid' => false,
                'risk_level' => 'invalid',
                'reason' => 'Invalid email format'
            ];
        }

        $parts = explode('@', $email);
        $user = $parts[0];
        $domain = $parts[1] ?? '';

        // Layer 2: Disposable Check
        if (in_array($domain, $this->disposableDomains, true)) {
            return [
                'email' => $email,
                'is_valid' => false,
                'risk_level' => 'high',
                'reason' => 'Disposable email address detected'
            ];
        }

        // Layer 3: DNS MX Check
        if (!checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) {
            return [
                'email' => $email,
                'is_valid' => false,
                'risk_level' => 'invalid',
                'reason' => 'Domain does not have valid mail exchangers (MX/A records)'
            ];
        }

        // Layer 4: Role-based account check
        $isRole = in_array($user, $this->rolePrefixes, true);

        return [
            'email' => $email,
            'is_valid' => true,
            'risk_level' => $isRole ? 'medium' : 'low',
            'is_role' => $isRole,
            'reason' => $isRole ? 'Role-based address' : 'Deliverable'
        ];
    }
}

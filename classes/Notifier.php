<?php
declare(strict_types=1);

namespace RaPostcode;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/src/SMTP.php';

/** Sends the importer's status emails (PHPMailer, vendored; SMTP or PHP mail()). */
final class Notifier
{
    public function __construct(private array $config, private Logger $log)
    {
    }

    public function send(string $subject, string $body, bool $debug = false): bool
    {
        foreach (['notify_to', 'mail_from'] as $key) {
            if (empty($this->config[$key])) {
                $this->log->error("Email not sent: '$key' is not set in config.php");
                return false;
            }
        }
        try {
            $m = new PHPMailer(true);
            if ($debug) {
                $m->SMTPDebug = SMTP::DEBUG_SERVER;   // show our commands and the server's replies
                $m->Debugoutput = 'echo';
            }
            $m->CharSet = 'UTF-8';
            if (!empty($this->config['smtp'])) {
                $s = $this->config['smtp'];
                $m->isSMTP();
                $m->Host = $s['host'];
                $m->Port = (int) ($s['port'] ?? 587);
                $m->SMTPSecure = $s['secure'] ?? PHPMailer::ENCRYPTION_STARTTLS;
                if (!empty($s['user'])) {
                    $m->SMTPAuth = true;
                    $m->Username = $s['user'];
                    $m->Password = $s['pass'] ?? '';
                }
            }
            $m->setFrom($this->config['mail_from'], $this->config['mail_from_name']);
            $m->addAddress($this->config['notify_to']);
            $m->Subject = '[Postcodes] ' . $subject;
            $m->Body = $body;
            $m->isHTML(false);
            $m->send();
            $via = !empty($this->config['smtp']) ? 'SMTP server ' . $this->config['smtp']['host'] : 'PHP mail()';
            $this->log->info("Email accepted by $via: to {$this->config['notify_to']}, from {$this->config['mail_from']}");
            return true;
        } catch (MailException $e) {
            $this->log->error('Could not send notification email: ' . $e->getMessage());
            return false;
        }
    }
}

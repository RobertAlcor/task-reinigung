<?php
/**
 * Mailversand ueber das SMTP-Konto des Anbieters.
 *
 * Betriebe haben kein eigenes Postfach im System. Absender ist daher
 * immer die Adresse aus der Konfiguration; der Name des Betriebs steht
 * im Absendernamen, seine E-Mail als Antwortadresse. So bleibt SPF/DKIM
 * der Anbieter-Domain intakt.
 */

declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
use RuntimeException;

final class Mailer
{
    private static array $config = [];

    public static function init(array $mailConfig): void
    {
        self::$config = $mailConfig;
        // DB-Einstellungen ueberschreiben config.php (wenn im Admin gepflegt)
        try {
            $db = Database::get();
            $rows = $db->rawAll("SELECT schluessel, wert FROM anbieter_einstellungen WHERE schluessel LIKE 'mail_%'");
            $map = []; foreach ($rows as $r) { $map[$r['schluessel']] = $r['wert']; }
            if (($map['mail_host'] ?? '') !== '') {
                self::$config = array_merge(self::$config, [
                    'host'       => $map['mail_host'],
                    'port'       => (int) ($map['mail_port'] ?? 587),
                    'secure'     => $map['mail_secure'] ?? 'tls',
                    'user'       => $map['mail_user'] ?? '',
                    'password'   => $map['mail_password'] ?? '',
                    'from_email' => $map['mail_from_email'] ?? self::$config['from_email'] ?? '',
                    'from_name'  => $map['mail_from_name'] ?? self::$config['from_name'] ?? 'Takt',
                ]);
            }
        } catch (\Throwable) {}
    }

    public static function fromEmail(): string { return self::$config['from_email'] ?? ''; }

    public static function configured(): bool
    {
        return (self::$config['user'] ?? '') !== '' && (self::$config['from_email'] ?? '') !== '';
    }

    /**
     * Sendet eine Textmail. $anhaenge: [pfad => dateiname].
     * Wirft RuntimeException bei Fehler.
     */
    public static function send(string $an, string $anName, string $betreff, string $text, ?string $vonName = null, ?string $replyTo = null, array $anhaenge = []): void
    {
        if (!self::configured()) {
            throw new RuntimeException('Mailversand nicht konfiguriert. Anbieter → Einstellungen → Mailversand ausfüllen.');
        }
        if (filter_var($an, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Ungültige Empfängeradresse.');
        }

        require_once __DIR__ . '/lib/phpmailer/Exception.php';
        require_once __DIR__ . '/lib/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/lib/phpmailer/SMTP.php';

        $m = new PHPMailer(true);
        try {
            $m->isSMTP();
            $m->Host       = self::$config['host'];
            $m->Port       = (int) self::$config['port'];
            $m->SMTPAuth   = true;
            $m->SMTPSecure = (self::$config['secure'] ?? 'tls') === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $m->Username   = self::$config['user'];
            $m->Password   = self::$config['password'];
            $m->CharSet    = 'UTF-8';
            $m->Timeout    = 10;
            $m->setFrom(self::$config['from_email'], $vonName ?: (self::$config['from_name'] ?? ''));
            if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false) {
                $m->addReplyTo($replyTo, $vonName ?: '');
            }
            $m->addAddress($an, $anName);
            $m->Subject = $betreff;
            $m->Body    = $text;
            foreach ($anhaenge as $pfad => $name) {
                $m->addAttachment($pfad, $name);
            }
            $m->send();
        } catch (MailException $e) {
            throw new RuntimeException('Versand fehlgeschlagen: ' . $m->ErrorInfo);
        }
    }

    /**
     * Arbeitet die Warteschlange ab — ueber alle Betriebe.
     * @return array{gesendet:int, fehler:int}
     */
    public static function processQueue(int $max = 50): array
    {
        $db = Database::get();
        $out = ['gesendet' => 0, 'fehler' => 0]; $folge = 0;
        if (!self::configured()) {
            return $out;
        }
        $rows = $db->rawAll(
            "SELECT n.*, b.name AS betrieb_name, b.email AS betrieb_email
               FROM benachrichtigungen n JOIN betriebe b ON b.id = n.betrieb_id
              WHERE n.status = 'wartend' AND n.kanal = 'mail' AND b.status <> 'gekuendigt'
              ORDER BY n.id LIMIT ?", [$max]
        );
        foreach ($rows as $n) {
            if ($folge >= 3) { break; }                       // Server antwortet nicht — Rest beim naechsten Lauf, Eintraege bleiben 'wartend'
            try {
                $text = $n['inhalt'] . "\n\n--\n" . $n['betrieb_name'];
                self::send((string) $n['empfaenger_email'], '', (string) $n['betreff'], $text, (string) $n['betrieb_name'], $n['betrieb_email'] ?: null);
                $db->raw("UPDATE benachrichtigungen SET status = 'gesendet', gesendet_am = NOW() WHERE id = ?", [(int) $n['id']]);
                $out['gesendet']++; $folge = 0;
            } catch (\Throwable $e) {
                $folge++;
                $verbindung = stripos($e->getMessage(), 'connect') !== false || stripos($e->getMessage(), 'SMTP') !== false;
                // Verbindungsfehler: Eintrag bleibt wartend (naechster Lauf); Inhaltsfehler: als Fehler markieren
                if (!$verbindung) { $db->raw("UPDATE benachrichtigungen SET status = 'fehler', fehler = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 255), (int) $n['id']]); }
                else { $db->raw("UPDATE benachrichtigungen SET fehler = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 255), (int) $n['id']]); }
                $out['fehler']++;
            }
        }
        return $out;
    }
}

<?php
/**
 * Zentraler Einstiegspunkt der API.
 *
 * Jede Anfrage laeuft hier durch. Ohne Eintrag in der Routenliste
 * gibt es keinen Zugang, und ausser den ausdruecklich offenen Routen
 * verlangt jede eine gueltige Anmeldung samt Rollenpruefung.
 */

declare(strict_types=1);

use App\Auth;
use App\Crypto;
use App\Database;
use App\Request;
use App\Response;
use App\Router;

$config = require __DIR__ . '/../system/bootstrap.php';

Response::securityHeaders();
Response::cors($config['security']['allowed_origins']);

// Vorabanfrage des Browsers
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Pfad nach /api/ ermitteln
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = preg_replace('#^.*/api/#', '', $uri) ?? '';
$path = trim((string) $path, '/');

$router = new Router();

// =====================================================================
// Offene Routen — ohne Anmeldung erreichbar
// =====================================================================

/** Anmeldung Büro und Kundenportal. */
$router->open('POST', 'login', static function (Request $r): array {
    $user = Auth::loginWithPassword(
        (string) $r->text('benutzername', 80, true),
        (string) ($r->all()['passwort'] ?? '')
    );
    return [
        'benutzer' => [
            'id'    => (int) $user['id'],
            'name'  => $user['benutzername'],
            'rolle' => $user['rolle'],
        ],
        'csrf' => Auth::csrfToken(),
    ];
});

/** Anmeldung Mitarbeiter-App: Personalnummer und PIN, liefert Token. */
$router->open('POST', 'app/login', static function (Request $r): array {
    return Auth::loginWithPin(
        (string) $r->text('personalnummer', 20, true),
        (string) ($r->all()['pin'] ?? ''),
        (string) ($r->all()['geraet'] ?? '')
    );
});

/** Erreichbarkeitsprüfung, z. B. für die Offline-Erkennung der PWA. */
$router->open('GET', 'status', static fn(): array => ['zeit' => date('c')]);

// =====================================================================
// Angemeldet — alle Rollen
// =====================================================================

$router->post('logout', static function (): array {
    Auth::logout();
    return ['abgemeldet' => true];
});

$router->get('ich', static function (): array {
    $u = Auth::user();
    return [
        'id'         => (int) $u['id'],
        'rolle'      => $u['rolle'],
        'betrieb_id' => (int) $u['betrieb_id'],
    ];
});

// =====================================================================
// Büro: Stammdaten
// =====================================================================

$buero = ['inhaber', 'office'];

$router->get('kunden', static function (Request $r): array {
    $db = Database::get();
    $suche = $r->query('suche');
    if ($suche !== null && $suche !== '') {
        return $db->select('kunden', 'firmenname LIKE ?', ['%' . $suche . '%'], 'ORDER BY firmenname LIMIT 100');
    }
    return $db->select('kunden', '', [], 'ORDER BY firmenname LIMIT 200');
}, $buero);

$router->get('kunden/{id}', static function (Request $r): array {
    $kunde = Database::get()->find('kunden', $r->paramInt('id'));
    if ($kunde === null) {
        Response::error('Kunde nicht gefunden.', 404);
    }
    $kunde['objekte'] = Database::get()->select('objekte', 'kunde_id = ?', [$kunde['id']], 'ORDER BY name');
    return $kunde;
}, $buero);

$router->post('kunden', static function (Request $r): array {
    $id = Database::get()->insert('kunden', [
        'firmenname'        => $r->text('firmenname', 150, true),
        'uid_nummer'        => $r->text('uid_nummer', 20),
        'adresse'           => $r->text('adresse', 150),
        'plz'               => $r->text('plz', 10),
        'ort'               => $r->text('ort', 80),
        'bezirk'            => $r->text('bezirk', 40),
        'kontaktperson'     => $r->text('kontaktperson', 120),
        'telefon'           => $r->text('telefon', 40),
        'email'             => $r->email('email'),
        'rechnungs_email'   => $r->email('rechnungs_email'),
        'zahlungsziel_tage' => $r->int('zahlungsziel_tage', false, 0, 90) ?? 14,
        'status'            => $r->enum('status', ['interessent', 'aktiv', 'inaktiv']) ?? 'interessent',
        'notizen'           => $r->text('notizen', 4000),
    ]);
    AuditLog::write('insert', 'kunden', $id, 'Kunde angelegt');
    return ['id' => $id];
}, $buero);

$router->patch('kunden/{id}', static function (Request $r): array {
    $id = $r->paramInt('id');
    $data = array_filter([
        'firmenname'    => $r->text('firmenname', 150),
        'kontaktperson' => $r->text('kontaktperson', 120),
        'telefon'       => $r->text('telefon', 40),
        'email'         => $r->email('email'),
        'status'        => $r->enum('status', ['interessent', 'aktiv', 'inaktiv']),
        'notizen'       => $r->text('notizen', 4000),
    ], static fn($v) => $v !== null);

    $n = Database::get()->update('kunden', $id, $data);
    if ($n === 0) {
        Response::error('Kunde nicht gefunden oder unverändert.', 404);
    }
    AuditLog::write('update', 'kunden', $id, 'Kunde geändert');
    return ['geaendert' => true];
}, $buero);

// =====================================================================
// Büro: Objekte
// =====================================================================

$router->get('objekte', static function (Request $r): array {
    return Database::get()->select('objekte', 'aktiv = 1', [], 'ORDER BY name LIMIT 300');
}, $buero);

$router->post('objekte', static function (Request $r): array {
    $db = Database::get();
    $kundeId = (int) $r->int('kunde_id', true);
    if ($db->find('kunden', $kundeId) === null) {
        Response::error('Kunde nicht gefunden.', 422, ['kunde_id' => 'Unbekannt.']);
    }

    return $db->transaction(static function () use ($db, $r, $kundeId): array {
        $id = $db->insert('objekte', [
            'kunde_id'           => $kundeId,
            'name'               => $r->text('name', 150, true),
            'adresse'            => $r->text('adresse', 150, true),
            'plz'                => $r->text('plz', 10, true),
            'ort'                => $r->text('ort', 80, true),
            'ansprechpartner'    => $r->text('ansprechpartner', 120),
            'telefon'            => $r->text('telefon', 40),
            'email'              => $r->email('email'),
            'zugang_info'        => $r->text('zugang_info', 2000),
            'reinigung_hinweise' => $r->text('reinigung_hinweise', 4000),
            'qr_token'           => Crypto::token(16),
        ]);

        // Zugangsdaten getrennt und verschluesselt ablegen
        $schluessel = $r->text('schluessel_info', 500);
        $alarm      = $r->text('alarm_code', 60);
        if ($schluessel !== null || $alarm !== null) {
            $db->raw(
                'INSERT INTO objekt_zugang (objekt_id, betrieb_id, schluessel_info_enc, alarm_code_enc) VALUES (?, ?, ?, ?)',
                [$id, $db->tenant(), Crypto::encrypt($schluessel), Crypto::encrypt($alarm)]
            );
        }
        AuditLog::write('insert', 'objekte', $id, 'Objekt angelegt');
        return ['id' => $id];
    });
}, $buero);

/** Zugangsdaten separat abrufen — nur Inhaber, und im Protokoll vermerkt. */
$router->get('objekte/{id}/zugang', static function (Request $r): array {
    $id = $r->paramInt('id');
    $db = Database::get();
    if ($db->find('objekte', $id) === null) {
        Response::error('Objekt nicht gefunden.', 404);
    }
    $row = $db->rawOne(
        'SELECT schluessel_info_enc, alarm_code_enc FROM objekt_zugang WHERE objekt_id = ? AND betrieb_id = ?',
        [$id, $db->tenant()]
    );
    AuditLog::write('read', 'objekt_zugang', $id, 'Zugangsdaten eingesehen');
    return [
        'schluessel_info' => Crypto::decrypt($row['schluessel_info_enc'] ?? null),
        'alarm_code'      => Crypto::decrypt($row['alarm_code_enc'] ?? null),
    ];
}, ['inhaber']);

// =====================================================================
// Büro: Mitarbeiter
// =====================================================================

$router->get('mitarbeiter', static function (): array {
    return Database::get()->select('mitarbeiter', 'aktiv = 1', [], 'ORDER BY nachname, vorname');
}, $buero);

$router->post('mitarbeiter', static function (Request $r): array {
    $db = Database::get();
    $pin = (string) ($r->all()['pin'] ?? '');
    if (preg_match('/^\d{4}$/', $pin) !== 1) {
        Response::error('PIN muss aus vier Ziffern bestehen.', 422, ['pin' => 'Vier Ziffern.']);
    }

    return $db->transaction(static function () use ($db, $r, $pin): array {
        $personalnummer = (string) $r->text('personalnummer', 20, true);

        $id = $db->insert('mitarbeiter', [
            'personalnummer'    => $personalnummer,
            'vorname'           => $r->text('vorname', 80, true),
            'nachname'          => $r->text('nachname', 80, true),
            'telefon'           => $r->text('telefon', 40),
            'email'             => $r->email('email'),
            'adresse'           => $r->text('adresse', 150),
            'plz'               => $r->text('plz', 10),
            'ort'               => $r->text('ort', 80),
            'geburtsdatum'      => $r->date('geburtsdatum'),
            'eintrittsdatum'    => $r->date('eintrittsdatum'),
            'anstellung'        => $r->enum('anstellung', ['geringfuegig', 'teilzeit', 'vollzeit'], true),
            'stunden_woche'     => $r->decimal('stunden_woche') ?? 0,
            'lohngruppe'        => $r->text('lohngruppe', 10),
            'stundenlohn'       => $r->decimal('stundenlohn'),
            'verrechnungssatz'  => $r->decimal('verrechnungssatz'),
        ]);

        // Sensible Felder getrennt und verschluesselt
        $svnr = $r->text('svnr', 20);
        $iban = $r->text('iban', 34);
        if ($svnr !== null || $iban !== null) {
            $db->raw(
                'INSERT INTO mitarbeiter_sensibel (mitarbeiter_id, betrieb_id, svnr_enc, iban_enc) VALUES (?, ?, ?, ?)',
                [$id, $db->tenant(), Crypto::encrypt($svnr), Crypto::encrypt($iban)]
            );
        }

        // Zugang für die App
        $db->insert('benutzer', [
            'rolle'          => 'mitarbeiter',
            'benutzername'   => $personalnummer,
            'pin_hash'       => password_hash($pin, PASSWORD_DEFAULT),
            'mitarbeiter_id' => $id,
        ]);

        AuditLog::write('insert', 'mitarbeiter', $id, 'Mitarbeiter angelegt');
        return ['id' => $id];
    });
}, $buero);

// =====================================================================
// Büro: Einsätze und Dienstplan
// =====================================================================

$router->get('einsaetze', static function (Request $r): array {
    $von = $r->query('von') ?? date('Y-m-d');
    $bis = $r->query('bis') ?? date('Y-m-d', strtotime('+7 days'));
    $db  = Database::get();

    $rows = $db->rawAll(
        'SELECT * FROM v_einsaetze_details WHERE betrieb_id = ? AND datum BETWEEN ? AND ? ORDER BY datum, uhrzeit_von',
        [$db->tenant(), $von, $bis]
    );
    // Zugeteilte Mitarbeiter nachladen
    foreach ($rows as &$row) {
        $row['mitarbeiter'] = $db->rawAll(
            'SELECT m.id, m.vorname, m.nachname, em.ist_ersatz
               FROM einsatz_mitarbeiter em
               JOIN mitarbeiter m ON m.id = em.mitarbeiter_id
              WHERE em.einsatz_id = ? AND em.betrieb_id = ?',
            [$row['id'], $db->tenant()]
        );
    }
    return $rows;
}, $buero);

$router->post('einsaetze', static function (Request $r): array {
    $db = Database::get();
    $objektId = (int) $r->int('objekt_id', true);
    $objekt = $db->find('objekte', $objektId);
    if ($objekt === null) {
        Response::error('Objekt nicht gefunden.', 422, ['objekt_id' => 'Unbekannt.']);
    }

    $datum = (string) $r->date('datum', true);
    $von   = (string) $r->time('uhrzeit_von', true);
    $bis   = (string) $r->time('uhrzeit_bis', true);
    if ($bis <= $von) {
        Response::error('Endzeit muss nach der Startzeit liegen.', 422, ['uhrzeit_bis' => 'Zu früh.']);
    }

    $mitarbeiterIds = array_map('intval', (array) ($r->all()['mitarbeiter_ids'] ?? []));

    return $db->transaction(static function () use ($db, $r, $objektId, $datum, $von, $bis, $mitarbeiterIds): array {
        $einsatzId = $db->insert('einsaetze', [
            'objekt_id'          => $objektId,
            'objekt_leistung_id' => $r->int('objekt_leistung_id'),
            'leistungsart_id'    => $r->int('leistungsart_id', true),
            'subunternehmer_id'  => $r->int('subunternehmer_id'),
            'datum'              => $datum,
            'uhrzeit_von'        => $von,
            'uhrzeit_bis'        => $bis,
            'dauer_soll_minuten' => $r->int('dauer_soll_minuten', false, 5, 1440) ?? 60,
            'notizen_office'     => $r->text('notizen_office', 2000),
        ]);

        foreach ($mitarbeiterIds as $mid) {
            if ($db->find('mitarbeiter', $mid) === null) {
                Response::error('Unbekannter Mitarbeiter in der Zuteilung.', 422);
            }
            // Doppelbelegung verhindern
            $konflikt = $db->rawOne(
                'SELECT e.id FROM einsatz_mitarbeiter em
                   JOIN einsaetze e ON e.id = em.einsatz_id
                  WHERE em.mitarbeiter_id = ? AND em.betrieb_id = ? AND e.datum = ?
                    AND e.status NOT IN (\'storniert\',\'ausgefallen\')
                    AND e.uhrzeit_von < ? AND e.uhrzeit_bis > ?
                  LIMIT 1',
                [$mid, $db->tenant(), $datum, $bis, $von]
            );
            if ($konflikt !== null) {
                Response::error('Mitarbeiter ist zu dieser Zeit bereits eingeteilt.', 409);
            }
            $db->insert('einsatz_mitarbeiter', ['einsatz_id' => $einsatzId, 'mitarbeiter_id' => $mid]);
        }

        AuditLog::write('insert', 'einsaetze', $einsatzId, 'Einsatz geplant');
        return ['id' => $einsatzId];
    });
}, $buero);

// =====================================================================
// Mitarbeiter-App
// =====================================================================

$router->get('app/meine-einsaetze', static function (Request $r): array {
    $u  = Auth::user();
    $db = Database::get();
    $von = $r->query('von') ?? date('Y-m-d', strtotime('-1 day'));
    $bis = $r->query('bis') ?? date('Y-m-d', strtotime('+14 days'));

    // Nur eigene Einsätze — die Mitarbeiter-ID kommt aus dem Token,
    // nicht aus der Anfrage.
    return $db->rawAll(
        'SELECT e.id, e.datum, e.uhrzeit_von, e.uhrzeit_bis, e.status, e.dauer_soll_minuten,
                e.notizen_office, e.notizen_mitarbeiter, o.id AS objekt_id, o.name AS objekt_name, o.adresse, o.plz, o.ort,
                o.reinigung_hinweise, o.zugang_info, o.ansprechpartner, o.telefon AS objekt_telefon, l.name AS leistung,
                z.checkin_zeit, z.checkout_zeit, z.minuten_netto,
                (SELECT COUNT(*) FROM einsatz_fotos f WHERE f.einsatz_id = e.id AND f.geloescht = 0) AS fotos,
                (SELECT COUNT(*) FROM einsatz_fotos f WHERE f.einsatz_id = e.id AND f.geloescht = 0 AND f.typ = \'vorher\') AS fotos_vorher,
                (SELECT COUNT(*) FROM einsatz_fotos f WHERE f.einsatz_id = e.id AND f.geloescht = 0 AND f.typ = \'nachher\') AS fotos_nachher,
                (SELECT GROUP_CONCAT(CONCAT(m2.vorname,\' \',LEFT(m2.nachname,1),\'.\') SEPARATOR \', \') FROM einsatz_mitarbeiter em2 JOIN mitarbeiter m2 ON m2.id = em2.mitarbeiter_id WHERE em2.einsatz_id = e.id AND em2.mitarbeiter_id <> em.mitarbeiter_id) AS kollegen
           FROM einsatz_mitarbeiter em
           JOIN einsaetze e       ON e.id = em.einsatz_id
           JOIN objekte o         ON o.id = e.objekt_id
           JOIN leistungsarten l  ON l.id = e.leistungsart_id
      LEFT JOIN zeiterfassung z   ON z.einsatz_id = e.id AND z.mitarbeiter_id = em.mitarbeiter_id
          WHERE em.mitarbeiter_id = ? AND em.betrieb_id = ?
            AND e.datum BETWEEN ? AND ? AND e.status <> \'storniert\'
          ORDER BY e.datum, e.uhrzeit_von',
        [(int) $u['mitarbeiter_id'], $db->tenant(), $von, $bis]
    );
}, ['mitarbeiter']);

/** Check-in per QR- oder NFC-Token am Objekt. */
$router->post('app/checkin', static function (Request $r): array {
    $u  = Auth::user();
    $db = Database::get();

    $einsatzId = (int) $r->int('einsatz_id', true);
    $token     = (string) $r->text('token', 32, true);
    $methode   = (string) ($r->enum('methode', ['qr', 'nfc']) ?? 'qr');

    // Einsatz muss dem Mitarbeiter gehören
    $einsatz = $db->rawOne(
        'SELECT e.*, o.qr_token, o.nfc_token
           FROM einsaetze e
           JOIN objekte o            ON o.id = e.objekt_id
           JOIN einsatz_mitarbeiter em ON em.einsatz_id = e.id AND em.mitarbeiter_id = ?
          WHERE e.id = ? AND e.betrieb_id = ? LIMIT 1',
        [(int) $u['mitarbeiter_id'], $einsatzId, $db->tenant()]
    );
    if ($einsatz === null) {
        Response::error('Einsatz nicht gefunden oder nicht zugeteilt.', 404);
    }
    // Token des Objekts prüfen — beweist die Anwesenheit vor Ort
    $gueltig = hash_equals((string) $einsatz['qr_token'], $token)
            || ($einsatz['nfc_token'] !== null && hash_equals((string) $einsatz['nfc_token'], $token));
    if (!$gueltig) {
        Response::error('Dieser Code gehört nicht zu diesem Objekt.', 422);
    }

    $vorhanden = $db->rawOne(
        'SELECT id, checkin_zeit FROM zeiterfassung WHERE einsatz_id = ? AND mitarbeiter_id = ? LIMIT 1',
        [$einsatzId, (int) $u['mitarbeiter_id']]
    );
    if ($vorhanden !== null) {
        return ['id' => (int) $vorhanden['id'], 'checkin_zeit' => $vorhanden['checkin_zeit'], 'hinweis' => 'Bereits eingestempelt.'];
    }

    $zeit = geraetezeit($r->text('zeit', 30));
    $id = $db->insert('zeiterfassung', [
        'einsatz_id'      => $einsatzId,
        'mitarbeiter_id'  => (int) $u['mitarbeiter_id'],
        'checkin_zeit'    => $zeit,
        'checkin_methode' => $methode,
    ]);
    $db->update('einsaetze', $einsatzId, ['status' => 'laeuft']);

    return ['id' => $id, 'checkin_zeit' => $zeit];
}, ['mitarbeiter']);

$router->post('app/checkout', static function (Request $r): array {
    $u  = Auth::user();
    $db = Database::get();
    $einsatzId = (int) $r->int('einsatz_id', true);
    $pause     = $r->int('pause_minuten', false, 0, 480) ?? 0;

    $z = $db->rawOne(
        'SELECT * FROM zeiterfassung WHERE einsatz_id = ? AND mitarbeiter_id = ? AND betrieb_id = ? LIMIT 1',
        [$einsatzId, (int) $u['mitarbeiter_id'], $db->tenant()]
    );
    if ($z === null) {
        Response::error('Kein Check-in vorhanden.', 422);
    }
    if ($z['checkout_zeit'] !== null) {
        return ['hinweis' => 'Bereits ausgestempelt.', 'minuten_netto' => (int) $z['minuten_netto']];
    }

    $ein  = new DateTimeImmutable((string) $z['checkin_zeit']);
    $aus  = new DateTimeImmutable(geraetezeit($r->text('zeit', 30)));
    if ($aus < $ein) { $aus = $ein; }
    $brutto = (int) round(($aus->getTimestamp() - $ein->getTimestamp()) / 60);
    $netto  = max(0, $brutto - $pause);

    $db->raw(
        'UPDATE zeiterfassung
            SET checkout_zeit = ?, checkout_methode = ?, pause_minuten = ?, minuten_brutto = ?, minuten_netto = ?
          WHERE id = ? AND betrieb_id = ?',
        [$aus->format('Y-m-d H:i:s'), $r->enum('methode', ['qr', 'nfc']) ?? 'qr', $pause, $brutto, $netto, (int) $z['id'], $db->tenant()]
    );
    $db->update('einsaetze', $einsatzId, ['status' => 'erledigt']);
    einsatzErledigtMelden($einsatzId);

    return ['minuten_brutto' => $brutto, 'minuten_netto' => $netto];
}, ['mitarbeiter']);

// ---------------------------------------------------------------------
// App: QR-Token am Objekt aufloesen → passende Einsaetze des Mitarbeiters
// ---------------------------------------------------------------------
$router->get('app/objekt/{token}', static function (Request $r): array {
    $u  = Auth::user();
    $db = Database::get();
    $token = (string) $r->param('token');
    if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
        Response::error('Ungültiger Code.', 422);
    }
    $o = $db->selectOne('objekte', '(qr_token = ? OR nfc_token = ?) AND aktiv = 1', [$token, $token]);
    if ($o === null) {
        Response::error('Dieser Code gehört zu keinem Objekt Ihres Betriebs.', 404);
    }
    $einsaetze = $db->rawAll(
        "SELECT e.id, e.datum, e.uhrzeit_von, e.uhrzeit_bis, e.status, z.checkin_zeit, z.checkout_zeit
           FROM einsaetze e JOIN einsatz_mitarbeiter em ON em.einsatz_id = e.id AND em.mitarbeiter_id = ?
      LEFT JOIN zeiterfassung z ON z.einsatz_id = e.id AND z.mitarbeiter_id = em.mitarbeiter_id
          WHERE e.objekt_id = ? AND e.betrieb_id = ? AND e.datum BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)
            AND e.status NOT IN ('storniert','ausgefallen') ORDER BY e.datum, e.uhrzeit_von",
        [(int) $u['mitarbeiter_id'], (int) $o['id'], $db->tenant()]
    );
    return ['objekt' => ['id' => (int) $o['id'], 'name' => $o['name'], 'adresse' => $o['adresse']], 'einsaetze' => $einsaetze];
}, ['mitarbeiter']);

// ---------------------------------------------------------------------
// App: Foto hochladen (multipart, Feld "foto")
// ---------------------------------------------------------------------
// Fotos eines Einsatzes mit kleinem Vorschaubild (nur eigene Einsaetze)
$router->get('app/einsaetze/{id}/fotos', static function (Request $r): array {
    $u = Auth::user(); $db = Database::get(); $id = $r->paramInt('id');
    $zugeteilt = $db->rawOne('SELECT 1 FROM einsatz_mitarbeiter WHERE einsatz_id = ? AND mitarbeiter_id = ? AND betrieb_id = ?', [$id, (int) $u['mitarbeiter_id'], $db->tenant()]);
    if ($zugeteilt === null) { Response::error('Nicht zugeteilt.', 403); }
    $out = [];
    foreach ($db->select('einsatz_fotos', 'einsatz_id = ? AND geloescht = 0', [$id], 'ORDER BY aufgenommen_am') as $f) {
        $pfad = dirname(__DIR__) . '/system/fotos/' . $f['pfad']; $thumb = null;
        if (is_file($pfad) && ($src = @imagecreatefromjpeg($pfad)) !== false) {
            $w = imagesx($src); $h = imagesy($src); $tw = 240; $th = (int) round($h * $tw / $w);
            $t = imagecreatetruecolor($tw, $th); imagecopyresampled($t, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
            ob_start(); imagejpeg($t, null, 70); $thumb = 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean()); imagedestroy($t); imagedestroy($src);
        }
        $out[] = ['id' => (int) $f['id'], 'typ' => $f['typ'], 'aufgenommen_am' => $f['aufgenommen_am'], 'eigenes' => (int) $f['mitarbeiter_id'] === (int) $u['mitarbeiter_id'], 'thumb' => $thumb];
    }
    return $out;
}, ['mitarbeiter']);

$router->post('app/foto', static function (Request $r): array {
    $u  = Auth::user();
    $db = Database::get();
    $einsatzId = (int) ($_POST['einsatz_id'] ?? 0);
    $typ = in_array($_POST['typ'] ?? '', ['vorher', 'nachher', 'schaden', 'sonstiges'], true) ? $_POST['typ'] : 'nachher';
    $zugeteilt = $db->rawOne('SELECT 1 FROM einsatz_mitarbeiter WHERE einsatz_id = ? AND mitarbeiter_id = ? AND betrieb_id = ?',
        [$einsatzId, (int) $u['mitarbeiter_id'], $db->tenant()]);
    if ($zugeteilt === null) {
        Response::error('Einsatz nicht zugeteilt.', 403);
    }
    global $config;
    $res = \App\Photo::store($_FILES['foto'] ?? [], $einsatzId, (int) $u['mitarbeiter_id'], $typ, (int) ($config['files']['photo_retention'] ?? 3));
    return ['id' => $res['id'], 'bytes' => $res['bytes']];
}, ['mitarbeiter']);

// ---------------------------------------------------------------------
// App: Offline-Warteschlange abarbeiten
// Jeder Eintrag: {typ: checkin|checkout|notiz, einsatz_id, token?, zeit, pause_minuten?, text?, lokal_id}
// ---------------------------------------------------------------------
$router->post('app/sync', static function (Request $r): array {
    $eintraege = (array) ($r->all()['eintraege'] ?? []);
    $ergebnis = [];
    foreach (array_slice($eintraege, 0, 100) as $e) {
        $lokal = (string) ($e['lokal_id'] ?? '');
        try {
            $res = syncEintrag(is_array($e) ? $e : []);
            $ergebnis[] = ['lokal_id' => $lokal, 'ok' => true, 'data' => $res];
        } catch (\Throwable $ex) {
            $ergebnis[] = ['lokal_id' => $lokal, 'ok' => false, 'fehler' => $ex->getMessage()];
        }
    }
    return $ergebnis;
}, ['mitarbeiter']);

// ---------------------------------------------------------------------
// App: Notiz zum Einsatz
// ---------------------------------------------------------------------
$router->post('app/einsatz/{id}/notiz', static function (Request $r): array {
    $u = Auth::user(); $db = Database::get();
    $id = $r->paramInt('id');
    $zugeteilt = $db->rawOne('SELECT 1 FROM einsatz_mitarbeiter WHERE einsatz_id = ? AND mitarbeiter_id = ? AND betrieb_id = ?', [$id, (int) $u['mitarbeiter_id'], $db->tenant()]);
    if ($zugeteilt === null) { Response::error('Einsatz nicht zugeteilt.', 403); }
    $db->update('einsaetze', $id, ['notizen_mitarbeiter' => $r->text('text', 2000)]);
    return ['gespeichert' => true];
}, ['mitarbeiter']);

// ---------------------------------------------------------------------
// App: Abwesenheiten
// ---------------------------------------------------------------------
$router->get('app/abwesenheiten', static function (): array {
    $u = Auth::user(); $db = Database::get();
    $liste = $db->select('abwesenheiten', 'mitarbeiter_id = ? AND datum_bis >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)', [(int) $u['mitarbeiter_id']], 'ORDER BY datum_von DESC LIMIT 50');
    $m = $db->find('mitarbeiter', (int) $u['mitarbeiter_id']);
    $genommen = (float) ($db->rawOne("SELECT IFNULL(SUM(tage),0) s FROM abwesenheiten WHERE mitarbeiter_id = ? AND betrieb_id = ? AND typ = 'urlaub' AND status = 'genehmigt' AND YEAR(datum_von) = YEAR(CURDATE())", [(int) $u['mitarbeiter_id'], $db->tenant()])['s'] ?? 0);
    return ['liste' => $liste, 'urlaub_anspruch' => (float) ($m['urlaubstage_jahr'] ?? 0), 'urlaub_genommen' => $genommen];
}, ['mitarbeiter']);

$router->post('app/abwesenheiten', static function (Request $r): array {
    $u = Auth::user(); $db = Database::get();
    $typ = $r->enum('typ', ['urlaub', 'krank', 'zeitausgleich', 'sonstiges'], true);
    $von = (string) $r->date('datum_von', true);
    $bis = $r->date('datum_bis') ?? $von;
    if ($bis < $von) { Response::error('Ende liegt vor dem Beginn.', 422); }
    $tage = 0;
    for ($d = new DateTimeImmutable($von); $d <= new DateTimeImmutable($bis); $d = $d->modify('+1 day')) { if ((int) $d->format('N') <= 5) { $tage++; } }
    $id = $db->insert('abwesenheiten', ['mitarbeiter_id' => (int) $u['mitarbeiter_id'], 'typ' => $typ, 'datum_von' => $von, 'datum_bis' => $bis,
        'tage' => max(1, $tage), 'status' => 'beantragt']);
    // Buero informieren
    $b = $db->rawOne('SELECT email FROM betriebe WHERE id = ?', [$db->tenant()]);
    $m = $db->find('mitarbeiter', (int) $u['mitarbeiter_id']);
    if (!empty($b['email'])) {
        $db->insert('benachrichtigungen', ['kanal' => 'mail', 'empfaenger_typ' => 'office', 'empfaenger_email' => $b['email'],
            'betreff' => 'Antrag: ' . ($typ === 'krank' ? 'Krankmeldung' : ucfirst($typ)) . ' ' . $m['vorname'] . ' ' . $m['nachname'],
            'inhalt' => $m['vorname'] . ' ' . $m['nachname'] . ' meldet ' . ($typ === 'krank' ? 'Krankenstand' : $typ) . ' von ' . date('d.m.Y', strtotime($von)) . ' bis ' . date('d.m.Y', strtotime($bis)) . '. Bitte im Büro bearbeiten.',
            'referenz_typ' => 'abwesenheit', 'referenz_id' => $id]);
    }
    return ['id' => $id, 'status' => 'beantragt'];
}, ['mitarbeiter']);

// Direktzugang des Anbieter-Superusers in die App: einmaliges Token gegen App-Token tauschen
$router->open('POST', 'app/direkt', static function (Request $r): array {
    $db = Database::get(); $t = (string) ($r->all()['token'] ?? '');
    if (preg_match('/^[a-f0-9]{40}$/', $t) !== 1) { Response::error('Ungültig.', 400); }
    $dz = $db->rawOne("SELECT * FROM anbieter_direktzugang WHERE token_hash = ? AND ziel = 'app' AND verwendet_am IS NULL AND laeuft_ab > NOW()", [hash('sha256', $t)]);
    if ($dz === null) { Response::error('Abgelaufen oder ungültig.', 403); }
    $db->raw('UPDATE anbieter_direktzugang SET verwendet_am = NOW() WHERE id = ?', [(int) $dz['id']]);
    $mb = $db->rawOne("SELECT * FROM benutzer WHERE id = ? AND rolle = 'mitarbeiter' AND aktiv = 1", [(int) $dz['benutzer_id']]);
    if ($mb === null) { Response::error('Mitarbeiter nicht aktiv.', 403); }
    return ['token' => Auth::appTokenOhnePin($mb)];
});

// ---------------------------------------------------------------------
// App: Web-Push und Kalender-Abo
// ---------------------------------------------------------------------
$router->get('app/push/schluessel', static fn(): array => ['public' => \App\Push::keys()['public']], ['mitarbeiter']);

$router->post('app/push/abo', static function (Request $r): array {
    $u = Auth::user(); $db = Database::get(); $d = $r->all();
    $ep = trim((string) ($d['endpoint'] ?? ''));
    if ($ep === '' || filter_var($ep, FILTER_VALIDATE_URL) === false || !str_starts_with($ep, 'https://')) { Response::error('Ungültiges Abo.', 422); }
    $db->raw('INSERT INTO push_abos (betrieb_id, mitarbeiter_id, endpoint, endpoint_hash, p256dh, auth, geraet) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE mitarbeiter_id = VALUES(mitarbeiter_id), betrieb_id = VALUES(betrieb_id), p256dh = VALUES(p256dh), auth = VALUES(auth), geraet = VALUES(geraet), fehler = 0',
        [$db->tenant(), (int) $u['mitarbeiter_id'], $ep, hash('sha256', $ep), mb_substr((string) ($d['p256dh'] ?? ''), 0, 200) ?: null, mb_substr((string) ($d['auth'] ?? ''), 0, 100) ?: null, mb_substr((string) ($d['geraet'] ?? ''), 0, 120) ?: null]);
    return ['ok' => true];
}, ['mitarbeiter']);

$router->post('app/push/abmelden', static function (Request $r): array {
    $u = Auth::user(); $db = Database::get();
    $db->raw('DELETE FROM push_abos WHERE endpoint_hash = ? AND mitarbeiter_id = ?', [hash('sha256', trim((string) ($r->all()['endpoint'] ?? ''))), (int) $u['mitarbeiter_id']]);
    return ['ok' => true];
}, ['mitarbeiter']);

// Vom Service Worker beim Eintreffen eines Push abgerufen: Kurzmeldung ohne Login,
// Identitaet ueber den (geheimen) Endpoint des Abos. Nur Titel, keine Details.
$router->open('POST', 'app/push/aktuell', static function (Request $r): array {
    $db = Database::get();
    $abo = $db->rawOne('SELECT mitarbeiter_id FROM push_abos WHERE endpoint_hash = ?', [hash('sha256', trim((string) ($r->all()['endpoint'] ?? '')))]);
    if ($abo === null) { Response::error('Unbekannt.', 404); }
    $mid = (int) $abo['mitarbeiter_id'];
    $anfr = $db->rawOne("SELECT COUNT(*) n FROM einsatz_anfragen WHERE mitarbeiter_id = ? AND status = 'offen'", [$mid])['n'] ?? 0;
    $nachr = $db->rawOne('SELECT titel FROM app_nachrichten WHERE mitarbeiter_id = ? AND gelesen_am IS NULL ORDER BY erstellt_am DESC LIMIT 1', [$mid]);
    $b = $db->rawOne('SELECT b.name FROM betriebe b JOIN mitarbeiter m ON m.betrieb_id = b.id WHERE m.id = ?', [$mid]);
    if ((int) $anfr > 0) { return ['titel' => 'Kannst du einspringen?', 'text' => ((int) $anfr === 1 ? 'Eine offene Einsatzanfrage' : $anfr . ' offene Einsatzanfragen') . ' — bitte in der App antworten.', 'ziel' => 'anfragen']; }
    if ($nachr !== null) { return ['titel' => $b['name'] ?? 'Nachricht', 'text' => (string) $nachr['titel'], 'ziel' => 'nachrichten']; }
    return ['titel' => $b['name'] ?? 'Takt', 'text' => 'Neuigkeiten in der App.', 'ziel' => 'plan'];
});

$router->get('app/kalender/link', static function () use ($config): array {
    $u = Auth::user(); $db = Database::get();
    if (empty($u['kalender_token'])) { $db->raw('UPDATE benutzer SET kalender_token = ? WHERE id = ?', [bin2hex(random_bytes(16)), (int) $u['id']]); $u = $db->find('benutzer', (int) $u['id']); }
    return ['url' => rtrim($config['app']['url'], '/') . '/api/kalender/' . $u['kalender_token'] . '.ics'];
}, ['mitarbeiter']);

// ICS-Feed ohne Login (Token in der URL), 8 Wochen voraus und 2 zurueck
$router->open('GET', 'kalender/{datei}', static function (Request $r): never {
    $db = Database::get(); $tok = preg_replace('/[^a-f0-9]/', '', str_replace('.ics', '', (string) $r->param('datei')));
    $u = strlen($tok) === 32 ? $db->rawOne("SELECT * FROM benutzer WHERE kalender_token = ? AND rolle = 'mitarbeiter' AND aktiv = 1", [$tok]) : null;
    if ($u === null) { http_response_code(404); exit('Nicht gefunden.'); }
    $db->setTenant((int) $u['betrieb_id']);
    $rows = $db->rawAll("SELECT e.id, e.datum, e.uhrzeit_von, e.uhrzeit_bis, e.status, o.name objekt, o.adresse, o.plz, o.ort, l.name leistung, e.aktualisiert_am
        FROM einsaetze e JOIN einsatz_mitarbeiter em ON em.einsatz_id = e.id JOIN objekte o ON o.id = e.objekt_id JOIN leistungsarten l ON l.id = e.leistungsart_id
        WHERE em.mitarbeiter_id = ? AND e.betrieb_id = ? AND e.datum BETWEEN DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND DATE_ADD(CURDATE(), INTERVAL 56 DAY) AND e.status <> 'ausgefallen' ORDER BY e.datum, e.uhrzeit_von", [(int) $u['mitarbeiter_id'], (int) $u['betrieb_id']]);
    $b = $db->rawOne('SELECT name FROM betriebe WHERE id = ?', [(int) $u['betrieb_id']]);
    $esc = static fn(string $t): string => str_replace(["\\", ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $t);
    $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Takt//Einsatzplan//DE\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\nX-WR-CALNAME:" . $esc('Einsätze ' . ($b['name'] ?? '')) . "\r\nX-WR-TIMEZONE:Europe/Vienna\r\n";
    foreach ($rows as $e) {
        $von = new \DateTime($e['datum'] . ' ' . $e['uhrzeit_von'], new \DateTimeZone('Europe/Vienna')); $bis = new \DateTime($e['datum'] . ' ' . $e['uhrzeit_bis'], new \DateTimeZone('Europe/Vienna'));
        $out .= "BEGIN:VEVENT\r\nUID:einsatz-" . $e['id'] . "@takt\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\nDTSTART;TZID=Europe/Vienna:" . $von->format('Ymd\THis') . "\r\nDTEND;TZID=Europe/Vienna:" . $bis->format('Ymd\THis') . "\r\n"
              . "SUMMARY:" . $esc($e['objekt'] . ' – ' . $e['leistung']) . "\r\nLOCATION:" . $esc($e['adresse'] . ', ' . $e['plz'] . ' ' . $e['ort']) . "\r\nSTATUS:" . ($e['status'] === 'erledigt' ? 'CONFIRMED' : 'TENTATIVE') . "\r\nEND:VEVENT\r\n";
    }
    $out .= "END:VCALENDAR\r\n";
    header('Content-Type: text/calendar; charset=utf-8'); header('Content-Disposition: inline; filename="einsaetze.ics"'); header('Cache-Control: private, max-age=900');
    echo $out; exit;
});

// ---------------------------------------------------------------------
// App: Einsatzanfragen (Kannst du einspringen?)
// ---------------------------------------------------------------------
$router->get('app/anfragen', static function (): array {
    $u = Auth::user(); $db = Database::get();
    // Abgelaufene Anfragen (Einsatz vorbei) schliessen
    $db->raw("UPDATE einsatz_anfragen a JOIN einsaetze e ON e.id = a.einsatz_id SET a.status = 'abgelaufen' WHERE a.mitarbeiter_id = ? AND a.status = 'offen' AND CONCAT(e.datum,' ',e.uhrzeit_von) < NOW()", [(int) $u['mitarbeiter_id']]);
    return $db->rawAll("SELECT a.id, a.status, a.nachricht, a.gesendet_am, e.id einsatz_id, e.datum, e.uhrzeit_von, e.uhrzeit_bis, e.dauer_soll_minuten, o.name objekt_name, o.adresse, o.plz, o.ort, l.name leistung
           FROM einsatz_anfragen a JOIN einsaetze e ON e.id = a.einsatz_id JOIN objekte o ON o.id = e.objekt_id JOIN leistungsarten l ON l.id = e.leistungsart_id
          WHERE a.mitarbeiter_id = ? AND a.betrieb_id = ? AND (a.status = 'offen' OR a.beantwortet_am > DATE_SUB(NOW(), INTERVAL 7 DAY)) ORDER BY a.status = 'offen' DESC, e.datum, e.uhrzeit_von", [(int) $u['mitarbeiter_id'], $db->tenant()]);
}, ['mitarbeiter']);

$router->post('app/anfragen/{id}/antwort', static function (Request $r): array {
    $u = Auth::user(); $db = Database::get();
    $id = $r->paramInt('id');
    $a = $db->rawOne("SELECT * FROM einsatz_anfragen WHERE id = ? AND mitarbeiter_id = ? AND betrieb_id = ? AND status = 'offen'", [$id, (int) $u['mitarbeiter_id'], $db->tenant()]);
    if ($a === null) { Response::error('Anfrage nicht mehr offen.', 404); }
    $ja = !empty($r->all()['zusage']);
    $text = mb_substr(trim((string) ($r->all()['antwort'] ?? '')), 0, 500);
    $ein = $db->rawOne('SELECT e.*, o.name objekt FROM einsaetze e JOIN objekte o ON o.id = e.objekt_id WHERE e.id = ?', [(int) $a['einsatz_id']]);
    $m = $db->find('mitarbeiter', (int) $u['mitarbeiter_id']);
    if ($ja) {
        // Nochmals auf Konflikt pruefen, dann zuteilen
        $konflikt = \App\Schedule::conflict((int) $u['mitarbeiter_id'], (string) $ein['datum'], (string) $ein['uhrzeit_von'], (string) $ein['uhrzeit_bis'], (int) $ein['id']);
        if ($konflikt !== null) { Response::error('Inzwischen belegt: ' . $konflikt, 409); }
        $schon = $db->rawOne('SELECT 1 FROM einsatz_mitarbeiter WHERE einsatz_id = ? AND mitarbeiter_id = ?', [(int) $ein['id'], (int) $u['mitarbeiter_id']]);
        if ($schon === null) { $db->insert('einsatz_mitarbeiter', ['einsatz_id' => (int) $ein['id'], 'mitarbeiter_id' => (int) $u['mitarbeiter_id'], 'ist_ersatz' => 1]); }
        $db->raw("UPDATE einsatz_anfragen SET status = 'zugesagt', antwort = ?, beantwortet_am = NOW() WHERE id = ?", [$text ?: null, $id]);
        // Andere offene Anfragen zu diesem Einsatz zurueckziehen und die Betroffenen informieren
        foreach ($db->rawAll("SELECT id, mitarbeiter_id FROM einsatz_anfragen WHERE einsatz_id = ? AND status = 'offen' AND id <> ?", [(int) $ein['id'], $id]) as $andere) {
            $db->raw("UPDATE einsatz_anfragen SET status = 'zurueckgezogen' WHERE id = ?", [(int) $andere['id']]);
            $db->insert('app_nachrichten', ['mitarbeiter_id' => (int) $andere['mitarbeiter_id'], 'typ' => 'info', 'titel' => 'Einsatz ' . date('d.m.', strtotime($ein['datum'])) . ' bereits besetzt', 'nachricht' => $ein['objekt'] . ' — danke fürs Melden, eine Kollegin/ein Kollege hat übernommen.']);
        }
        try { \App\Schedule::notify((int) $ein['id'], 'ersatz'); } catch (\Throwable) {}
    } else {
        $db->raw("UPDATE einsatz_anfragen SET status = 'abgesagt', antwort = ?, beantwortet_am = NOW() WHERE id = ?", [$text ?: null, $id]);
    }
    // Buero informieren
    $b = $db->rawOne('SELECT email FROM betriebe WHERE id = ?', [$db->tenant()]);
    if (!empty($b['email'])) {
        $db->insert('benachrichtigungen', ['kanal' => 'mail', 'empfaenger_typ' => 'office', 'empfaenger_email' => $b['email'],
            'betreff' => ($ja ? 'Zusage: ' : 'Absage: ') . $m['vorname'] . ' ' . $m['nachname'] . ' für ' . date('d.m.', strtotime($ein['datum'])) . ' ' . $ein['objekt'],
            'inhalt' => $m['vorname'] . ' ' . $m['nachname'] . ' hat ' . ($ja ? 'zugesagt und ist eingeteilt' : 'abgesagt') . ": " . $ein['objekt'] . ', ' . date('d.m.Y', strtotime($ein['datum'])) . ' ' . substr($ein['uhrzeit_von'], 0, 5) . ($text ? "\n\n„" . $text . '“' : ''), 'referenz_typ' => 'einsatz', 'referenz_id' => (int) $ein['id']]);
    }
    return ['status' => $ja ? 'zugesagt' : 'abgesagt'];
}, ['mitarbeiter']);

// ---------------------------------------------------------------------
// App: Nachrichten, Stundenkonto, PIN
// ---------------------------------------------------------------------
$router->get('app/nachrichten', static function (): array {
    $u = Auth::user(); $db = Database::get();
    return $db->select('app_nachrichten', 'mitarbeiter_id = ?', [(int) $u['mitarbeiter_id']], 'ORDER BY erstellt_am DESC LIMIT 50');
}, ['mitarbeiter']);

$router->post('app/nachrichten/gelesen', static function (): array {
    $u = Auth::user(); $db = Database::get();
    $db->raw('UPDATE app_nachrichten SET gelesen_am = NOW() WHERE mitarbeiter_id = ? AND betrieb_id = ? AND gelesen_am IS NULL', [(int) $u['mitarbeiter_id'], $db->tenant()]);
    return ['ok' => true];
}, ['mitarbeiter']);

$router->get('app/stundenkonto', static function (): array {
    $u = Auth::user(); $db = Database::get();
    $monate = $db->rawAll('SELECT jahr, monat, anzahl_einsaetze, minuten_netto FROM v_mitarbeiter_monatsminuten WHERE betrieb_id = ? AND mitarbeiter_id = ? ORDER BY jahr DESC, monat DESC LIMIT 6', [$db->tenant(), (int) $u['mitarbeiter_id']]);
    $zeiten = $db->rawAll(
        'SELECT z.checkin_zeit, z.checkout_zeit, z.minuten_netto, z.pause_minuten, z.checkin_methode, o.name AS objekt
           FROM zeiterfassung z JOIN einsaetze e ON e.id = z.einsatz_id JOIN objekte o ON o.id = e.objekt_id
          WHERE z.mitarbeiter_id = ? AND z.betrieb_id = ? AND z.checkin_zeit >= DATE_SUB(CURDATE(), INTERVAL 45 DAY) ORDER BY z.checkin_zeit DESC',
        [(int) $u['mitarbeiter_id'], $db->tenant()]);
    return ['monate' => $monate, 'zeiten' => $zeiten];
}, ['mitarbeiter']);

$router->post('app/pin', static function (Request $r): array {
    $u = Auth::user(); $db = Database::get();
    $alt = (string) ($r->all()['alt'] ?? ''); $neu = (string) ($r->all()['neu'] ?? '');
    if (!password_verify($alt, (string) $u['pin_hash'])) { Response::error('Die bisherige PIN stimmt nicht.', 422); }
    if (preg_match('/^\d{4}$/', $neu) !== 1) { Response::error('Die neue PIN braucht vier Ziffern.', 422); }
    if ($neu === $alt) { Response::error('Die neue PIN muss sich unterscheiden.', 422); }
    $db->raw('UPDATE benutzer SET pin_hash = ? WHERE id = ?', [password_hash($neu, PASSWORD_DEFAULT), (int) $u['id']]);
    return ['ok' => true];
}, ['mitarbeiter']);

$router->get('app/profil', static function (): array {
    $u = Auth::user(); $db = Database::get();
    $m = $db->find('mitarbeiter', (int) $u['mitarbeiter_id']);
    $b = $db->rawOne('SELECT name, telefon, email FROM betriebe WHERE id = ?', [$db->tenant()]);
    $ungelesen = $db->count('app_nachrichten', 'mitarbeiter_id = ? AND gelesen_am IS NULL', [(int) $u['mitarbeiter_id']]);
    $anfragen = $db->count('einsatz_anfragen', "mitarbeiter_id = ? AND status = 'offen'", [(int) $u['mitarbeiter_id']]);
    $fotoRegel = $db->selectOne('einstellungen', "schluessel = 'foto_regel'")['wert'] ?? 'hinweis';
    return ['name' => $m['vorname'] . ' ' . $m['nachname'], 'personalnummer' => $m['personalnummer'], 'betrieb' => $b, 'ungelesen' => $ungelesen, 'anfragen' => $anfragen, 'stunden_woche' => (float) $m['stunden_woche'], 'foto_regel' => $fotoRegel];
}, ['mitarbeiter']);

// ---------------------------------------------------------------------
// Buero: Fotos ansehen (Auslieferung aus dem gesperrten Bereich)
// ---------------------------------------------------------------------
$router->get('fotos/{id}', static function (Request $r): never {
    \App\Photo::serve($r->paramInt('id'));
}, ['inhaber', 'office', 'mitarbeiter']);

$router->get('einsaetze/{id}/fotos', static function (Request $r): array {
    $db = Database::get();
    $id = $r->paramInt('id');
    if ($db->find('einsaetze', $id) === null) { Response::error('Einsatz nicht gefunden.', 404); }
    return $db->select('einsatz_fotos', 'einsatz_id = ? AND geloescht = 0', [$id], 'ORDER BY aufgenommen_am');
}, ['inhaber', 'office']);

// =====================================================================

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);

// ---------------------------------------------------------------------

/**
 * Zeitangabe vom Geraet (ISO 8601) pruefen: nur Vergangenheit, hoechstens
 * 24 Stunden alt, sonst Serverzeit. Verhindert Manipulation nach vorne
 * und begrenzt Nachlieferungen.
 */
function geraetezeit(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return date('Y-m-d H:i:s');
    }
    try {
        $d = new DateTimeImmutable($iso);
    } catch (\Throwable) {
        return date('Y-m-d H:i:s');
    }
    $d = $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $jetzt = new DateTimeImmutable();
    if ($d > $jetzt || $jetzt->getTimestamp() - $d->getTimestamp() > 86400) {
        return date('Y-m-d H:i:s');
    }
    return $d->format('Y-m-d H:i:s');
}

/** Kunde informieren, sobald alle eingestempelten Kraefte ausgestempelt haben — einmal je Einsatz. */
function einsatzErledigtMelden(int $einsatzId): void
{
    $db = Database::get();
    $offen = (int) ($db->rawOne('SELECT COUNT(*) n FROM zeiterfassung WHERE einsatz_id = ? AND betrieb_id = ? AND checkout_zeit IS NULL', [$einsatzId, $db->tenant()])['n'] ?? 0);
    if ($offen > 0) { return; }
    $schon = $db->rawOne("SELECT id FROM benachrichtigungen WHERE referenz_typ = 'einsatz' AND referenz_id = ? AND betrieb_id = ? AND empfaenger_typ = 'kunde' AND erstellt_am > DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1", [$einsatzId, $db->tenant()]);
    if ($schon !== null) { return; }
    try { \App\Schedule::notify($einsatzId, 'erledigt'); } catch (\Throwable) {}
}

/** Einen Eintrag der Offline-Warteschlange verarbeiten. */
function syncEintrag(array $e): array
{
    $u = Auth::user(); $db = Database::get();
    $typ = (string) ($e['typ'] ?? '');
    $einsatzId = (int) ($e['einsatz_id'] ?? 0);
    $zugeteilt = $db->rawOne('SELECT 1 FROM einsatz_mitarbeiter WHERE einsatz_id = ? AND mitarbeiter_id = ? AND betrieb_id = ?', [$einsatzId, (int) $u['mitarbeiter_id'], $db->tenant()]);
    if ($zugeteilt === null) {
        throw new \RuntimeException('Einsatz nicht zugeteilt.');
    }
    $zeit = geraetezeit((string) ($e['zeit'] ?? ''));

    if ($typ === 'checkin') {
        $token = (string) ($e['token'] ?? '');
        $o = $db->rawOne('SELECT o.qr_token, o.nfc_token FROM einsaetze e JOIN objekte o ON o.id = e.objekt_id WHERE e.id = ? AND e.betrieb_id = ?', [$einsatzId, $db->tenant()]);
        if ($o === null || !(hash_equals((string) $o['qr_token'], $token) || ($o['nfc_token'] !== null && hash_equals((string) $o['nfc_token'], $token)))) {
            throw new \RuntimeException('Code gehört nicht zum Objekt.');
        }
        $vorhanden = $db->rawOne('SELECT id FROM zeiterfassung WHERE einsatz_id = ? AND mitarbeiter_id = ?', [$einsatzId, (int) $u['mitarbeiter_id']]);
        if ($vorhanden !== null) {
            return ['bereits' => true];
        }
        $id = $db->insert('zeiterfassung', ['einsatz_id' => $einsatzId, 'mitarbeiter_id' => (int) $u['mitarbeiter_id'], 'checkin_zeit' => $zeit, 'checkin_methode' => in_array($e['methode'] ?? '', ['qr', 'nfc'], true) ? $e['methode'] : 'qr']);
        $db->update('einsaetze', $einsatzId, ['status' => 'laeuft']);
        return ['id' => $id, 'checkin_zeit' => $zeit];
    }
    if ($typ === 'checkout') {
        $z = $db->rawOne('SELECT * FROM zeiterfassung WHERE einsatz_id = ? AND mitarbeiter_id = ? AND betrieb_id = ?', [$einsatzId, (int) $u['mitarbeiter_id'], $db->tenant()]);
        if ($z === null) { throw new \RuntimeException('Kein Check-in vorhanden.'); }
        if ($z['checkout_zeit'] !== null) { return ['bereits' => true]; }
        $ein = new DateTimeImmutable((string) $z['checkin_zeit']); $aus = new DateTimeImmutable($zeit);
        if ($aus < $ein) { $aus = $ein; }
        $pause = max(0, min(480, (int) ($e['pause_minuten'] ?? 0)));
        $brutto = (int) round(($aus->getTimestamp() - $ein->getTimestamp()) / 60); $netto = max(0, $brutto - $pause);
        $db->raw('UPDATE zeiterfassung SET checkout_zeit = ?, checkout_methode = ?, pause_minuten = ?, minuten_brutto = ?, minuten_netto = ? WHERE id = ?',
            [$aus->format('Y-m-d H:i:s'), in_array($e['methode'] ?? '', ['qr', 'nfc'], true) ? $e['methode'] : 'qr', $pause, $brutto, $netto, (int) $z['id']]);
        $db->update('einsaetze', $einsatzId, ['status' => 'erledigt']);
        einsatzErledigtMelden($einsatzId);
        return ['minuten_netto' => $netto];
    }
    if ($typ === 'notiz') {
        $db->update('einsaetze', $einsatzId, ['notizen_mitarbeiter' => mb_substr((string) ($e['text'] ?? ''), 0, 2000)]);
        return ['gespeichert' => true];
    }
    throw new \RuntimeException('Unbekannter Eintragstyp.');
}

/** Schreibt Aenderungen ins Protokoll. */
final class AuditLog
{
    public static function write(string $action, string $table, int $recordId, string $description = ''): void
    {
        $db = Database::get();
        $db->raw(
            'INSERT INTO audit_log (betrieb_id, benutzer_id, aktion, tabelle, datensatz_id, beschreibung, ip_adresse)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $db->hasTenant() ? $db->tenant() : null,
                Auth::userId() ?: null,
                $action,
                $table,
                $recordId,
                mb_substr($description, 0, 255),
                Auth::clientIp(),
            ]
        );
    }
}

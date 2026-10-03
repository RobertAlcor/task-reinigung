-- Bringt eine bestehende Datenbank auf den Stand vom 12.09.2026.
-- Enthaelt: Preise, Loeschung nach Vertragsende, Mahnwesen, Web-Push/Kalender.
-- Einfach komplett importieren. Meldet eine Zeile "Duplicate column" oder
-- "Duplicate key" (weil schon vorhanden), einfach ignorieren -- der Rest
-- der Datei laeuft trotzdem durch.

-- ===== Preise 2026 =====
-- Preise 2026: Basis 89 / Plus 149 / Pro 249 (bis 50 Mitarbeiter), Website-Modul 49, Einrichtung einmalig
UPDATE anbieter_tarife SET preis_monat = 89.00,  max_mitarbeiter = 10 WHERE code = 'basis';
UPDATE anbieter_tarife SET preis_monat = 149.00, max_mitarbeiter = 25 WHERE code = 'plus';
UPDATE anbieter_tarife SET preis_monat = 249.00, max_mitarbeiter = 50 WHERE code = 'pro';
ALTER TABLE anbieter_vertraege MODIFY website_preis_monat DECIMAL(8,2) NOT NULL DEFAULT 49.00;
ALTER TABLE anbieter_vertraege ADD COLUMN einrichtung_einmalig DECIMAL(8,2) NOT NULL DEFAULT 0.00;
ALTER TABLE anbieter_vertraege ADD COLUMN einrichtung_verrechnet TINYINT(1) NOT NULL DEFAULT 0;

-- ===== Löschung nach Vertragsende =====
-- Vertragsende und Loeschfrist (Art. 28 DSGVO, AV-Vertrag: Loeschung 30 Tage nach Vertragsende)
ALTER TABLE betriebe ADD COLUMN gekuendigt_am DATE DEFAULT NULL;
ALTER TABLE betriebe ADD COLUMN geloescht_am DATETIME DEFAULT NULL;
CREATE OR REPLACE VIEW v_anbieter_betriebe AS
SELECT
  b.id, b.name, b.slug, b.email, b.tarif, b.max_mitarbeiter, b.status, b.testphase_bis,
  b.av_vertrag_akzeptiert_am, b.erstellt_am, b.gekuendigt_am, b.geloescht_am,
  v.preis_monat, v.website_modul, v.website_preis_monat, v.rabatt_prozent, v.zahlungsweise, v.zahlungsart,
  v.ust_satz, v.vertragsbeginn, v.naechste_abrechnung, v.kuendigung_zum,
  -- effektiver Monatsbetrag netto
  ROUND((IFNULL(v.preis_monat,0) + IF(IFNULL(v.website_modul,0)=1, IFNULL(v.website_preis_monat,0), 0)) * (1 - IFNULL(v.rabatt_prozent,0)/100), 2) AS monatsbetrag_netto,
  (SELECT COUNT(*) FROM mitarbeiter m WHERE m.betrieb_id = b.id AND m.aktiv = 1)      AS mitarbeiter_aktiv,
  (SELECT COUNT(*) FROM objekte o     WHERE o.betrieb_id = b.id AND o.aktiv = 1)      AS objekte_aktiv,
  (SELECT COUNT(*) FROM kunden k      WHERE k.betrieb_id = b.id AND k.status = 'aktiv') AS kunden_aktiv,
  (SELECT MAX(u.letzter_login) FROM benutzer u WHERE u.betrieb_id = b.id)              AS letzter_login,
  (SELECT u.benutzername FROM benutzer u WHERE u.betrieb_id = b.id AND u.rolle = 'inhaber' ORDER BY u.id LIMIT 1) AS inhaber_login,
  (SELECT COUNT(*) FROM einsaetze e WHERE e.betrieb_id = b.id AND e.datum >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS einsaetze_30_tage,
  (SELECT IFNULL(SUM(r.brutto),0) FROM anbieter_rechnungen r WHERE r.betrieb_id = b.id AND r.status = 'offen') AS offen_brutto,
  (SELECT COUNT(*) FROM anbieter_rechnungen r WHERE r.betrieb_id = b.id AND r.status = 'offen' AND r.faellig_am < CURDATE()) AS ueberfaellig_anzahl
FROM betriebe b
LEFT JOIN anbieter_vertraege v ON v.betrieb_id = b.id;

-- ===== Mahnwesen =====
-- Mahnwesen (Zahlungserinnerung, Mahnung) mit Verzugszinsen § 456 UGB und Betreibungskosten § 458 UGB
CREATE TABLE IF NOT EXISTS mahnungen (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id INT UNSIGNED NOT NULL,
  rechnung_id INT UNSIGNED NOT NULL,
  stufe TINYINT NOT NULL,
  datum DATE NOT NULL,
  frist_bis DATE NOT NULL,
  offen_betrag DECIMAL(10,2) NOT NULL,
  verzugstage INT NOT NULL DEFAULT 0,
  zinssatz DECIMAL(5,2) NOT NULL DEFAULT 0,
  verzugszinsen DECIMAL(10,2) NOT NULL DEFAULT 0,
  mahnspesen DECIMAL(8,2) NOT NULL DEFAULT 0,
  gesamt DECIMAL(10,2) NOT NULL,
  pdf_pfad VARCHAR(255) DEFAULT NULL,
  gesendet_am DATETIME DEFAULT NULL,
  erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rechnung (rechnung_id),
  KEY idx_betrieb (betrieb_id),
  CONSTRAINT fk_mahnungen_rechnung FOREIGN KEY (rechnung_id) REFERENCES rechnungen (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE rechnungs_layout ADD COLUMN mahnspesen_1 DECIMAL(8,2) NOT NULL DEFAULT 0.00;
ALTER TABLE rechnungs_layout ADD COLUMN mahnspesen_2 DECIMAL(8,2) NOT NULL DEFAULT 40.00;
ALTER TABLE rechnungs_layout ADD COLUMN verzugszins_prozent DECIMAL(5,2) NOT NULL DEFAULT 9.20;
ALTER TABLE rechnungs_layout ADD COLUMN mahnfrist_tage TINYINT NOT NULL DEFAULT 10;
-- Anbieter: automatische Abrechnung
INSERT IGNORE INTO anbieter_einstellungen (schluessel, wert) VALUES ('abrechnung_automatisch', '0'), ('abrechnung_senden', '0');

-- ===== Web-Push und Kalender =====
-- Web-Push fuer die Mitarbeiter-App und Kalender-Abo
CREATE TABLE IF NOT EXISTS push_abos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id INT UNSIGNED NOT NULL,
  mitarbeiter_id INT UNSIGNED NOT NULL,
  endpoint VARCHAR(500) NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  p256dh VARCHAR(200) DEFAULT NULL,
  auth VARCHAR(100) DEFAULT NULL,
  geraet VARCHAR(120) DEFAULT NULL,
  erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  zuletzt_ok DATETIME DEFAULT NULL,
  fehler INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_endpoint (endpoint_hash),
  KEY idx_mitarbeiter (mitarbeiter_id),
  CONSTRAINT fk_push_mitarbeiter FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE app_nachrichten ADD COLUMN push_am DATETIME DEFAULT NULL;
ALTER TABLE einsatz_anfragen ADD COLUMN push_am DATETIME DEFAULT NULL;
ALTER TABLE benutzer ADD COLUMN kalender_token CHAR(32) DEFAULT NULL;

-- ===== Schnellzugang (Testbetriebe) =====
-- Schnellzugang ohne Passwort: nur fuer Testbetriebe, zum eigenen Testen
-- und zum Einladen von Kolleginnen/Kollegen. Wird vom Anbieter erzeugt,
-- oeffnet das Buero direkt (als Inhaber), ohne Benutzername/Passwort.
CREATE TABLE IF NOT EXISTS anbieter_schnellzugang (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  erstellt_von VARCHAR(80) DEFAULT NULL,
  erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  laeuft_ab DATETIME NOT NULL,
  nutzungen INT UNSIGNED NOT NULL DEFAULT 0,
  letzte_nutzung DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token_hash),
  UNIQUE KEY uq_betrieb (betrieb_id),
  CONSTRAINT fk_schnellzugang_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Anbieter-Benutzer mit Rollen, Passwort vergessen, QR-Login =====
-- Mehrere Anbieter-Benutzer mit Rollen, Passwort-vergessen fuer Anbieter, persoenlicher QR-Login
ALTER TABLE anbieter_benutzer ADD COLUMN rolle ENUM('superuser','tester') NOT NULL DEFAULT 'tester';
UPDATE anbieter_benutzer SET rolle = 'superuser' WHERE id = (SELECT id FROM (SELECT MIN(id) id FROM anbieter_benutzer) x);
CREATE TABLE IF NOT EXISTS passwort_reset_anbieter (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  anbieter_benutzer_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  laeuft_ab DATETIME NOT NULL,
  verwendet_am DATETIME DEFAULT NULL,
  ip_adresse VARCHAR(45) DEFAULT NULL,
  erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS anbieter_login_qr (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  anbieter_benutzer_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  laeuft_ab DATETIME NOT NULL,
  nutzungen INT UNSIGNED NOT NULL DEFAULT 0,
  letzte_nutzung DATETIME DEFAULT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_token (token_hash), UNIQUE KEY uq_benutzer (anbieter_benutzer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Direktzugang Superuser =====
-- Direktzugang des Superusers in Buero, App und Portal eines Betriebs (Testen ohne Login)
CREATE TABLE IF NOT EXISTS anbieter_direktzugang (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) NOT NULL,
  betrieb_id INT UNSIGNED NOT NULL,
  benutzer_id INT UNSIGNED NOT NULL,
  ziel ENUM('buero','app','portal') NOT NULL,
  erstellt_von VARCHAR(80) DEFAULT NULL,
  laeuft_ab DATETIME NOT NULL,
  verwendet_am DATETIME DEFAULT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

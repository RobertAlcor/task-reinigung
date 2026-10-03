-- NUR für eine bestehende Datenbank; steckt auch in AKTUALISIEREN.sql. Bei Neuinstallation NICHT nötig.
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

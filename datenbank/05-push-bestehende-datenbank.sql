-- NUR für eine bestehende Datenbank. Bei Neuinstallation mit 01-datenbank-komplett.sql NICHT nötig.
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

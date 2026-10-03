#!/usr/bin/env python3
"""Passendes FTP-Paket bauen; private Freigabe bleibt außerhalb des ZIPs."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import secrets
import time
import zipfile
from datetime import datetime
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[2]
TTL = 23 * 60 * 60


def build(output: Path, root: Path = ROOT) -> dict:
    output = output.resolve()
    root = root.resolve()
    if output == root or root in output.parents:
        raise ValueError("Ausgabe außerhalb des Repositorys wählen; Reparaturcode bleibt privat.")
    index = (root / "public/index.php").read_bytes()
    if b"config-reparieren.php" not in index:
        raise ValueError("Automatische Weiterleitung fehlt in index.php.")
    template = (root / "tools/config-recovery/recovery.template.php").read_text(encoding="utf-8")
    for marker in ("__ACCESS_HASH__", "__EXPIRES__"):
        if template.count(marker) != 1:
            raise ValueError("Unerwartete Reparaturvorlage.")
    code = secrets.token_urlsafe(24)
    expires = int(time.time()) + TTL
    digest = hashlib.sha256(code.encode()).hexdigest()
    repair = template.replace("__ACCESS_HASH__", digest).replace("__EXPIRES__", str(expires))
    repair = repair.replace("In START-HIER.html eine neue Kopie erzeugen.", "Ein neues, gültiges FTP-Paket anfordern.")
    repair = repair.replace("Zuerst START-HIER.html auf dem Computer öffnen.", "Ein neues, gültiges FTP-Paket anfordern.")
    repair = repair.replace("Den Code aus START-HIER.html übernehmen.", "Den Reparaturcode aus der Download-Nachricht übernehmen.")
    repair = repair.replace("Der Einmalcode aus START-HIER.html, nicht dein FTP-Passwort.", "Der Reparaturcode aus der Download-Nachricht, nicht dein FTP-Passwort.")
    files = {"public/index.php": index, "public/config-reparieren.php": repair.encode("utf-8")}
    assert all(code.encode() not in content for content in files.values())
    output.mkdir(parents=True, exist_ok=True)
    archive = output / "Takt-FTP-Fertigpaket.zip"
    if archive.exists() or (output / "Takt-Zugang-PRIVAT.txt").exists():
        raise FileExistsError("Zielpaket existiert bereits; kein stiller Austausch des Reparaturcodes.")
    with zipfile.ZipFile(archive, "x", compression=zipfile.ZIP_DEFLATED) as z:
        for name, content in files.items():
            z.writestr(name, content)
    until = datetime.fromtimestamp(expires, ZoneInfo("Europe/Vienna")).strftime("%d.%m.%Y, %H:%M")
    private = output / "Takt-Zugang-PRIVAT.txt"
    private.write_text("NICHT AUF DEN SERVER ODER NACH GITHUB LADEN\n\nReparaturcode: " + code
        + "\nGültig bis: " + until + " Uhr Wiener Zeit\n\nZIP entpacken. Den enthaltenen public-Ordner als Ganzes nach "
        + "/html/reinigung/ hochladen und mit dem vorhandenen public-Ordner zusammenführen. Keine Ordner löschen. "
        + "Danach die normale Website öffnen und den Reparaturcode sowie die Datenbank-Zugangsdaten eingeben. "
        + "Keine SQL-Datei importieren. Nach Erfolg Reparaturdatei entfernen und config.php privat sichern.\n", encoding="utf-8")
    private.chmod(0o600)
    manifest = {"purpose": "Konfigurationsreparatur mit automatischem Einstieg; keine Neuinstallation",
        "files": {name: hashlib.sha256(data).hexdigest() for name, data in files.items()},
        "expires": expires, "expires_vienna": until}
    (output / "Paket-Pruefsummen.json").write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    return {"archive": archive, "private": private, "code": code, **manifest}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True)
    result = build(parser.parse_args().output)
    print("ZIP:", result["archive"])
    print("Privater Reparaturcode in:", result["private"])
    print("Gültig bis", result["expires_vienna"], "Uhr Wiener Zeit")

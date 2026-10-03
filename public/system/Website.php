<?php
/**
 * White-Label-Website eines Betriebs.
 *
 * Ein Template (Richtung "Takt"), gefuellt aus website_config, betriebe,
 * leistungsarten und website_fotos. Der Betrieb wird ueber die Domain
 * (website_config.domain) oder zur Vorschau ueber ?betrieb=<slug> bestimmt.
 * Seiten: start, leistungen, ueber-uns, kontakt, impressum, datenschutz.
 */

declare(strict_types=1);

namespace App;

final class Website
{
    public array $b;        // betriebe
    public array $c;        // website_config
    public array $t;        // texte (JSON)
    public array $leistungen;
    public array $fotos;
    private Database $db;

    public static function fuerHost(string $host): ?self
    {
        $host = strtolower(preg_replace('/:\d+$/', '', $host) ?? '');
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $db = Database::get();
        $c = $db->rawOne('SELECT * FROM website_config WHERE aktiv = 1 AND (domain = ? OR domain = ?) LIMIT 1', [$host, 'www.' . $host]);
        return $c ? self::lade((int) $c['betrieb_id'], true) : null;
    }

    public static function fuerSlug(string $slug): ?self
    {
        $db = Database::get();
        $b = $db->rawOne('SELECT id FROM betriebe WHERE slug = ? LIMIT 1', [$slug]);
        return $b ? self::lade((int) $b['id'], false) : null;
    }

    private static function lade(int $betriebId, bool $nurAktiv): ?self
    {
        $db = Database::get();
        $b = $db->rawOne("SELECT * FROM betriebe WHERE id = ? AND status IN ('test','aktiv')", [$betriebId]);
        if ($b === null) { return null; }
        $db->setTenant($betriebId);
        $c = $db->rawOne('SELECT * FROM website_config WHERE betrieb_id = ?', [$betriebId]) ?? ['betrieb_id' => $betriebId, 'aktiv' => 0];
        if ($nurAktiv && (int) ($c['aktiv'] ?? 0) !== 1) { return null; }
        $w = new self();
        $w->db = $db; $w->b = $b; $w->c = $c;
        $w->t = array_merge(self::standardTexte($b), json_decode((string) ($c['texte'] ?? ''), true) ?: []);
        $sichtbar = json_decode((string) ($c['leistungen_sichtbar'] ?? ''), true);
        $w->leistungen = $db->select('leistungsarten', "aktiv = 1 AND kategorie <> 'regie'", [], 'ORDER BY sortierung, name');
        if (is_array($sichtbar) && $sichtbar !== []) { $w->leistungen = array_values(array_filter($w->leistungen, static fn($l) => in_array((int) $l['id'], array_map('intval', $sichtbar), true))); }
        $w->fotos = [];
        foreach ($db->select('website_fotos', '', [], 'ORDER BY bereich, sortierung') as $f) { $w->fotos[$f['bereich']][] = $f; }
        return $w;
    }

    public static function standardTexte(array $b): array
    {
        $ort = $b['ort'] ?: 'Wien';
        return [
            'hero_titel' => 'Immer dieselben Leute, immer dieselbe Zeit.',
            'hero_text' => "Unterhaltsreinigung für Büros und Ordinationen in {$ort}. Sie wissen vorher, wer kommt und wann — und erfahren es sofort, wenn sich etwas ändert.",
            'zahl_1' => '', 'zahl_1_text' => 'Objekte in Betreuung', 'zahl_2' => '', 'zahl_2_text' => 'Jahre Erfahrung', 'zahl_3' => '4 Stunden', 'zahl_3_text' => 'Reaktionszeit, wenn jemand ausfällt',
            'leistungen_titel' => 'Was wir übernehmen, und wie oft.', 'leistungen_text' => 'Für alles andere sagen wir Ihnen ehrlich, wenn jemand anderer besser passt.',
            'nachweis_titel' => 'Am Morgen liegt der Nachweis bereit.', 'nachweis_text' => 'Jede Reinigung wird am Objekt ein- und ausgestempelt und mit Fotos dokumentiert. Sie sehen Zeiten und Ergebnis im Kundenportal, ohne nachfragen zu müssen.',
            'ueber_titel' => 'Über uns', 'ueber_text' => '', 'team_titel' => 'Die Leute, die zu Ihnen kommen.', 'team_text' => 'Kein wechselnder Pool. Jedes Objekt hat sein festes Team und eine Ansprechperson im Büro.',
            'gebiet_titel' => 'Wo wir arbeiten.', 'gebiet_text' => '', 'bezirke' => '',
            'kontakt_titel' => 'Reden wir über Ihr Objekt.', 'kontakt_text' => 'Auf Anfragen antworten wir am selben Werktag.',
            'rechtsform' => '', 'firmenbuch' => '', 'firmenbuchgericht' => '', 'gewerbe' => 'Denkmal-, Fassaden- und Gebäudereinigung', 'behoerde' => 'Magistratisches Bezirksamt', 'kammer' => 'Wirtschaftskammer Wien, Fachgruppe Denkmal-, Fassaden- und Gebäudereiniger', 'inhaber' => '',
            'faq' => "Was kostet die Reinigung?|Der Preis hängt von Fläche, Böden, Sanitäranlagen und Frequenz ab. Nach der Besichtigung bekommen Sie einen Fixpreis pro Monat.\nWas passiert bei Krankheit oder Urlaub?|Wir stellen Ersatz aus dem eigenen Team. Sie bekommen automatisch eine Nachricht, sobald jemand anderer kommt.\nWie kommen Sie ins Objekt?|Schlüssel bei uns im versperrten Schrank, Übergabe durch den Portier oder Zutritt zu Ihren Öffnungszeiten.\nWie lange bin ich gebunden?|Zwölf Monate, danach monatlich kündbar.",
        ];
    }

    // ---------------------------------------------------------------
    // Kontaktformular → anfragen
    // ---------------------------------------------------------------
    public function anfrage(array $post, string $ip): string
    {
        // Honeypot und Mindestzeit gegen Bots
        if (trim((string) ($post['website'] ?? '')) !== '') { return 'Danke, Ihre Anfrage ist eingegangen.'; }
        if ((int) ($post['t'] ?? 0) > time() - 3) { throw new \RuntimeException('Bitte erneut absenden.'); }
        $name = mb_substr(trim((string) ($post['name'] ?? '')), 0, 120);
        $email = trim((string) ($post['email'] ?? ''));
        if ($name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) { throw new \RuntimeException('Bitte Name und eine gültige E-Mail-Adresse angeben.'); }
        $letzte = $this->db->rawOne('SELECT COUNT(*) n FROM anfragen WHERE betrieb_id = ? AND ip_adresse = ? AND erstellt_am > DATE_SUB(NOW(), INTERVAL 10 MINUTE)', [$this->db->tenant(), $ip]);
        if ((int) ($letzte['n'] ?? 0) >= 3) { throw new \RuntimeException('Zu viele Anfragen. Bitte rufen Sie uns an.'); }
        $id = $this->db->insert('anfragen', ['name' => $name, 'firma' => mb_substr(trim((string) ($post['firma'] ?? '')), 0, 150) ?: null, 'email' => $email, 'telefon' => mb_substr(trim((string) ($post['telefon'] ?? '')), 0, 40) ?: null,
            'leistung' => mb_substr(trim((string) ($post['leistung'] ?? '')), 0, 60) ?: null, 'nachricht' => mb_substr(trim((string) ($post['nachricht'] ?? '')), 0, 4000) ?: null, 'status' => 'neu', 'ip_adresse' => $ip]);
        if (!empty($this->b['email'])) {
            $this->db->insert('benachrichtigungen', ['kanal' => 'mail', 'empfaenger_typ' => 'office', 'empfaenger_email' => $this->b['email'], 'betreff' => 'Neue Anfrage über die Website: ' . ($post['firma'] ?: $name),
                'inhalt' => "Name: {$name}\nFirma: " . ($post['firma'] ?? '') . "\nE-Mail: {$email}\nTelefon: " . ($post['telefon'] ?? '') . "\nLeistung: " . ($post['leistung'] ?? '') . "\n\n" . ($post['nachricht'] ?? '') . "\n\nIm Büro unter Kunden → Anfragen.", 'referenz_typ' => 'anfrage', 'referenz_id' => $id]);
        }
        return 'Danke, Ihre Anfrage ist eingegangen. Wir melden uns am selben Werktag.';
    }

    // ---------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------
    public function render(string $seite, ?array $meldung = null, string $basis = '', bool $vorschau = false): string
    {
        $e = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $b = $this->b; $t = $this->t; $c = $this->c;
        $farbe = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($c['farbe_primaer'] ?? $b['farbe_primaer'] ?? '')) ? ($c['farbe_primaer'] ?? $b['farbe_primaer']) : '#0B5D4A';
        $name = $b['name']; $ort = $b['ort'] ?: 'Wien';
        $titel = match ($seite) { 'leistungen' => 'Leistungen', 'ueber-uns' => 'Über uns', 'kontakt' => 'Kontakt', 'impressum' => 'Impressum', 'datenschutz' => 'Datenschutz', default => '' };
        $seoTitel = $seite === 'start' ? ($c['seo_titel'] ?: "Gebäudereinigung {$ort} – {$name}") : "{$titel} – {$name}";
        $seoBeschr = $c['seo_beschreibung'] ?: mb_substr($t['hero_text'], 0, 155);
        $u = static fn(string $p): string => $basis . ($p === 'start' ? '/' : '/' . $p);
        $logo = !empty($b['logo_pfad']) ? '<img src="/uploads/' . $e($b['logo_pfad']) . '" alt="' . $e($name) . '" class="logo">' : '<span class="marke-text">' . $e($name) . '</span>';
        $foto = fn(string $bereich, int $i = 0): ?array => $this->fotos[$bereich][$i] ?? null;
        $bild = static function (?array $f, string $klasse, string $platzText) use ($e): string {
            return $f ? '<img class="' . $klasse . '" src="/uploads/' . $e($f['pfad']) . '" alt="' . $e($f['alt_text'] ?? '') . '" loading="lazy">' : '<div class="' . $klasse . ' platz" aria-hidden="true"><span>' . $e($platzText) . '</span></div>';
        };
        $tel = preg_replace('/\s+/', '', (string) ($b['telefon'] ?? ''));

        $kopf = '<header class="kopf"><a class="marke" href="' . $u('start') . '">' . $logo . '</a><nav class="nav" aria-label="Hauptnavigation"><a href="' . $u('leistungen') . '">Leistungen</a><a href="' . $u('ueber-uns') . '">Über uns</a><a href="' . $u('kontakt') . '">Kontakt</a></nav><a class="knopf" href="' . $u('kontakt') . '">Angebot anfordern</a></header>';
        $fuss = '<footer class="fuss"><div class="fuss-innen"><div><div class="marke-text" style="margin-bottom:10px">' . $e($name) . '</div><p>' . $e(trim(($b['adresse'] ?? '') . ', ' . ($b['plz'] ?? '') . ' ' . $ort, ', ')) . '</p>' . ($b['telefon'] ? '<p><a href="tel:' . $e($tel) . '">' . $e($b['telefon']) . '</a></p>' : '') . ($b['email'] ? '<p><a href="mailto:' . $e($b['email']) . '">' . $e($b['email']) . '</a></p>' : '') . '</div>'
              . '<div><b>Seiten</b><a href="' . $u('leistungen') . '">Leistungen</a><a href="' . $u('ueber-uns') . '">Über uns</a><a href="' . $u('kontakt') . '">Kontakt</a></div><div><b>Rechtliches</b><a href="' . $u('impressum') . '">Impressum</a><a href="' . $u('datenschutz') . '">Datenschutz</a><a href="/portal/">Kundenportal</a></div></div>'
              . '<div class="fuss-unten"><span>© ' . date('Y') . ' ' . $e($name) . '</span><span>handprogrammiert von <a href="https://webdesign-alcor.at" rel="noopener">Webdesign ALCOR</a></span></div></footer>';

        $inhalt = match ($seite) {
            'leistungen' => $this->seiteLeistungen($e, $u, $bild, $foto),
            'ueber-uns'  => $this->seiteUeber($e, $bild, $foto),
            'kontakt'    => $this->seiteKontakt($e, $meldung),
            'impressum'  => $this->seiteImpressum($e),
            'datenschutz' => $this->seiteDatenschutz($e),
            default      => $this->seiteStart($e, $u, $bild, $foto, $meldung),
        };

        $jsonld = json_encode(['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $name, 'url' => $basis ?: ('https://' . ($c['domain'] ?? '')), 'telephone' => $b['telefon'] ?? '', 'email' => $b['email'] ?? '',
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => $b['adresse'] ?? '', 'postalCode' => $b['plz'] ?? '', 'addressLocality' => $ort, 'addressCountry' => 'AT'],
            'areaServed' => $t['bezirke'] ?: $ort, 'makesOffer' => array_map(static fn($l) => ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => $l['name']]], $this->leistungen)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ga = !empty($c['google_analytics_id']) && preg_match('/^G-[A-Z0-9]+$/', (string) $c['google_analytics_id']) ? $this->gaSnippet($e((string) $c['google_analytics_id'])) : '';

        return '<!DOCTYPE html><html lang="de-AT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $e($seoTitel) . '</title><meta name="description" content="' . $e($seoBeschr) . '">'
             . ($vorschau ? '<meta name="robots" content="noindex, nofollow">' : '') . '<link rel="canonical" href="' . $e(($basis ?: 'https://' . ($c['domain'] ?? '')) . ($seite === 'start' ? '/' : '/' . $seite)) . '">'
             . '<link rel="stylesheet" href="https://fonts.bunny.net/css?family=bricolage-grotesque:600,700,800|dm-sans:400,500,600"><link rel="stylesheet" href="/assets/css/web.css"><style>:root{--haus:' . $farbe . '}</style>'
             . '<script>document.documentElement.classList.add("js")</script><script type="application/ld+json">' . $jsonld . '</script></head><body>' . ($vorschau ? '<div class="vorschau-leiste">Vorschau — Website ist ' . ((int) ($c['aktiv'] ?? 0) === 1 ? 'aktiv' : 'noch nicht freigeschaltet') . '</div>' : '')
             . $kopf . '<main>' . $inhalt . '</main>' . $fuss . $ga . '<script>document.querySelectorAll(".auf").forEach(function(el){new IntersectionObserver(function(es,o){es.forEach(function(x){if(x.isIntersecting){x.target.classList.add("da");o.unobserve(x.target)}})},{threshold:.08}).observe(el)});</script></body></html>';
    }

    private function seiteStart(callable $e, callable $u, callable $bild, callable $foto, ?array $meldung): string
    {
        $t = $this->t; $b = $this->b;
        $wochentage = ['Mo', 'Di', 'Mi', 'Do', 'Fr'];
        $zahlen = ''; foreach ([1, 2, 3] as $i) { if (($t["zahl_{$i}"] ?? '') !== '') { $zahlen .= '<div class="zahl"><b>' . $e($t["zahl_{$i}"]) . '</b><span>' . $e($t["zahl_{$i}_text"]) . '</span></div>'; } }
        $leist = ''; $i = 0;
        foreach (array_slice($this->leistungen, 0, 3) as $l) { $leist .= '<article class="leistung">' . $bild($foto('leistungen', $i), 'foto', $l['name']) . '<div class="inhalt"><span class="takt">' . $e(self::taktText($l['kategorie'])) . '</span><h3>' . $e($l['name']) . '</h3><p>' . $e($t['leistung_' . $l['id'] . '_text'] ?? self::leistungText($l['kategorie'])) . '</p></div></article>'; $i++; }
        return '<section class="hero"><div><h1>' . $e($t['hero_titel']) . '</h1><p>' . $e($t['hero_text']) . '</p><div class="hero-tat"><a class="knopf" href="' . $u('kontakt') . '">Besichtigung vereinbaren</a>' . ($b['telefon'] ? '<a class="knopf-2" href="tel:' . $e(preg_replace('/\s+/', '', $b['telefon'])) . '">' . $e($b['telefon']) . '</a>' : '') . '</div></div>'
             . '<div class="woche" aria-hidden="true"><div class="woche-kopf"><span class="obj">Ihr Objekt</span><span class="kw">Beispielwoche</span></div><div class="tage">' . implode('', array_map(static fn($d, $i) => '<div class="tag"><span class="name">' . $d . '</span>' . (in_array($i, [0, 2, 4], true) ? '<div class="block"><div class="zeit">18:00</div><div class="wer">festes Team</div></div>' : '<div class="block frei"><div class="zeit">frei</div></div>') . '</div>', $wochentage, array_keys($wochentage))) . '</div><div class="woche-fuss">So sieht Ihre Woche bei uns aus — immer dieselben Leute, immer dieselbe Zeit.</div></div></section>'
             . ($foto('hero') ? '<div class="bildband auf">' . $bild($foto('hero'), 'foto', '') . '</div>' : '')
             . ($zahlen ? '<div class="zahlen auf">' . $zahlen . '</div>' : '')
             . '<section class="abschnitt auf"><h2>' . $e($t['leistungen_titel']) . '</h2><p class="vor">' . $e($t['leistungen_text']) . '</p><div class="leistungen">' . $leist . '</div><p style="margin-top:18px"><a href="' . $u('leistungen') . '">Alle Leistungen →</a></p></section>'
             . '<section class="nachweis auf"><div class="nachweis-inner"><div><h2>' . $e($t['nachweis_titel']) . '</h2><p>' . $e($t['nachweis_text']) . '</p><a class="knopf-3" href="' . $u('kontakt') . '">Beispiel ansehen</a></div><div class="karte" aria-hidden="true"><div class="titel">Ihr Objekt</div><div class="datum">Freitag · Unterhaltsreinigung</div><div class="z"><span>Angekommen</span><b>18:02</b></div><div class="z"><span>Fertig</span><b>20:11</b></div><div class="z"><span>Team</span><b>A. N., M. P.</b></div><div class="bilder"><div class="foto platz"><span>vorher</span></div><div class="foto platz"><span>nachher</span></div></div></div></div></section>'
             . $this->faq($e)
             . '<section class="kontakt auf" id="kontakt">' . $this->kontaktBlock($e, $meldung) . '</section>';
    }

    private function seiteLeistungen(callable $e, callable $u, callable $bild, callable $foto): string
    {
        $t = $this->t; $h = ''; $i = 0;
        foreach ($this->leistungen as $l) { $h .= '<div class="reihe auf">' . $bild($foto('leistungen', $i), 'foto feld', $l['name']) . '<div><span class="takt">' . $e(self::taktText($l['kategorie'])) . '</span><h2>' . $e($l['name']) . '</h2><p>' . $e($t['leistung_' . $l['id'] . '_text'] ?? self::leistungText($l['kategorie'])) . '</p></div></div>'; $i++; }
        return '<section class="abschnitt seite-kopf"><h1>' . $e($t['leistungen_titel']) . '</h1><p class="vor">' . $e($t['leistungen_text']) . '</p></section><section class="abschnitt" style="padding-top:0">' . $h . '</section><section class="kontakt auf">' . $this->kontaktBlock($e, null) . '</section>';
    }

    private function seiteUeber(callable $e, callable $bild, callable $foto): string
    {
        $t = $this->t; $team = '';
        foreach ($this->fotos['team'] ?? [] as $f) { $team .= '<div class="person">' . $bild($f, 'foto', '') . '<b>' . $e($f['alt_text'] ?? '') . '</b></div>'; }
        return '<section class="abschnitt seite-kopf"><h1>' . $e($t['ueber_titel']) . '</h1>' . ($t['ueber_text'] ? '<div class="prosa">' . nl2br($e($t['ueber_text'])) . '</div>' : '<p class="vor">' . $e($t['hero_text']) . '</p>') . '</section>'
             . ($team ? '<section class="abschnitt auf" style="padding-top:0"><h2>' . $e($t['team_titel']) . '</h2><p class="vor">' . $e($t['team_text']) . '</p><div class="team">' . $team . '</div></section>' : '')
             . ($t['bezirke'] ? '<section class="abschnitt auf" style="padding-top:0"><h2>' . $e($t['gebiet_titel']) . '</h2>' . ($t['gebiet_text'] ? '<p class="vor" style="margin-bottom:12px">' . $e($t['gebiet_text']) . '</p>' : '') . '<div class="bezirke">' . implode('', array_map(static fn($z) => '<span>' . $e(trim($z)) . '</span>', array_filter(explode(',', $t['bezirke'])))) . '</div></section>' : '');
    }

    private function seiteKontakt(callable $e, ?array $meldung): string
    {
        return '<section class="abschnitt seite-kopf"><h1>' . $e($this->t['kontakt_titel']) . '</h1></section><section class="kontakt" style="padding-top:0">' . $this->kontaktBlock($e, $meldung) . '</section>';
    }

    private function kontaktBlock(callable $e, ?array $meldung): string
    {
        $b = $this->b; $t = $this->t;
        $opt = ''; foreach ($this->leistungen as $l) { $opt .= '<option>' . $e($l['name']) . '</option>'; }
        return '<div class="kontakt-inner"><div><h2>' . $e($t['kontakt_titel']) . '</h2><p class="vor" style="margin-bottom:0">' . $e($t['kontakt_text']) . ($this->c['oeffnungszeiten'] ? ' Erreichbar ' . $e($this->c['oeffnungszeiten']) . '.' : '') . '</p><div class="info"><b>' . $e($b['name']) . '</b><br>' . $e(trim(($b['adresse'] ?? '') . ', ' . ($b['plz'] ?? '') . ' ' . ($b['ort'] ?: 'Wien'), ', ')) . ($b['telefon'] ? '<br><a href="tel:' . $e(preg_replace('/\s+/', '', $b['telefon'])) . '">' . $e($b['telefon']) . '</a>' : '') . ($b['email'] ? '<br><a href="mailto:' . $e($b['email']) . '">' . $e($b['email']) . '</a>' : '') . '</div></div>'
             . '<form class="formular" method="post" action="/kontakt#formular" id="formular">' . ($meldung ? '<div class="meldung ' . $e($meldung['typ']) . '">' . $e($meldung['text']) . '</div>' : '') . '<input type="hidden" name="t" value="' . time() . '"><div class="hp"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
             . '<div class="zeile2"><div class="feldg"><label for="k-n">Name</label><input id="k-n" name="name" required autocomplete="name"></div><div class="feldg"><label for="k-f">Firma</label><input id="k-f" name="firma" autocomplete="organization"></div></div>'
             . '<div class="zeile2"><div class="feldg"><label for="k-e">E-Mail</label><input id="k-e" name="email" type="email" required autocomplete="email"></div><div class="feldg"><label for="k-t">Telefon</label><input id="k-t" name="telefon" type="tel" autocomplete="tel"></div></div>'
             . '<div class="feldg"><label for="k-l">Worum geht es?</label><select id="k-l" name="leistung">' . $opt . '<option>Etwas anderes</option></select></div><div class="feldg"><label for="k-m">Nachricht</label><textarea id="k-m" name="nachricht" placeholder="Fläche, Bezirk, gewünschte Tage — je konkreter, desto genauer die Antwort."></textarea></div>'
             . '<button class="knopf" type="submit">Anfrage senden</button><p class="hinweis">Ihre Angaben verwenden wir nur zur Beantwortung dieser Anfrage. Näheres in der <a href="/datenschutz">Datenschutzerklärung</a>.</p></form></div>';
    }

    private function faq(callable $e): string
    {
        $h = '';
        foreach (array_filter(explode("\n", (string) $this->t['faq'])) as $z) { [$f, $a] = array_pad(explode('|', $z, 2), 2, ''); if (trim($f) !== '') { $h .= '<div class="frage"><h3>' . $e(trim($f)) . '</h3><p>' . $e(trim($a)) . '</p></div>'; } }
        return $h ? '<section class="abschnitt auf"><h2>Häufige Fragen.</h2><div class="fragen">' . $h . '</div></section>' : '';
    }

    private function seiteImpressum(callable $e): string
    {
        $b = $this->b; $t = $this->t; $ort = $b['ort'] ?: 'Wien';
        $z = static fn(string $l, string $v) => $v !== '' ? '<dt>' . $e($l) . '</dt><dd>' . $e($v) . '</dd>' : '';
        return '<section class="abschnitt seite-kopf"><h1>Impressum</h1><p class="klein">Angaben gemäß § 5 ECG, § 14 UGB und § 25 MedienG</p></section><section class="abschnitt rechtstext" style="padding-top:0"><dl class="fakten">'
             . $z('Firma', $b['name'] . ($t['rechtsform'] ? ' ' . $t['rechtsform'] : '')) . $z('Inhaber / Geschäftsführung', $t['inhaber']) . $z('Anschrift', trim(($b['adresse'] ?? '') . ', ' . ($b['plz'] ?? '') . ' ' . $ort, ', ')) . $z('Telefon', $b['telefon'] ?? '') . $z('E-Mail', $b['email'] ?? '')
             . $z('Unternehmensgegenstand', $t['gewerbe']) . $z('UID-Nummer', $b['uid_nummer'] ?? '') . $z('Firmenbuchnummer', $t['firmenbuch']) . $z('Firmenbuchgericht', $t['firmenbuchgericht']) . $z('Gewerbebehörde', $t['behoerde']) . $z('Mitglied bei', $t['kammer'])
             . '<dt>Anwendbare Rechtsvorschriften</dt><dd>Gewerbeordnung 1994, abrufbar unter <a href="https://www.ris.bka.gv.at" rel="noopener">ris.bka.gv.at</a></dd>'
             . '<dt>Medieninhaber und Herausgeber</dt><dd>' . $e($b['name']) . ', ' . $e($ort) . '</dd><dt>Blattlinie</dt><dd>Information über das Leistungsangebot des Unternehmens.</dd>'
             . '<dt>Online-Streitbeilegung</dt><dd>Verbraucher haben die Möglichkeit, Beschwerden an die Online-Streitbeilegungsplattform der EU zu richten: <a href="https://ec.europa.eu/consumers/odr" rel="noopener">ec.europa.eu/consumers/odr</a>. Wir sind nicht verpflichtet und nicht bereit, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.</dd>'
             . '<dt>Website</dt><dd>Konzept und Umsetzung: <a href="https://webdesign-alcor.at" rel="noopener">Webdesign ALCOR</a></dd></dl></section>';
    }

    private function seiteDatenschutz(callable $e): string
    {
        $b = $this->b; $ort = $b['ort'] ?: 'Wien'; $ga = !empty($this->c['google_analytics_id']);
        return '<section class="abschnitt seite-kopf"><h1>Datenschutzerklärung</h1></section><section class="abschnitt rechtstext" style="padding-top:0">'
             . '<h2>Verantwortlicher</h2><p>' . $e($b['name']) . ', ' . $e(trim(($b['adresse'] ?? '') . ', ' . ($b['plz'] ?? '') . ' ' . $ort, ', ')) . ($b['email'] ? ', ' . $e($b['email']) : '') . ($b['telefon'] ? ', ' . $e($b['telefon']) : '') . '.</p>'
             . '<h2>Hosting und Zugriffsdaten</h2><p>Diese Website wird bei der easyname GmbH, Fernkorngasse 10/3/501, 1100 Wien, gehostet. Beim Aufruf werden technisch notwendige Daten (IP-Adresse, Datum und Uhrzeit, aufgerufene Seite, Browser) in Server-Protokollen gespeichert und nach 14 Tagen gelöscht. Rechtsgrundlage ist unser berechtigtes Interesse am sicheren Betrieb der Website (Art. 6 Abs. 1 lit. f DSGVO).</p>'
             . '<h2>Kontaktformular</h2><p>Wenn Sie uns über das Formular schreiben, verarbeiten wir Name, E-Mail-Adresse und die weiteren Angaben, um Ihre Anfrage zu beantworten und ein Angebot zu erstellen. Rechtsgrundlage ist die Anbahnung eines Vertrags (Art. 6 Abs. 1 lit. b DSGVO). Die Daten werden in unserer Verwaltungssoftware verarbeitet, die ein Dienstleister in unserem Auftrag auf Servern in Österreich betreibt (Auftragsverarbeitung nach Art. 28 DSGVO). Anfragen, aus denen kein Vertrag entsteht, löschen wir nach sechs Monaten.</p>'
             . '<h2>Schriften</h2><p>Die Website verwendet Schriften von Bunny Fonts (BunnyWay d.o.o., Slowenien), einem datenschutzfreundlichen Dienst ohne Speicherung personenbezogener Daten und ohne Cookies.</p>'
             . '<h2>Cookies</h2><p>' . ($ga ? 'Diese Website setzt nur mit Ihrer Zustimmung Cookies für die Reichweitenmessung (siehe unten). Ohne Zustimmung werden keine Cookies gesetzt.' : 'Diese Website setzt keine Cookies.') . '</p>'
             . ($ga ? '<h2>Google Analytics</h2><p>Mit Ihrer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 165 Abs. 3 TKG 2021) verwenden wir Google Analytics 4 der Google Ireland Ltd., Gordon House, Barrow Street, Dublin 4, Irland, mit IP-Anonymisierung. Die Einwilligung können Sie jederzeit widerrufen, indem Sie die im Browser gespeicherte Auswahl löschen. Google verarbeitet Daten auch in den USA; Grundlage sind die Standardvertragsklauseln der EU und das EU-US Data Privacy Framework.</p>' : '')
             . '<h2>Ihre Rechte</h2><p>Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit und Widerspruch. Wenden Sie sich dazu an die oben genannten Kontaktdaten. Außerdem können Sie sich bei der Datenschutzbehörde, Barichgasse 40–42, 1030 Wien, beschweren.</p>'
             . '<p class="klein">Stand ' . date('m/Y') . '</p></section>';
    }

    private function gaSnippet(string $id): string
    {
        return '<div class="consent" id="consent" hidden><p>Dürfen wir zur Verbesserung der Website anonymisierte Statistiken erheben (Google Analytics)?</p><div><button class="knopf klein" data-c="ja">Ja, einverstanden</button><button class="knopf-2 klein" data-c="nein">Nein</button></div></div>'
             . '<script>(function(){var k="consent-ga",v=localStorage.getItem(k),b=document.getElementById("consent");function lade(){var s=document.createElement("script");s.src="https://www.googletagmanager.com/gtag/js?id=' . $id . '";s.async=true;document.head.appendChild(s);window.dataLayer=window.dataLayer||[];function g(){dataLayer.push(arguments)}g("js",new Date());g("config","' . $id . '",{anonymize_ip:true})}if(v==="ja")lade();else if(v!=="nein"){b.hidden=false;b.querySelectorAll("[data-c]").forEach(function(x){x.onclick=function(){localStorage.setItem(k,x.dataset.c);b.hidden=true;if(x.dataset.c==="ja")lade()}})}})();</script>';
    }

    public static function taktText(string $kat): string
    {
        return match ($kat) { 'unterhalt' => '2–5× pro Woche', 'fenster' => '4× im Jahr', 'grund' => '1–2× im Jahr', default => 'nach Bedarf' };
    }

    public static function leistungText(string $kat): string
    {
        return match ($kat) {
            'unterhalt' => 'Böden, Sanitär, Küche, Papierkorb, Griffflächen. Fixes Team, fixe Zeiten — damit niemand jede Woche neu eingeschult werden muss.',
            'fenster'   => 'Innen und außen bis zur dritten Etage, ohne Hubsteiger. Termin nach Absprache, damit im Besprechungsraum niemand gestört wird.',
            'grund'     => 'Maschinell, inklusive Beschichtung bei Hartböden. Meist am Wochenende, damit der Betrieb nicht steht.',
            default     => 'Nach Umbau, vor Übergabe, nach Wasserschaden. Wir kommen zur Besichtigung und nennen einen Preis, der dann auch hält.',
        };
    }
}

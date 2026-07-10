# Loop-Runbook — Backend Teil 2: Mandanten / MySQL (safe, sequential)

## Ziel & Scope
Server-seitiges Mehr-Mandanten-Backend auf der Proxmox-VM (`192.168.178.207`, Debian 12, LAMP; MariaDB ist installiert, aber noch nicht konfiguriert). **Nur Server/PHP/MySQL** — autonom per **curl** verifizierbar. Die App-Integration (Pairing-Menü, Server-Reihenfolge/Dauer honorieren, Widgets rendern) ist **NICHT** Teil dieses Loops (braucht Emulator/Screenshots → separater interaktiver Schritt danach).

## Modus: safe
- Nach **jedem** Schritt: Gate ausführen; nur bei grün ein Checkpoint-Commit.
- Schlägt derselbe Schritt **2×** fehl → **anhalten** und Mensch fragen.
- Keine Secrets committen (`config.php` bleibt nur auf der VM + gitignored).

## Branch
`feature/backend-mandanten` (von `main`, Baseline `b66dde8`). Loop-Schritt 0 legt ihn an.

## Gate (Stop-Kriterium)
`server/tests/integration.sh` (curl gegen `http://192.168.178.207/teamworkshow/`) endet mit Exit 0 **und** `find server/php -name '*.php' -exec php -l {} \;` fehlerfrei **und** Schema ist angewandt. Der Loop **baut das Testskript mit** und erweitert es je Schritt.
**Iterations-Cap: 12.** Abbruch bei Cap oder Gate-Dauerfehler.

## Externe Abhängigkeiten / Stubs
- OpenWeather-API-Key: aus `config.php` lesen; fehlt er → `weather.php` liefert einen klaren Stub (`{"stub":true}`), Gate bleibt grün.
- VM-Zugang: SSH-Key `~/.ssh/teamworkshow_deploy` (root). DB via `ssh … mariadb`.
- Deploy: `scp` der PHP-Dateien bzw. `scripts/deploy.sh --no-bump`.

## Schritte (je 1 Iteration, mit Verifikation)
0. **Setup**: Branch anlegen. `server/db/schema.sql`, `server/php/db.php` (PDO), `server/php/config.sample.php` (+ `config.php` nur auf VM, gitignored). MariaDB: DB `teamworkshow` + User anlegen, Schema anwenden. _Verify:_ `ssh … "mariadb -e 'show tables' teamworkshow"` zeigt Tabellen.
1. **Schema + Seed**: Tabellen `tenants`, `devices`(pairing_code UNIQUE, tenant_id, name, standort/anzeige_info, last_seen), `presentations`, `slides`(media_name, position, duration_ms), `widget_settings`(device_id, weather_enabled, weather_location, notices_text, notices_enabled, schedule). Seed: 1 Tenant + 1 Device (+Pairing-Code) + Presentation aus vorhandenen Medien. _Verify:_ curl playlist mit Pairing-Code liefert die Seed-Slides.
2. **playlist.php geräte-spezifisch**: `?device=<pairing_code>` → geordnete Slides inkl. `duration_ms` + Widget-Config + Tenant/Anzeige-Info. Ohne `device` → Fallback Ordner-Scan (abwärtskompatibel). _Verify:_ curl mit/ohne device.
3. **Admin-CRUD**: `tenants.php`, `devices.php`, `presentations.php` (Slide-Reihenfolge + Dauer), `widgets.php` (JSON). _Verify:_ curl create/list/update/delete Roundtrip.
4. **Wetter**: `weather.php?device=` — OpenWeather serverseitig, gecacht (Key aus config; sonst Stub). _Verify:_ curl liefert Wetter oder Stub.
5. **Hinweise**: manuelle Notices in `widget_settings`, in playlist/Config-Response gespiegelt. _Verify:_ curl zeigt gesetzte Notice.
6. **Dashboard-Admin + Login**: Multi-Tenant-Verwaltung (Tenants/Geräte/Präsentationen drag-order+Dauer/Widgets) + einfacher Login (Session). _Verify:_ HTTP 200/302-Smoke; visuelle Review durch Mensch später.
7. **Gate finalisieren**: `server/tests/integration.sh` deckt alle Endpunkte ab, grün; `php -l` clean.

## Nach dem Loop (interaktiv, NICHT im Loop)
App: `MediaItem` um `durationMs`/`position`; `PlaylistManager`/`SlideShowController` nutzen Server-Reihenfolge+Dauer; Wartungsmenü „Gerät koppeln" (Pairing-Code); Widgets rendern. Verifikation per Emulator-Screenshots.

## Start
Am besten in einer **frischen Session** (sauberer, günstiger Kontext):
> „Arbeite `.claude/plans/backend-mandanten-runbook.md` autonom ab: ein Schritt pro Iteration, nach jedem Schritt das Gate ausführen, bei grün committen, sonst nach 2 Fehlversuchen anhalten. Stop bei Gate-grün oder 12 Iterationen."

Oder self-paced via `/loop`:
> `/loop Arbeite den nächsten offenen Schritt aus .claude/plans/backend-mandanten-runbook.md ab, dann Gate ausführen und committen.`

## Monitor
- Fortschritt: `git -C <repo> log --oneline feature/backend-mandanten`
- Gate manuell: `bash server/tests/integration.sh`
- DB: `ssh -i ~/.ssh/teamworkshow_deploy root@192.168.178.207 "mariadb -e 'show tables' teamworkshow"`
- Status/Eingriff: `/loop-status`

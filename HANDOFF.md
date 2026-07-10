# TeamworkShow — Session-Handoff (Stand 2026-07-10, v1.0.15)

Kurzeinstieg für eine neue Session. Ziel des Projekts: **Android-Kiosk-/Digital-Signage-App** (Kotlin) + **PHP-Medienserver**. Die App spielt eine Endlos-Slideshow aus einem gerätespezifischen Medienordner, der alle 60 s per Hash vom Server synchronisiert wird.

## Repo & Version
- Pfad: `~/AndroidStudioProjects/TeamworkShow` · Git-Remote: GitHub `schroed99-art/teamworkShow`
- Branch `main`, letzter Commit **`6412862`** (Notice-Ticker konfigurierbar, v1.0.15). Alles zu GitHub **gepusht** (`main` = `origin/main`). Working tree clean.
- Version: Root-Datei `VERSION` (aktuell **1.0.15**, VM meldet 1.0.15). `scripts/deploy.sh` bumpt Patch → baut App → installiert → deployt Server.
- **Standing deploy-OK** (Memory `teamworkshow-autodeploy`): commit→deploy→migrate→smoke→push ohne Rückfrage. Ad-hoc-DB-Mutationen (außerhalb `deploy.sh` + benannter Migrationen) brauchen weiter explizite Freigabe.

## Zuletzt ausgeliefert (2026-07-10, diese Session)
- **v1.0.12** Wetter als Zwischenbild-Slide (eigener Slide-Typ in der Präsentation).
- **v1.0.13** Wetter-Zwischenbild **frei konfigurierbar** (globale Vorlage `weather_layout`-Tabelle): Hintergrund aus Medienpool, Elemente Ort/3-Tage-Vorhersage/Analoguhr/Freitext mit Größe+Position. Editor „🌤 Layout…" im Slide-Editor. Migration `migrate_weather_layout.php` (gelaufen).
- **v1.0.14** Wetter-Layout **Zeilen-Modell**: 8 feste Zeilen (Header,1–6,Footer) statt 3 Bänder; Element wählt Zeile + H-Ausrichtung + Größe → keine Überlappung. Schema unverändert (keine Migration).
- **v1.0.15** **Hinweis-Laufschrift konfigurierbar** (pro Gerät): 3 neue `widget_settings`-Spalten `notices_size`(sp)/`notices_bg`(#AARRGGBB)/`notices_height`(dp). Felder im Geräte-Editor (Schriftgröße, Rahmen-Höhe, Farbe+Deckkraft). Migration `migrate_notice_style.php` (gelaufen auf VM). Marquee lief schon rechts→links; nur Styling war vorher hartkodiert.
- Details je Feature in Memory `teamworkshow-status` (neueste Einträge oben).

## Umgebung (alles per CLI, keine Studio-Dialoge)
- `export JAVA_HOME="/Applications/Android Studio.app/Contents/jbr/Contents/Home"`
- SDK: `~/Library/Android/sdk` · `adb` unter `$SDK/platform-tools/adb`
- Emulator-AVD: **`TeamworkShow_Pixel`** (Pixel 7, API 36, arm64) → `$SDK/emulator/emulator -avd TeamworkShow_Pixel &`
- Bauen/Installieren: `./gradlew installDebug` · Start: `adb shell am start -n com.example.teamworkshow/.SplashActivity`
- Medienordner am Gerät: `/sdcard/Android/data/com.example.teamworkshow/files/media`
- Wartungsmenü: 5× oben rechts tippen (2 s) → PIN **`0000`** → Server einrichten / Jetzt synchronisieren / Medien neu laden / App verlassen
- Server-URL der App (SharedPreferences): aktuell `http://192.168.178.207/teamworkshow` (die VM)

## Server (Staging-VM)
- Debian 12 LXC (`CT103`) auf **192.168.178.207**, LAMP (Apache · PHP 8.2 · **MariaDB konfiguriert**: DB+User `teamworkshow`).
- Deployt unter `/var/www/html/teamworkshow/`: öffentlich `playlist.php`, `media.php`, `upload.php`, `delete.php`, `version.php`, `index.html`, `media/`; Backend `db.php`, `auth.php`, `tenants/devices/presentations/widgets.php`, `weather.php`; Admin `login.php`, `admin.php`; Secrets `config.php` (**nur VM**, gitignored).
- Status-Dashboard (Upload/Löschen + Version): `http://192.168.178.207/teamworkshow/` · **Admin-Dashboard**: `…/admin.php` (Login `login.php`, Passwort in VM-`config.php`).
- Deploy: `scripts/deploy.sh` (deployt nur die öffentlichen PHP). Backend-Dateien per `scp` (Runbook). **OPcache**: nach scp ~3 s warten vor dem Curlen (validate_timestamps=On). SSH-Key `~/.ssh/teamworkshow_deploy` (root).
- Gate: `TW_ADMIN_TOKEN=<admin-pw aus VM-config.php> bash server/tests/integration.sh` → muss `GATE: GREEN` (23 Checks) zeigen. DB: `ssh … "mariadb -e 'show tables' teamworkshow"`.
- Lokaler Mock (ohne PHP): `python3 server/mock/mock_server.py ./media 8080` · Emulator erreicht den Mac über `10.0.2.2`.

## App-Politur (`b66dde8`) — am Emulator verifiziert (Session „Phase 2")
- ✅ Splash → `Show_Splashscreen_v3` (gebrandet, ab ~800 ms)
- ✅ Versions-Label oben rechts, `vX.Y.Z`
- ✅ Dezente Slide-Fortschrittslinie (Magenta, unten) + Transitions
- ✅ „App verlassen" mit Bestätigungsdialog („App verlassen?")
- ⏳ Download-Overlay (`SyncManager.SyncListener`) NICHT verifiziert — braucht laufende Sync (kein Server am Emulator; lokalen Mock nutzen).
- Preloader (`PlaylistManager.peekNext` / `PlayerCallback.onSlideStarted`) ist Hintergrund-Optimierung, visuell nicht direkt prüfbar.

## Splash-Fix (`ab011ca`, v1.0.3) — deployed
- Android 12+ zeigte beim Kaltstart das **grüne Default-Launcher-Icon**. Behoben mit `androidx.core:core-splashscreen`:
  `Theme.TeamworkShow.SplashScreen` (schwarz + `splash_icon.xml` = Teamwork-Logo mit 24dp-Inset + `postSplashScreenTheme`) und `installSplashScreen()` in `SplashActivity`.
- ➜ **Offen (Politur):** `splash_icon` nutzt den Platzhalter `ic_teamwork_logo` — echtes rundes Logo als Vektor hinterlegen.

## Backend Teil 2 (Mandanten/MySQL) — FERTIG & gepusht (`45e51cd`…`529c761`)
- 8 Runbook-Schritte, Gate nach jedem grün. Endpunkte live auf der VM: `playlist.php?device=<pairing>` → geordnete Slides + `duration_ms` + Widgets + Tenant (ohne `device` = Ordner-Fallback); Admin-CRUD (`tenants/devices/presentations`+slides`/widgets.php`, Auth via `X-Admin-Token` **oder** Session); `weather.php` (Stub ohne Key); `login.php`+`admin.php` (Multi-Tenant-Dashboard, Drag-Reihenfolge + Dauer, Widgets — visuell reviewt).
- Schema `server/db/schema.sql`, idempotenter `server/db/seed.php`. Seed-Gerät Pairing **`DEMO-01`** (device_id 1). OpenWeather-Key noch leer → Wetter = Stub.
- Entschieden (umgesetzt): **Wetter per API + Hinweise manuell** · Geräte-Zuordnung per **Pairing-Code** · Exit über den **Wartungs-PIN**.

## App-Integration (Phase 3) — FERTIG (v1.0.4+, alle am Emulator verifiziert)
- ✅ `MediaItem` mit `durationMs`/`position`; `PlaylistManager`/`SlideShowController` honorieren Server-Reihenfolge + Dauer aus `playlist.php?device=<pairing>`.
- ✅ Wartungsmenü **„Gerät koppeln"** (Pairing-Code → `?device=`). ✅ Wetter-/Hinweis-Widgets aus dem `widgets`-Block; Wetter live via `weather.php?device=`.

## Offen / Housekeeping
- 🔐 **Root-Passwort der VM ändern** (wurde früher im Klartext gepostet) — vom Nutzer bewusst zurückgestellt, nur auf ausdrückliche Freigabe angehen.
- 🔑 **Admin-Passwort** (Dashboard-Login) liegt in VM-`config.php` (2026-07-10 regeneriert) — bei Gelegenheit rotieren.
- 🌤️ **OpenWeather-Key** ist in VM-`config.php` eingetragen, gab zuletzt aber HTTP 401 (Neu-Key-Aktivierungs-Lag). Sobald aktiv, geht Wetter automatisch live (kein Deploy nötig). Re-Check: `curl '…/weather.php?device=DEMO-01'` → `stub:false`.
- ✅ Erledigt: Video-Upload-Limits (VM Apache-ini, 256M), Download-Overlay (Mock verifiziert), echtes Splash-Logo (`splash_logo.png`).

## Konventionen
- UI: **nie** natives `alert`/`confirm`/`prompt` → gebrandetes Modal (`confirmDialog` im Dashboard). Schwarz + Magenta `#d81b60`.
- Nach jedem Deploy die Version nennen (App=Gerät vs. Dashboard=Server vergleichbar).

## Projektgedächtnis (wird automatisch geladen)
`teamworkshow-dev-setup`, `teamworkshow-server-architecture`, `teamworkshow-versioning`, `teamworkshow-ui-conventions`, `teamworkshow-status`.

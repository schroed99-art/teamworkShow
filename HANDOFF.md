# TeamworkShow — Session-Handoff (Stand 2026-07-14, v1.0.29)

Kurzeinstieg für eine neue Session. Ziel des Projekts: **Android-Kiosk-/Digital-Signage-App** (Kotlin) + **PHP-Medienserver**. Die App spielt eine Endlos-Slideshow aus einem gerätespezifischen Medienordner, der alle 60 s per Hash vom Server synchronisiert wird.

> Ältere Feature-Abschnitte weiter unten (v1.0.3–v1.0.15) sind Historie; die aktuellen Stände stehen hier oben.

## Repo & Version
- Pfad: `~/Claude/teamworkshow` (Umzug 2026-07-14, vorher `~/AndroidStudioProjects/TeamworkShow`) · Git-Remote: GitHub `schroed99-art/teamworkShow`
- Branch `main`, letzter Commit **`9897ba9`** (upload.php hinter Login). Alles zu GitHub **gepusht** (`main` = `origin/main`). Working tree clean.
- Version: Root-Datei `VERSION` (aktuell **1.0.29**). `scripts/deploy.sh` bumpt Patch → baut App → deployt Server; `scripts/publish-apk.sh` baut signiertes Release + lädt APK in den **privaten** VM-Ordner.
- **Standing deploy-OK** (Memory `teamworkshow-autodeploy`): commit→deploy→migrate→smoke→push ohne Rückfrage. Ad-hoc-DB-Mutationen (außerhalb `deploy.sh` + benannter Migrationen) brauchen weiter explizite Freigabe.

## ⚠️ Paket umbenannt: `com.example.teamworkshow` → **`de.teamworkshow.app`**
- Alle `adb`/Pfad-Befehle nutzen jetzt **`de.teamworkshow.app`** (nicht mehr `com.example…`).
- Medienordner am Gerät: `/sdcard/Android/data/de.teamworkshow.app/files/media`
- Start: `adb shell am start -n de.teamworkshow.app/.SplashActivity`
- **Coexistenz-Falle:** Wegen der Umbenennung können altes + neues Paket parallel installiert sein und sich **nicht** gegenseitig per In-App-Update aktualisieren. Über die Umbenennung hinweg ist **einmalig** ein manuelles `adb install` / Sideload nötig; danach laufen Updates wieder via `apk.php`.

## Zuletzt ausgeliefert (2026-07-13/14, diese Session)
Großer UI-/Feature-Block — Details je Punkt in Memory `teamworkshow-status` (neueste oben):
- **Web-Dashboard + Login restyled** auf Lead-Manager-CI (slate/rose `#0F172A/#1E293B/#334155`, Akzent **`#D21A55`**, Text `#F1F5F9/#94A3B8`) + Logo-Wasserzeichen; Favicon/Tab-Icon (`assets/favicon.png` etc.). Android-App bewusst ausgenommen.
- **In-App-Einstellmenü** (versteckter Trigger 5× oben rechts / MENU-Taste → PIN `0000`): Beenden · Hilfe & Kontakt (8 Felder zentral vom Server) · App aktualisieren · Speicherort wählen (fester Pfad, `MANAGE_EXTERNAL_STORAGE`) · Logs exportieren. Alle Dialoge gebrandet (Material3 `ThemeOverlay.TeamworkShow.Dialog`).
- **Login-gated APK-Download:** APK liegt **außerhalb** des Webroots (`/var/www/teamworkshow-apk`), Auslieferung nur via `apk.php` (Dashboard-Session **oder** gültiger Pairing-Code `?device=CODE`). `app_update.json` bleibt öffentlich (nur Metadaten). Eigene Kachel „App-Installation" unter Geräte → `download.php` (login-gated).
- **Globale Einstellungen-Seite** (`einstellungen.php`, volle Breite): Hilfe-&-Kontakt-Felder (4-Spalten-Grid) + Benutzerverwaltung; Header mit Account-Menü (`nav_user.php`: Passwort ändern / Abmelden).
- **Admin-Slides als Master-Detail** (Liste ↔ Editor, Tab-Leiste bleibt oben). **Laufschrift** in eigenen Abschnitt getrennt + Schriftart/Schriftfarbe/Geschwindigkeit (`notices_font/color/speed`). Ticker-Truncation behoben (voller Text scrollt).
- **Slide-Editor: direkter Bild-/Video-Upload** (analog Medienpool), Auto-Refresh + Vorauswahl.
- **`upload.php` jetzt hinter Login** (`tw_require_manage()`, 401 ohne Auth) — letzter offener ungeschützter Schreib-Endpoint geschlossen.

## Migrationen dieser Session (auf VM gelaufen)
- `migrate_app_settings.php` (Key/Value-Tabelle `app_settings`, seedet `help_*`), `migrate_notice_font.php` (`widget_settings`: `notices_font/notices_color/notices_speed`). Idempotent.

## Umgebung (alles per CLI, keine Studio-Dialoge)
- `export JAVA_HOME="/Applications/Android Studio.app/Contents/jbr/Contents/Home"`
- SDK: `~/Library/Android/sdk` · `adb` unter `$SDK/platform-tools/adb`
- Emulator-AVD: **`TeamworkShow_Pixel`** (Serial `emulator-5554`, Pairing `550-3B4`) → `$SDK/emulator/emulator -avd TeamworkShow_Pixel &`. Physisches Handy: Serial `TK02260501832`, Pairing `136-A54`.
- Bauen: `./gradlew :app:compileDebugKotlin` / `:app:assembleRelease` · Installieren: `./gradlew installDebug` · Start s.o.
- Wartungsmenü: 5× oben rechts tippen (2 s) → PIN **`0000`**. (adb-Tap-Automatik muss on-device als Shell-Loop laufen, um das 2-s-Fenster zu treffen.)
- Server-URL der App (SharedPreferences `teamworkshow_settings`): `http://192.168.178.207/teamworkshow` (die VM)

## Server (Staging-VM)
- Debian 12 LXC (`CT103`) auf **192.168.178.207**, LAMP (Apache · PHP 8.2 · **MariaDB konfiguriert**: DB+User `teamworkshow`).
- Deployt unter `/var/www/html/teamworkshow/`: öffentlich `playlist.php`, `media.php`, `version.php`, `app_update.php`/`app_update.json`, `index.html`, `media/`; **login-gated** `upload.php`, `delete.php`, `apk.php`, `download.php`; Backend `db.php`, `auth.php`, `tenants/devices/presentations/widgets.php`, `weather.php`, `settings.php`, `nav_user.php`, `apk_path.php`; Admin `login.php`, `admin.php`, `einstellungen.php`, `benutzer.php`; Secrets `config.php` (**nur VM**, gitignored). **APK selbst liegt außerhalb des Webroots** unter `/var/www/teamworkshow-apk/app-release.apk` (nur via `apk.php`).
  - `apk.php`/`upload.php`/`delete.php`/`widgets.php` nutzen `require_once auth.php` (auth.php `require_once`t db.php bereits — sonst Redeclare-Fatal/HTTP 500).
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
- ✅ Erledigt: Video-Upload-Limits (VM Apache-ini, 256M), Download-Overlay (Mock verifiziert), echtes Splash-Logo (`splash_logo.png`), Upload-Endpoint hinter Login.
- 💡 Nutzer-Hinweis (v1.0.15): „es kommen noch weitere Konfigurationen hinzu" für die Laufschrift — keine konkrete nächste genannt, auf explizite Ansage warten.

## Konventionen
- UI: **nie** natives `alert`/`confirm`/`prompt` → gebrandetes Modal (`confirmDialog` im Dashboard, `MaterialAlertDialogBuilder` in der App). Dashboard-CI slate/rose, Akzent **`#D21A55`**.
- **Kann keine eingeloggten Dashboard-Seiten screenshotten** (würde Login-Eingabe erfordern → verboten). Verifikation dort per `wget`/`php -l` auf der VM; App-Änderungen per Emulator-Screenshot.
- Sicherheit: Keystore + Passwörter sind Nutzer-Credentials — **nicht** anfassen/committen (`*.jks`, `keystore.properties` gitignored). Admin-PW nur in VM-`config.php`. In-place-APK-Update braucht **gleiches Paket + gleichen Release-Key**.
- Nach jedem Deploy die Version nennen (App=Gerät vs. Dashboard=Server vergleichbar).

## Projektgedächtnis (wird automatisch geladen)
`teamworkshow-dev-setup`, `teamworkshow-server-architecture`, `teamworkshow-versioning`, `teamworkshow-ui-conventions`, `teamworkshow-status`.

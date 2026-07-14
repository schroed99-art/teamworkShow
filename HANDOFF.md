# TeamworkShow — Session-Handoff (Stand 2026-07-14, v1.0.40)

Kurzeinstieg für eine neue Session. Ziel des Projekts: **Android-Kiosk-/Digital-Signage-App** (Kotlin) + **PHP-Medienserver**. Die App spielt eine Endlos-Slideshow aus einem gerätespezifischen Medienordner, der alle 60 s per Hash vom Server synchronisiert wird.

> Ältere Feature-Abschnitte weiter unten (v1.0.3–v1.0.15) sind Historie; die aktuellen Stände stehen hier oben.

## Repo & Version
- Pfad: `~/Claude/teamworkshow` (Umzug 2026-07-14, vorher `~/AndroidStudioProjects/TeamworkShow`) · Git-Remote: GitHub `schroed99-art/teamworkShow`
- Branch `main`. Alles zu GitHub **gepusht** (`main` = `origin/main`).
- Version: Root-Datei `VERSION` (aktuell **1.0.40**). `scripts/deploy.sh` bumpt Patch → baut App → deployt Server; `scripts/publish-apk.sh` baut signiertes Release + lädt APK in den **privaten** VM-Ordner.
- **Standing deploy-OK** (Memory `teamworkshow-autodeploy`): commit→deploy→migrate→smoke→push ohne Rückfrage. Ad-hoc-DB-Mutationen (außerhalb `deploy.sh` + benannter Migrationen) brauchen weiter explizite Freigabe.

## ⚠️ Paket umbenannt: `com.example.teamworkshow` → **`de.teamworkshow.app`**
- Alle `adb`/Pfad-Befehle nutzen jetzt **`de.teamworkshow.app`** (nicht mehr `com.example…`).
- Medienordner am Gerät: `/sdcard/Android/data/de.teamworkshow.app/files/media`
- Start: `adb shell am start -n de.teamworkshow.app/.SplashActivity`
- **Coexistenz-Falle:** Wegen der Umbenennung können altes + neues Paket parallel installiert sein und sich **nicht** gegenseitig per In-App-Update aktualisieren. Über die Umbenennung hinweg ist **einmalig** ein manuelles `adb install` / Sideload nötig; danach laufen Updates wieder via `apk.php`.

## Phase 5 — Roadmap & Stand (2026-07-14)
Geplant in 4 Schritten: **5.1 Multi-Format → 5.2 Mandanten-Self-Service → 5.3 Bildschirm-Zonen → 5.4 Nachrichten.**

**5.1 Multi-Format — FERTIG (v1.0.30, am Emulator verifiziert).**
- Neues Feld `devices.display_format` (`portrait|phone|landscape|tablet`, Whitelist serverseitig, Migration `migrate_device_format.php`), im Dashboard pro Gerät wählbar, via `playlist.php` → 60-s-Sync in die App.
- App setzt `requestedOrientation` zur Laufzeit; `layout-land/`, `values-land/`, `values-sw600dp/`, `dimens.xml`. Formatwechsel greift innerhalb eines Sync-Intervalls (Activity wird neu erstellt — bewusst akzeptiert).
- **Nicht verifiziert:** das Tablet-Format (`values-sw600dp`) — es existiert kein Tablet-AVD.

**5.2 Mandanten-Self-Service — FERTIG (v1.0.32).**
- `users.tenant_id` (NULL = interner Staff/global) + Rolle **`kunde`** (Migration `migrate_user_tenant.php`).
- **Mandantentrennung liegt zentral in `auth.php`**: `tw_current_tenant_id()` (DB-gelesen, nicht Session — Umbindung wirkt sofort), `tw_require_tenant()`, `tw_tenant_filter()`, `tw_owning_tenant()`, `tw_require_staff()` vs. `tw_require_manage()`. **Endpoints implementieren die Prüfung nicht selbst nach** — genau eine Stelle kann falsch sein.
- Kunde darf: eigene Präsentationen/Slides, eigene Medien (Upload/Löschen), Laufschrift + Wetter am eigenen Gerät, und sein Gerät auf eine **eigene** Präsentation zeigen lassen. Kunde darf **nicht**: Mandanten/Geräte/Benutzer anlegen oder löschen, globale Einstellungen oder Wetter-Layout ändern.
- **Kundenansicht:** `admin.php` läuft im reduzierten Modus (`IS_KUNDE`), `overview.php` ist die Landeseite. Beide Seiten fragen die DB direkt ab und wenden den Mandantenfilter **selbst** an — dasselbe gilt für `status.php`. Wer hier eine neue SQL-Abfrage ergänzt, muss `tw_tenant_filter()` mitziehen.
- Dabei geschlossen: **`delete.php` war komplett unauthentifiziert** (jeder im Netz konnte Medien löschen) und `upload.php` überschrieb fremde Dateien bei Namensgleichheit.
- Verifiziert mit zwei Angriffs-Proben gegen einen echten Kundenlogin: 27/27 auf den JSON-Endpoints, 14/14 auf den HTML-Seiten.
- **Zugänge (v1.0.34):** Kundenlogins werden **beim Mandanten** angelegt — `admin.php` → Mandant → Tab **„Zugänge"** (Anlegen mit generiertem Temp-Passwort, Zurücksetzen, Aktiv/Inaktiv, Löschen). `users.php` GET kennt dafür `?tenant_id=` und liefert `tenant_name` mit. Die zentrale `benutzer.php` blendet die Rolle `kunde` bewusst aus: sie hätte keinen Mandanten-Wähler, und ihre Rollenauswahl kennt `kunde` nicht — ein Bearbeiten dort würde den Kunden stillschweigend degradieren und seine Mandantenbindung löschen.
- **Selbstverwaltung (v1.0.34):** ein Kunde verwaltet sein eigenes Team im selben „Zugänge"-Tab (anlegen, Passwort zurücksetzen, aktiv/inaktiv, löschen). `users.php` läuft dafür auf `tw_require_manage()`; die drei Ausbruchswege sind je **einmal** geschlossen: `tw_scope_target()` (fremde Nutzer — Staff hat `tenant_id NULL` und fällt damit automatisch raus), `tw_scope_role()` (nur Rolle `kunde` zuweisbar) und `tw_owning_tenant()` (Mandant nicht verschiebbar). Zusätzlich: niemand deaktiviert/löscht das Konto, mit dem er angemeldet ist.
- Verifiziert: 29/29 Angriffs-/Funktionsprobe auf die Selbstverwaltung; Regression 28/28 (Endpoints) + 15/15 (HTML-Seiten).
- **`media/` ist bewusst keine Vertraulichkeitsgrenze:** `media.php` bleibt unauthentifiziert (ein Signage-Gerät hat keinen Login), Dateinamen sind also erratbar. Kunden können sich gegenseitig aber nicht auflisten, überschreiben oder löschen.

**5.3 Bildschirm-Zonen — FERTIG (v1.0.37, am Emulator verifiziert).**
- Pro Gerät: `zone_mode` (`single|split`), `zone_axis` (`rows` = übereinander, `cols` = nebeneinander), `zone_split` (Anteil der Firmen-Zone in %, 10–90) und `company_presentation_id` (Migration `migrate_device_zones.php`). Alles **staff-only** — der Kunde behält allein seine `presentation_id`, die im Split seine eigene Zone füllt.
- Die **Firmen-Präsentation darf aus einem anderen Mandanten kommen** (sie trägt unsere Werbung) — das ist die eine bewusste Ausnahme von der Mandantentrennung und steht so in `devices.php`.
- `playlist.php` liefert `zones: {mode, axis, split, company[], customer[]}`; **`items` bleibt die flache Vereinigung** und ist weiterhin das, was die App herunterlädt und per Hash vergleicht (eine von beiden Zonen genutzte Datei erscheint dort genau einmal). In `single` ist `zones` null → alter Vertrag unverändert.
- **App-Umbau:** neue Klasse `player/Stage.kt` = eine Bühne mit eigenen Views (`res/layout/zone_stage.xml`, zweimal per `<include>` eingebunden), eigenem ExoPlayer, eigener `PlaylistManager` + `SlideShowController`. `MainActivity` ist nicht mehr `PlayerCallback`, sondern hält 1–2 Stages; Laufschrift, Overlays, Wetter-**Inhalt** und Sync bleiben pro Gerät.
  - **Achtung:** die IDs in `zone_stage.xml` sind pro Fenster **doppelt**. Immer über die Wurzel des `<include>` suchen (`stage.root.findViewById`), **nie** `Activity.findViewById` — sonst trifft man stumm die falsche Zone.
  - Im Zonenmodus scannt `PlaylistManager` den Medienordner **nicht** (`itemsProvider`): der Ordner ist geteilt, eine Zone darf nur ihre eigenen Slides spielen.
- Eine geänderte Zonen-Aufteilung erzeugt die Activity neu (`zoneLayoutSignature`) — derselbe Weg wie beim Formatwechsel, greift innerhalb eines Sync-Intervalls ohne Neustart.
- Verifiziert: 20/20 Backend-Probe; am Emulator 60/40 übereinander, Livewechsel auf 50/50 nebeneinander und zurück auf eine Fläche (dort weiterhin inkl. Wetter-Zwischenbild).

**5.4 Nachrichten — FERTIG (v1.0.40, am Emulator verifiziert).**
- Neue Slide-Art **`kind='news'`** (Migration `migrate_slide_news.php`: `slides.kind`-ENUM um `news` erweitert, `slides.text_title` + `slides.text_body`). Eine Nachricht ist **dateilos** wie das Wetter-Zwischenbild und trägt ihren Text selbst.
- **Warum das die Zonen automatisch löst:** eine Slide gehört zu einer Präsentation, eine Präsentation zu einer Zone. Firma und Kunde schreiben damit je in ihr eigenes Board, ohne dass es dafür ein zweites Datenmodell braucht.
- Dashboard: im Slide-Editor **„+ 📰 Nachricht"**; Überschrift und Text werden direkt in der Slide-Zeile bearbeitet, Reihenfolge und Dauer wie bei jeder anderen Slide. Eine Nachricht **ohne jeden Text wird beim Speichern verworfen** (sonst stünde ein leeres Board auf dem Bildschirm).
- App: `MediaType.NEWS` + `NewsSlide`, gerendert in `zone_stage.xml` (`newsView`) — Magenta-Akzent, Titel + Text mit `autoSizeTextType`, damit dieselbe Nachricht im Vollbild wie in einer schmalen Zone lesbar bleibt.
- **`items` ist die Download-Liste, nicht die Playlist** — der Unterschied zählt jetzt: in `single` steht dort auch das Dateilose (die App baut ihre Show genau daraus), in `split` ausschließlich echte Dateien (die Show steht in `zones`). Wer hier etwas ergänzt, muss beide Fälle bedenken.
- Die Laufschrift bleibt bewusst **geräteweit** (unter beiden Zonen) — sie gehört dem Gerät, nicht einer Zone.
- Verifiziert: 19/19 Backend-Probe (u. a. Kunde schreibt sein eigenes Board → 200, fremdes → 403); am Emulator je eine eigene Nachricht in Firmen- und Kunden-Zone.

**Phase 5 ist damit abgeschlossen.**

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

# TeamworkShow — Session-Handoff (Stand 2026-07-10)

Kurzeinstieg für eine neue Session. Ziel des Projekts: **Android-Kiosk-/Digital-Signage-App** (Kotlin) + **PHP-Medienserver**. Die App spielt eine Endlos-Slideshow aus einem gerätespezifischen Medienordner, der alle 60 s per Hash vom Server synchronisiert wird.

## Repo & Version
- Pfad: `~/AndroidStudioProjects/TeamworkShow` · Git-Remote: GitHub `schroed99-art/teamworkShow`
- Branch `main`, letzter Commit **`ab011ca`** (Android-12-Splash-Fix).
- Version: Root-Datei `VERSION` (aktuell **1.0.3**). `scripts/deploy.sh` bumpt Patch → baut App → installiert → deployt Server.

## Umgebung (alles per CLI, keine Studio-Dialoge)
- `export JAVA_HOME="/Applications/Android Studio.app/Contents/jbr/Contents/Home"`
- SDK: `~/Library/Android/sdk` · `adb` unter `$SDK/platform-tools/adb`
- Emulator-AVD: **`TeamworkShow_Pixel`** (Pixel 7, API 36, arm64) → `$SDK/emulator/emulator -avd TeamworkShow_Pixel &`
- Bauen/Installieren: `./gradlew installDebug` · Start: `adb shell am start -n com.example.teamworkshow/.SplashActivity`
- Medienordner am Gerät: `/sdcard/Android/data/com.example.teamworkshow/files/media`
- Wartungsmenü: 5× oben rechts tippen (2 s) → PIN **`0000`** → Server einrichten / Jetzt synchronisieren / Medien neu laden / App verlassen
- Server-URL der App (SharedPreferences): aktuell `http://192.168.178.207/teamworkshow` (die VM)

## Server (Staging-VM)
- Debian 12 LXC (`CT103`) auf **192.168.178.207**, LAMP (Apache · PHP 8.2 · MariaDB installiert, noch nicht konfiguriert).
- Deployt unter `/var/www/html/teamworkshow/`: `playlist.php`, `media.php`, `upload.php`, `delete.php`, `version.php`, `index.html`, `media/`.
- Dashboard (read-only Status + Upload/Löschen + Version-Chip): `http://192.168.178.207/teamworkshow/`
- Deploy: `scripts/deploy.sh` · SSH-Key `~/.ssh/teamworkshow_deploy` (root, key-basiert).
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

## Nächster großer Block: Backend Teil 2 (Loop bereit)
- **Runbook:** `.claude/plans/backend-mandanten-runbook.md` (sequential, safe, 7 Schritte, curl-Gate, Cap 12, Branch `feature/backend-mandanten`).
- Entschieden: **Wetter per API + Hinweise manuell** · Geräte-Zuordnung per **Pairing-Code** · Exit über den **vorhandenen Wartungs-PIN**.
- **Loop starten (frische Session):** „Arbeite `.claude/plans/backend-mandanten-runbook.md` autonom ab: ein Schritt pro Iteration, nach jedem Schritt das Gate ausführen, bei grün committen, sonst nach 2 Fehlversuchen anhalten. Stop bei Gate-grün oder 12 Iterationen."
- App-Integration (Pairing-Menü, Server-Reihenfolge/Dauer honorieren, Widgets rendern) ist **nach** dem Loop interaktiv (Emulator/Screenshots).

## Offen / Housekeeping
- 🔐 **Root-Passwort der VM ändern** (wurde früher im Klartext gepostet).
- 🎬 Video-Upload braucht höhere PHP-Limits auf der VM (`upload_max_filesize`/`post_max_size`).
- 🌤️ **OpenWeather-API-Key** für das Wetter-Widget besorgen.
- ⏳ Download-Overlay per lokalem Mock-Server verifizieren (einziger noch offener Politur-Screen).
- 🖼️ Echtes rundes Logo als Vektor für `splash_icon` / `ic_teamwork_logo` hinterlegen.

## Konventionen
- UI: **nie** natives `alert`/`confirm`/`prompt` → gebrandetes Modal (`confirmDialog` im Dashboard). Schwarz + Magenta `#d81b60`.
- Nach jedem Deploy die Version nennen (App=Gerät vs. Dashboard=Server vergleichbar).

## Projektgedächtnis (wird automatisch geladen)
`teamworkshow-dev-setup`, `teamworkshow-server-architecture`, `teamworkshow-versioning`, `teamworkshow-ui-conventions`, `teamworkshow-status`.

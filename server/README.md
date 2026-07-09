# Teamwork Show – Media Server

The app syncs its media from a server that exposes two endpoints:

| Endpoint | Response |
|---|---|
| `GET {base}/playlist.php` | `{ "items": [ { "name", "hash", "size" }, ... ] }` (`hash` = SHA-256 hex) |
| `GET {base}/media.php?name=<file>` | raw file bytes |

The app polls `playlist.php`, downloads any new/changed files (compared by hash),
deletes files no longer listed, and reloads the slideshow.

Supported types: `jpg jpeg png webp mp4`.

## Step 1 (current): folder-scan, no database
Both backends simply scan a `media/` folder. Drop files in, they appear.

### Local test with the Python mock (no PHP needed)
```bash
cd server/mock
python3 mock_server.py ./media 8080     # serves ./media on port 8080
# put images/videos into server/mock/media/
```
In the app: open the maintenance menu (5× tap top-right corner → PIN `0000`) →
**Server einrichten** → enter `http://10.0.2.2:8080` (emulator → host machine).
The app syncs immediately and then every 60 s. Add/remove a file in
`server/mock/media/` to watch it sync.

### Production deploy on All-Inkl (PHP + SFTP/SSH)
1. Upload `server/php/playlist.php` and `server/php/media.php` into a web folder,
   e.g. `https://<domain>/teamworkshow/`.
2. Create a `media/` subfolder next to them and upload media into it.
3. In the app, set the server URL to `https://<domain>/teamworkshow` (no trailing
   slash, no `/playlist.php`).

> Use `https://` in production (All-Inkl provides SSL). `usesCleartextTraffic`
> is only enabled for local `http://` LAN testing.

## Step 2 (next): MySQL
Replace the folder scan with a MySQL-backed playlist for ordering, active/inactive
flags, scheduling and (later) multiple devices/playlists. The HTTP contract stays
the same, so no app change is required.

#!/usr/bin/env python3
"""
Local test mock for the Teamwork Show media server.

Mirrors the exact HTTP contract of the PHP backend so the Android app can be
tested without deploying anything:

    GET /playlist.php        -> { "items": [ { "name", "hash", "size" }, ... ] }
    GET /media.php?name=NAME -> raw file bytes

Usage:
    python3 mock_server.py [MEDIA_DIR] [PORT]

Defaults: MEDIA_DIR=./media  PORT=8080
The Android emulator reaches the host machine at http://10.0.2.2:<PORT>.
"""
import hashlib
import json
import mimetypes
import os
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

ALLOWED = {"jpg", "jpeg", "png", "webp", "mp4"}
MEDIA_DIR = os.path.abspath(sys.argv[1]) if len(sys.argv) > 1 else os.path.abspath("media")
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 8080


def sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(8192), b""):
            h.update(chunk)
    return h.hexdigest()


def build_playlist():
    items = []
    if os.path.isdir(MEDIA_DIR):
        for name in sorted(os.listdir(MEDIA_DIR), key=str.lower):
            path = os.path.join(MEDIA_DIR, name)
            if not os.path.isfile(path):
                continue
            ext = name.rsplit(".", 1)[-1].lower() if "." in name else ""
            if ext not in ALLOWED:
                continue
            items.append({"name": name, "hash": sha256(path), "size": os.path.getsize(path)})
    return {"items": items}


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path in ("/", "/index.html"):
            self._send_frontend()
        elif parsed.path.endswith("playlist.php"):
            self._send_json(build_playlist())
        elif parsed.path.endswith("media.php"):
            self._send_media(parse_qs(parsed.query).get("name", [""])[0])
        else:
            self.send_error(404, "Not found")

    def _send_frontend(self):
        # Serve the same index.html that deploys to All-Inkl (lives in server/php/).
        path = os.path.join(os.path.dirname(__file__), "..", "php", "index.html")
        try:
            with open(path, "rb") as f:
                body = f.read()
        except OSError:
            self.send_error(404, "index.html not found")
            return
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _send_json(self, obj):
        body = json.dumps(obj).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _send_media(self, name):
        # Reject anything that could escape the media directory.
        if not name or "/" in name or "\\" in name or ".." in name:
            self.send_error(400, "Bad name")
            return
        path = os.path.join(MEDIA_DIR, name)
        if not os.path.isfile(path):
            self.send_error(404, "Not found")
            return
        ctype = mimetypes.guess_type(path)[0] or "application/octet-stream"
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(os.path.getsize(path)))
        self.end_headers()
        with open(path, "rb") as f:
            self.wfile.write(f.read())

    def log_message(self, fmt, *args):
        print(f"[mock] {self.address_string()} {fmt % args}")


if __name__ == "__main__":
    os.makedirs(MEDIA_DIR, exist_ok=True)
    print(f"Serving media from {MEDIA_DIR} on http://0.0.0.0:{PORT}")
    print(f"  Emulator URL: http://10.0.2.2:{PORT}")
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()

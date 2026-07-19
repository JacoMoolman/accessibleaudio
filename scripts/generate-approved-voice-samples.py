#!/usr/bin/env python3
"""Generate issue #38's approved samples through the running AudioBookMaker app.

The generated archive contains the original local samples plus the retained
cloud samples. The public site exposes only the 30 retained cloud samples and
renumbers that sequence as "Voice 1" through "Voice 30".
"""

from __future__ import annotations

import argparse
import json
import re
import shutil
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


ROOT = Path(__file__).resolve().parents[1]
DIALOGUES = ROOT / "docs" / "voice-sample-dialogues.md"
AUDIOBOOKMAKER = Path(r"G:\Projects\AUDIOBOOK\AudioBookMaker")
PREVIEWS = AUDIOBOOKMAKER / "previews"
OUTPUT = ROOT / "assets" / "voice-samples" / "catalog"
MANIFEST = ROOT / "docs" / "voice-sample-generation-manifest.json"
APP = "http://127.0.0.1:3250"


def post_json(path: str, payload: dict, timeout: int = 1800) -> dict:
    request = Request(
        APP + path,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urlopen(request, timeout=timeout) as response:
            body = response.read().decode("utf-8")
            if not 200 <= response.status < 300:
                raise RuntimeError(f"{path} returned HTTP {response.status}: {body}")
    except HTTPError as error:
        body = error.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"{path} returned HTTP {error.code}: {body}") from error
    except URLError as error:
        raise RuntimeError(f"AudioBookMaker is unavailable at {APP}: {error.reason}") from error
    return json.loads(body)


def dialogues() -> list[dict]:
    source = DIALOGUES.read_text(encoding="utf-8")
    pattern = re.compile(
        r"^## (?P<number>\d{2}) \u2014 (?P<provider>Local|Gemini): "
        r"(?P<voice>.+?) \u2014 .+?\n\n(?P<text>.+?)(?=\n## \d{2} \u2014 |\Z)",
        re.MULTILINE | re.DOTALL,
    )
    entries = []
    for match in pattern.finditer(source):
        entries.append(
            {
                "number": int(match.group("number")),
                "provider": match.group("provider").lower(),
                "voice": match.group("voice").strip(),
                "text": " ".join(match.group("text").split()),
            }
        )
    if len(entries) != 35 or len({entry["number"] for entry in entries}) != 35:
        raise RuntimeError(f"Expected 35 approved dialogue entries, found {len(entries)}")
    return entries


def wait_for_file(path: Path, newer_than: float, timeout: int = 1800) -> None:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if path.exists() and path.stat().st_size > 10_000 and path.stat().st_mtime >= newer_than:
            return
        time.sleep(2)
    raise RuntimeError(f"Timed out waiting for {path}")


def create_local(entry: dict) -> Path:
    destination = OUTPUT / f"voice-{entry['number']:02}.wav"
    source = PREVIEWS / f"{entry['voice']}_preview.wav"
    started = time.time()
    response = post_json(
        "/api/preview",
        {
            "voice": entry["voice"],
            "text": entry["text"],
            "ref_text": "",
            "language": "English",
            "sample_rate": 24000,
        },
        timeout=60,
    )
    if not response.get("ok"):
        raise RuntimeError(f"Local preview was not accepted for {entry['voice']}: {response}")
    wait_for_file(source, started)
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source, destination)
    return destination


def create_gemini(entry: dict) -> Path:
    destination = OUTPUT / f"voice-{entry['number']:02}.mp3"
    source = PREVIEWS / f"gemini_tts_{entry['voice']}_en.mp3"
    started = time.time()
    response = post_json(
        "/api/gemini_tts/preview",
        {
            "voice": entry["voice"],
            "text": entry["text"],
            "sample_language": "en",
            "force": True,
        },
    )
    if not response.get("audio_url"):
        raise RuntimeError(f"Gemini preview failed for {entry['voice']}: {response}")
    wait_for_file(source, started)
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source, destination)
    return destination


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--provider", choices=("all", "local", "gemini"), default="all")
    args = parser.parse_args()

    entries = [entry for entry in dialogues() if args.provider in ("all", entry["provider"])]
    manifest_entries = []
    for position, entry in enumerate(entries, start=1):
        print(f"[{position}/{len(entries)}] {entry['provider']} {entry['voice']}", flush=True)
        destination = create_local(entry) if entry["provider"] == "local" else create_gemini(entry)
        manifest_entries.append(
            {
                "provider": entry["provider"],
                "public_label": f"Voice {entry['number']}",
                "source_voice": entry["voice"],
                "file": destination.relative_to(ROOT).as_posix(),
                "bytes": destination.stat().st_size,
            }
        )
        print(f"  -> {destination.relative_to(ROOT)} ({destination.stat().st_size} bytes)", flush=True)

    if args.provider == "all":
        MANIFEST.parent.mkdir(parents=True, exist_ok=True)
        MANIFEST.write_text(
            json.dumps(
                {
                    "generated_at": datetime.now(timezone.utc).isoformat(),
                    "generator": "OmniVoice local previews and Google Gemini TTS previews",
                    "samples": manifest_entries,
                },
                indent=2,
            )
            + "\n",
            encoding="utf-8",
        )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as error:
        print(f"ERROR: {error}", file=sys.stderr)
        raise SystemExit(1)

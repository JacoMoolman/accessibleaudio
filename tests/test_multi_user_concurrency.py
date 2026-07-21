import concurrent.futures
import hashlib
import shutil
import socket
import subprocess
import threading
import time
from pathlib import Path

import requests


ROOT = Path(__file__).resolve().parents[1]


def available_port():
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        return listener.getsockname()[1]


def php_string(value):
    return str(value).replace("\\", "/").replace("'", "\\'")


def start_test_site(tmp_path, shared_uploads, user_id, email, password):
    site = tmp_path / user_id
    api = site / "api"
    shutil.copytree(
        ROOT / "api",
        api,
        ignore=shutil.ignore_patterns("config.local.php", "config.local.example.php"),
    )
    encoder = shutil.which("ffmpeg")
    assert encoder, "The concurrency smoke test requires ffmpeg"
    config = f"""<?php
return [
    'ENABLE_TEST_LOGIN' => true,
    'TEST_LOGIN_USER_ID' => '{php_string(user_id)}',
    'TEST_LOGIN_EMAIL' => '{php_string(email)}',
    'TEST_LOGIN_PASSWORD' => '{php_string(password)}',
    'UPLOAD_DIR' => '{php_string(shared_uploads)}',
    'PAYFAST_MERCHANT_ID' => '10000100',
    'PAYFAST_MERCHANT_KEY' => '46f0cd694581a',
    'PAYFAST_SANDBOX' => true,
    'PAYFAST_UNSIGNED_SANDBOX' => true,
    'OPENROUTER_API_KEY' => 'concurrency-test-only',
    'LAME_BINARY' => '{php_string(encoder)}',
];
"""
    (api / "config.local.php").write_text(config, encoding="utf-8")
    polyfill = site / "test-polyfill.php"
    polyfill.write_text(
        """<?php
if (!function_exists('mb_check_encoding')) {
    function mb_check_encoding(string $value, ?string $encoding = null): bool {
        return preg_match('//u', $value) === 1;
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value): int {
        return preg_match_all('/./us', $value, $matches);
    }
}
""",
        encoding="utf-8",
    )
    port = available_port()
    creation_flags = getattr(subprocess, "CREATE_NO_WINDOW", 0)
    process = subprocess.Popen(
        ["php", "-d", f"auto_prepend_file={polyfill}", "-S", f"127.0.0.1:{port}", "-t", str(site)],
        cwd=site,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        creationflags=creation_flags,
    )
    base_url = f"http://127.0.0.1:{port}"
    for _ in range(40):
        try:
            if requests.get(f"{base_url}/api/config.php", timeout=0.5).status_code == 200:
                return process, base_url
        except requests.RequestException:
            pass
        time.sleep(0.05)
    process.terminate()
    raise RuntimeError("Test PHP server did not start")


def login(base_url, email, password):
    response = requests.post(
        f"{base_url}/api/test-login.php",
        json={"email": email, "password": password},
        timeout=5,
    )
    response.raise_for_status()
    return response.json()["access_token"]


def upload_at_barrier(base_url, token, filename, barrier):
    manuscript = ("CHAPTER I.\nConcurrent access\n\n" + "Two users can upload safely. " * 80).encode()
    barrier.wait(timeout=5)
    return requests.post(
        f"{base_url}/api/process-file.php",
        headers={"Authorization": f"Bearer {token}"},
        data={
            "narrator_voice": "Voice 1",
            "terms_accepted": "true",
            "terms_version": "2026-07-18",
            "source_language": "English",
            "chapter_titles": '["CHAPTER I."]',
        },
        files={"file": (filename, manuscript, "text/plain")},
        timeout=15,
    )


def test_two_authenticated_users_upload_simultaneously_without_cross_account_leakage(tmp_path):
    shared_uploads = tmp_path / "shared-private-uploads"
    users = [
        ("00000000-0000-4000-8000-000000000101", "one@example.test", "test-one"),
        ("00000000-0000-4000-8000-000000000202", "two@example.test", "test-two"),
    ]
    servers = []
    try:
        for user_id, email, password in users:
            servers.append(start_test_site(tmp_path, shared_uploads, user_id, email, password))
        tokens = [
            login(base_url, email, password)
            for (_, base_url), (_, email, password) in zip(servers, users)
        ]
        barrier = threading.Barrier(2)
        filenames = ["user-one.txt", "user-two.txt"]
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            futures = [
                pool.submit(upload_at_barrier, servers[index][1], tokens[index], filenames[index], barrier)
                for index in range(2)
            ]
            responses = [future.result(timeout=20) for future in futures]

        assert all(response.status_code in (200, 201) for response in responses)
        records = [response.json() for response in responses]
        assert [record["user_id"] for record in records] == [user[0] for user in users]

        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            futures = [
                pool.submit(
                    requests.get,
                    f"{servers[index][1]}/api/files.php",
                    headers={"Authorization": f"Bearer {tokens[index]}"},
                    timeout=5,
                )
                for index in range(2)
            ]
            listings = [future.result().json() for future in futures]

        assert [[record["filename"] for record in listing] for listing in listings] == [
            ["user-one.txt"],
            ["user-two.txt"],
        ]
        assert len((shared_uploads / "uploads.jsonl").read_text(encoding="utf-8").splitlines()) == 2
        for index, (user_id, _, _) in enumerate(users):
            user_hash = hashlib.sha256(user_id.encode()).hexdigest()
            manuscript = (
                shared_uploads
                / "users"
                / user_hash
                / "uploads"
                / records[index]["id"]
                / filenames[index]
            )
            assert manuscript.is_file()
    finally:
        for process, _ in servers:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()

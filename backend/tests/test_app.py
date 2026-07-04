import io
import json
import uuid

import pytest
from fastapi.testclient import TestClient

from backend.app import create_app
from backend.auth import AuthenticatedUser, SupabaseTokenVerifier
from backend.config import Settings
from backend.storage import build_upload_key, validate_txt_upload


class FakeRepository:
    def __init__(self):
        self.created = []

    def create_uploaded_file(self, record):
        saved = {
            **record,
            "created_at": "2026-07-02T10:00:00Z",
            "processed_at": None,
            "result_text": None,
            "result_path": None,
        }
        self.created.append(saved)
        return saved

    def list_uploaded_files(self, user_id):
        return [item for item in self.created if item["user_id"] == user_id]

    def get_uploaded_file(self, user_id, file_id):
        for item in self.created:
            if item["user_id"] == user_id and item["id"] == file_id:
                return item
        return None


class FakeObjectStorage:
    def __init__(self):
        self.uploads = []

    def upload_bytes(self, content, key, content_type):
        self.uploads.append(
            {"content": content, "key": key, "content_type": content_type}
        )
        return "accessible-audio-submissions"


def make_client():
    repo = FakeRepository()
    storage = FakeObjectStorage()
    settings = Settings(
        supabase_url="https://example.supabase.co",
        supabase_service_role_key="service-role",
        supabase_anon_key="anon-key",
        turnstile_site_key="turnstile-site-key",
        supabase_jwt_secret="jwt-secret",
        aws_region="us-east-1",
        s3_bucket_name="accessible-audio-submissions",
        allowed_origins=["http://localhost:8000"],
    )

    async def fake_auth(_authorization):
        return AuthenticatedUser(
            id="11111111-1111-4111-8111-111111111111",
            email="reader@example.com",
        )

    app = create_app(
        settings=settings,
        repository=repo,
        object_storage=storage,
        auth_dependency=fake_auth,
    )
    return TestClient(app), repo, storage


def test_health_returns_ok():
    client, _, _ = make_client()

    response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"ok": True}


def test_public_config_returns_only_browser_safe_values():
    client, _, _ = make_client()

    response = client.get("/config/public")

    assert response.status_code == 200
    assert response.json() == {
        "supabaseUrl": "https://example.supabase.co",
        "supabaseAnonKey": "anon-key",
        "turnstileSiteKey": "turnstile-site-key",
    }


def test_public_config_allows_missing_turnstile_site_key():
    settings = Settings(
        supabase_url="https://example.supabase.co",
        supabase_service_role_key="service-role",
        supabase_anon_key="anon-key",
        turnstile_site_key=None,
    )
    app = create_app(
        settings=settings,
        repository=FakeRepository(),
        object_storage=FakeObjectStorage(),
        auth_dependency=lambda _authorization: AuthenticatedUser(
            id="11111111-1111-4111-8111-111111111111",
            email="reader@example.com",
        ),
    )

    response = TestClient(app).get("/config/public")

    assert response.status_code == 200
    assert response.json() == {
        "supabaseUrl": "https://example.supabase.co",
        "supabaseAnonKey": "anon-key",
        "turnstileSiteKey": None,
    }


def test_submit_page_is_served_only_under_submit_path():
    client, _, _ = make_client()

    root_response = client.get("/")
    submit_response = client.get("/submit")

    assert root_response.status_code == 404
    assert submit_response.status_code == 200
    assert "Submit Audiobook" in submit_response.text


def test_submit_page_nav_points_back_to_public_website():
    client, _, _ = make_client()

    response = client.get("/submit")

    assert response.status_code == 200
    assert 'class="site-header"' in response.text
    assert 'href="https://accessibleaudio.co.za/"' in response.text
    assert 'href="https://accessibleaudio.co.za/#service"' in response.text
    assert 'href="https://accessibleaudio.co.za/#private"' in response.text
    assert 'href="https://accessibleaudio.co.za/#languages"' in response.text
    assert 'href="https://accessibleaudio.co.za/audiobooks.html"' in response.text
    assert 'href="https://accessibleaudio.co.za/contact.html"' in response.text


def test_submit_page_does_not_render_language_placeholder():
    client, _, _ = make_client()

    response = client.get("/submit")

    assert response.status_code == 200
    assert "Pending manual review" not in response.text
    assert "Detected language" not in response.text


def test_frontend_hides_all_login_controls_when_logged_in():
    client, _, _ = make_client()

    response = client.get("/app.js")

    assert response.status_code == 200
    assert "authControls.hidden = loggedIn" in response.text
    assert "googleButton.hidden = loggedIn" in response.text
    assert 'hideWhenLoggedIn(emailInput?.closest("label"), loggedIn)' in response.text
    assert 'hideWhenLoggedIn(passwordInput?.closest("label"), loggedIn)' in response.text


def test_frontend_responses_disable_browser_cache():
    client, _, _ = make_client()

    for path in ("/submit", "/app.js", "/styles.css"):
        response = client.get(path)

        assert response.status_code == 200
        assert response.headers["cache-control"] == "no-store"


def test_frontend_css_preserves_hidden_controls():
    client, _, _ = make_client()

    response = client.get("/styles.css")

    assert response.status_code == 200
    assert "[hidden]" in response.text
    assert "display: none !important" in response.text


def test_analyze_file_detects_language_and_chapter_titles():
    client, _, _ = make_client()

    response = client.post(
        "/analyze-file",
        headers={"Authorization": "Bearer valid-token"},
        files={
            "file": (
                "Book One.txt",
                io.BytesIO(
                    b"Chapter One\nDown the rabbit-hole.\n\n"
                    b"CHAPTER TWO\nThe pool of tears."
                ),
                "text/plain",
            )
        },
    )

    assert response.status_code == 200
    assert response.json() == {
        "source_language": "English",
        "chapters": [
            {"index": 1, "title": "Chapter One"},
            {"index": 2, "title": "CHAPTER TWO"},
        ],
        "chapter_count": 2,
    }


def test_submit_page_requires_file_analysis_before_options():
    client, _, _ = make_client()

    response = client.get("/submit")

    assert response.status_code == 200
    assert 'id="analysis-panel"' in response.text
    assert 'id="analysis-result"' in response.text
    assert 'id="production-options"' in response.text
    assert 'disabled data-requires-analysis' in response.text


def test_submit_page_has_voice_sample_and_translation_voice_controls():
    client, _, _ = make_client()

    response = client.get("/submit")

    assert response.status_code == 200
    assert 'id="play-narrator-sample"' in response.text
    assert 'id="translation-voice-options"' in response.text
    assert 'name="translation-voice-Afrikaans"' in response.text
    assert 'name="translation-voice-Zulu"' in response.text
    assert 'name="translation-voice-English"' in response.text


def test_frontend_analyzes_file_before_unlocking_options():
    client, _, _ = make_client()

    response = client.get("/app.js")

    assert response.status_code == 200
    assert "analyzeSelectedFile" in response.text
    assert "setProductionOptionsEnabled(Boolean(fileAnalysis))" in response.text
    assert "playVoiceSample" in response.text
    assert "selectedTranslationVoices" in response.text


def test_frontend_attempts_direct_test_login_before_supabase_auth():
    client, _, _ = make_client()

    response = client.get("/app.js")

    assert response.status_code == 200
    assert 'fetchJson("/test-login"' in response.text
    assert "const testSession = await tryTestLogin(email, password)" in response.text
    assert "setSession(testSession)" in response.text


def test_test_login_rejects_wrong_password():
    client, _, _ = make_client()

    response = client.post(
        "/test-login",
        json={
            "email": "momstats-test@accessibleaudio.local",
            "password": "wrong",
        },
    )

    assert response.status_code == 401


def test_test_login_token_uploads_without_supabase_confirmation():
    settings = Settings(
        supabase_url="https://example.supabase.co",
        supabase_service_role_key="service-role",
        supabase_anon_key="anon-key",
        turnstile_site_key=None,
    )
    storage = FakeObjectStorage()
    app = create_app(
        settings=settings,
        repository=FakeRepository(),
        object_storage=storage,
    )
    client = TestClient(app)

    login_response = client.post(
        "/test-login",
        json={
            "email": "momstats-test@accessibleaudio.local",
            "password": "momstats-test-2026-07-04",
        },
    )
    assert login_response.status_code == 200
    token = login_response.json()["access_token"]

    response = client.post(
        "/process-file",
        headers={"Authorization": f"Bearer {token}"},
        files={
            "file": (
                "momstats-live-test.txt",
                io.BytesIO(b"Chapter 1\nLive test upload."),
                "text/plain",
            )
        },
        data={
            "narrator_voice": "English Female",
            "output_format": "mp3",
            "also_wav": "false",
            "translate": "false",
            "translation_languages": "",
            "source_language": "English",
            "chapter_titles": json.dumps(["Chapter 1"]),
            "make_video": "false",
        },
    )

    assert response.status_code == 201
    body = response.json()
    assert body["user_id"] == "00000000-0000-4000-8000-000000000006"
    assert body["filename"] == "momstats-live-test.txt"
    assert body["status"] == "uploaded"
    assert len(storage.uploads) == 2

    files_response = client.get("/files", headers={"Authorization": f"Bearer {token}"})
    assert files_response.status_code == 200
    assert [item["filename"] for item in files_response.json()] == [
        "momstats-live-test.txt"
    ]


def test_validate_txt_upload_rejects_non_txt_filename():
    with pytest.raises(ValueError, match="Only .txt files are accepted"):
        validate_txt_upload("novel.pdf", b"hello")


def test_validate_txt_upload_rejects_empty_txt_file():
    with pytest.raises(ValueError, match="empty"):
        validate_txt_upload("novel.txt", b"")


def test_build_upload_key_uses_user_folder_upload_id_and_safe_filename():
    upload_id = uuid.UUID("22222222-2222-4222-8222-222222222222")

    key = build_upload_key(
        user_id="11111111-1111-4111-8111-111111111111",
        upload_id=upload_id,
        filename="../../My Book Draft.txt",
    )

    assert (
        key
        == "users/11111111-1111-4111-8111-111111111111/uploads/22222222-2222-4222-8222-222222222222/My_Book_Draft.txt"
    )


def test_supabase_user_endpoint_verifier_accepts_valid_token():
    class FakeResponse:
        status_code = 200

        def json(self):
            return {
                "id": "11111111-1111-4111-8111-111111111111",
                "email": "reader@example.com",
            }

    class FakeHttpClient:
        def __init__(self):
            self.request = None

        def get(self, url, headers, timeout):
            self.request = {"url": url, "headers": headers, "timeout": timeout}
            return FakeResponse()

    http_client = FakeHttpClient()
    verifier = SupabaseTokenVerifier(
        supabase_url="https://example.supabase.co",
        supabase_anon_key="anon-key",
        http_client=http_client,
    )

    user = verifier.verify_authorization_header("Bearer valid-token")

    assert user == AuthenticatedUser(
        id="11111111-1111-4111-8111-111111111111",
        email="reader@example.com",
    )
    assert http_client.request["url"] == "https://example.supabase.co/auth/v1/user"
    assert http_client.request["headers"]["apikey"] == "anon-key"
    assert http_client.request["headers"]["Authorization"] == "Bearer valid-token"


def test_process_file_uploads_txt_to_s3_and_saves_metadata():
    client, repo, storage = make_client()

    response = client.post(
        "/process-file",
        headers={"Authorization": "Bearer valid-token"},
        files={"file": ("Book One.txt", io.BytesIO(b"Chapter 1\nHello"), "text/plain")},
        data={
            "narrator_voice": "Zulu Female",
            "output_format": "mp3",
            "also_wav": "true",
            "translate": "false",
            "translation_languages": "",
            "make_video": "false",
        },
    )

    assert response.status_code == 201
    body = response.json()
    assert body["user_id"] == "11111111-1111-4111-8111-111111111111"
    assert body["filename"] == "Book One.txt"
    assert body["status"] == "uploaded"
    assert body["s3_bucket"] == "accessible-audio-submissions"
    assert body["s3_key"].startswith(
        "users/11111111-1111-4111-8111-111111111111/uploads/"
    )
    assert body["s3_key"].endswith("/Book_One.txt")
    assert repo.created[0]["narrator_voice"] == "Zulu Female"
    assert storage.uploads[0]["content"] == b"Chapter 1\nHello"
    assert storage.uploads[0]["content_type"] == "text/plain; charset=utf-8"


def test_process_file_uploads_options_text_file_next_to_book():
    client, _, storage = make_client()

    response = client.post(
        "/process-file",
        headers={"Authorization": "Bearer valid-token"},
        files={"file": ("Peter Pan.txt", io.BytesIO(b"Chapter 1\nHello"), "text/plain")},
        data={
            "narrator_voice": "English Female",
            "output_format": "mp3",
            "also_wav": "true",
            "translate": "true",
            "translation_languages": "Afrikaans,Zulu",
            "translation_voices": json.dumps(
                {"Afrikaans": "Afrikaans Male", "Zulu": "Zulu Female"}
            ),
            "source_language": "English",
            "chapter_titles": json.dumps(["Chapter One", "CHAPTER TWO"]),
            "make_video": "true",
        },
    )

    assert response.status_code == 201
    book_upload, options_upload = storage.uploads
    assert book_upload["key"].endswith("/Peter_Pan.txt")
    assert options_upload["key"] == book_upload["key"].rsplit("/", 1)[0] + "/options.txt"
    assert options_upload["content_type"] == "text/plain; charset=utf-8"
    options_text = options_upload["content"].decode("utf-8")
    assert "filename: Peter Pan.txt" in options_text
    assert "narrator_voice: English Female" in options_text
    assert "output_format: mp3" in options_text
    assert "also_wav: true" in options_text
    assert "source_language: English" in options_text
    assert "detected_chapter_count: 2" in options_text
    assert "chapter_1_title: Chapter One" in options_text
    assert "chapter_2_title: CHAPTER TWO" in options_text
    assert "translate: true" in options_text
    assert "translation_languages: Afrikaans, Zulu" in options_text
    assert "translation_voice_Afrikaans: Afrikaans Male" in options_text
    assert "translation_voice_Zulu: Zulu Female" in options_text
    assert "make_video: true" in options_text


def test_process_file_saves_source_language_metadata():
    client, repo, _ = make_client()

    response = client.post(
        "/process-file",
        headers={"Authorization": "Bearer valid-token"},
        files={"file": ("Book One.txt", io.BytesIO(b"Chapter 1\nHello"), "text/plain")},
        data={
            "narrator_voice": "Zulu Female",
            "output_format": "mp3",
            "also_wav": "false",
            "translate": "false",
            "translation_languages": "",
            "source_language": "English",
            "chapter_titles": json.dumps(["Chapter 1"]),
            "make_video": "false",
        },
    )

    assert response.status_code == 201
    assert repo.created[0]["source_language"] == "English"


def test_files_endpoint_returns_only_authenticated_users_records():
    client, repo, _ = make_client()
    repo.created.append(
        {
            "id": "33333333-3333-4333-8333-333333333333",
            "user_id": "11111111-1111-4111-8111-111111111111",
            "filename": "mine.txt",
            "s3_bucket": "accessible-audio-submissions",
            "s3_key": "users/111/uploads/333/mine.txt",
            "status": "uploaded",
        }
    )
    repo.created.append(
        {
            "id": "44444444-4444-4444-8444-444444444444",
            "user_id": "99999999-9999-4999-8999-999999999999",
            "filename": "not-mine.txt",
            "s3_bucket": "accessible-audio-submissions",
            "s3_key": "users/999/uploads/444/not-mine.txt",
            "status": "uploaded",
        }
    )

    response = client.get("/files", headers={"Authorization": "Bearer valid-token"})

    assert response.status_code == 200
    assert [item["filename"] for item in response.json()] == ["mine.txt"]

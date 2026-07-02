import io
import uuid

import pytest
from fastapi.testclient import TestClient

from backend.app import create_app
from backend.auth import AuthenticatedUser
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
    }


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

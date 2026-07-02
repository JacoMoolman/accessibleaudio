import json
import uuid
from collections.abc import Awaitable, Callable
from typing import Annotated, Any

from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, UploadFile, status
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles

from backend.auth import AuthenticatedUser, SupabaseTokenVerifier
from backend.config import Settings
from backend.repository import SupabaseUploadedFilesRepository
from backend.storage import (
    S3ObjectStorage,
    build_upload_key,
    validate_txt_upload,
    validation_http_error,
)

AuthDependency = Callable[[str | None], Awaitable[AuthenticatedUser]]


def create_app(
    settings: Settings | None = None,
    repository: Any | None = None,
    object_storage: Any | None = None,
    auth_dependency: AuthDependency | None = None,
) -> FastAPI:
    settings = settings or Settings.from_env()
    repository = repository or SupabaseUploadedFilesRepository(
        settings.supabase_url,
        settings.supabase_service_role_key,
    )
    object_storage = object_storage or S3ObjectStorage(
        bucket_name=settings.s3_bucket_name,
        region_name=settings.aws_region,
    )
    verifier = SupabaseTokenVerifier(
        jwt_secret=settings.supabase_jwt_secret,
        jwks_url=settings.supabase_jwks_url,
    )

    app = FastAPI(title="Accessible Audio Submit API")
    if settings.allowed_origins:
        app.add_middleware(
            CORSMiddleware,
            allow_origins=settings.allowed_origins,
            allow_credentials=True,
            allow_methods=["GET", "POST", "OPTIONS"],
            allow_headers=["Authorization", "Content-Type"],
        )

    async def current_user(
        authorization: Annotated[str | None, Header()] = None,
    ) -> AuthenticatedUser:
        if auth_dependency is not None:
            return await auth_dependency(authorization)
        return verifier.verify_authorization_header(authorization)

    @app.get("/health")
    def health() -> dict[str, bool]:
        return {"ok": True}

    @app.get("/config/public")
    def public_config() -> dict[str, str]:
        if not settings.supabase_anon_key:
            raise HTTPException(
                status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
                detail="SUPABASE_ANON_KEY is not configured",
            )
        return {
            "supabaseUrl": settings.supabase_url,
            "supabaseAnonKey": settings.supabase_anon_key,
        }

    @app.post("/process-file", status_code=status.HTTP_201_CREATED)
    async def process_file(
        user: Annotated[AuthenticatedUser, Depends(current_user)],
        file: Annotated[UploadFile, File()],
        narrator_voice: Annotated[str, Form()] = "",
        output_format: Annotated[str, Form()] = "mp3",
        also_wav: Annotated[bool, Form()] = False,
        translate: Annotated[bool, Form()] = False,
        translation_languages: Annotated[str, Form()] = "",
        make_video: Annotated[bool, Form()] = False,
    ) -> dict[str, Any]:
        content = await file.read()
        try:
            validate_txt_upload(file.filename or "", content, settings.max_upload_bytes)
        except ValueError as exc:
            raise validation_http_error(exc) from exc

        upload_id = uuid.uuid4()
        key = build_upload_key(user.id, upload_id, file.filename or "upload.txt")
        bucket = object_storage.upload_bytes(
            content=content,
            key=key,
            content_type="text/plain; charset=utf-8",
        )
        record = {
            "id": str(upload_id),
            "user_id": user.id,
            "filename": file.filename,
            "s3_bucket": bucket,
            "s3_key": key,
            "status": "uploaded",
            "narrator_voice": narrator_voice.strip() or None,
            "output_format": output_format,
            "also_wav": also_wav,
            "translate": translate,
            "translation_languages": _parse_translation_languages(translation_languages),
            "make_video": make_video,
        }
        return repository.create_uploaded_file(record)

    @app.get("/files")
    async def files(
        user: Annotated[AuthenticatedUser, Depends(current_user)],
    ) -> list[dict[str, Any]]:
        return repository.list_uploaded_files(user.id)

    @app.get("/files/{file_id}")
    async def file_detail(
        file_id: str,
        user: Annotated[AuthenticatedUser, Depends(current_user)],
    ) -> dict[str, Any]:
        record = repository.get_uploaded_file(user.id, file_id)
        if record is None:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="File not found")
        return record

    frontend_dir = _frontend_dir()
    if frontend_dir.exists():
        assets_dir = frontend_dir / "assets"
        if assets_dir.exists():
            app.mount("/assets", StaticFiles(directory=assets_dir), name="frontend-assets")

        @app.get("/")
        def index() -> FileResponse:
            return FileResponse(frontend_dir / "index.html")

        @app.get("/app.js")
        def app_js() -> FileResponse:
            return FileResponse(frontend_dir / "app.js", media_type="application/javascript")

        @app.get("/styles.css")
        def frontend_css() -> FileResponse:
            return FileResponse(frontend_dir / "styles.css", media_type="text/css")

    return app


def _parse_translation_languages(value: str) -> list[str]:
    value = (value or "").strip()
    if not value:
        return []
    if value.startswith("["):
        parsed = json.loads(value)
        return [str(item).strip() for item in parsed if str(item).strip()]
    return [item.strip() for item in value.split(",") if item.strip()]


def _frontend_dir():
    from pathlib import Path

    return Path(__file__).resolve().parents[1] / "frontend"

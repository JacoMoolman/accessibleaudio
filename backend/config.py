import os
from dataclasses import dataclass, field


def _split_csv(value: str | None) -> list[str]:
    if not value:
        return []
    return [item.strip() for item in value.split(",") if item.strip()]


@dataclass
class Settings:
    supabase_url: str
    supabase_service_role_key: str
    supabase_anon_key: str | None = None
    supabase_jwt_secret: str | None = None
    supabase_jwks_url: str | None = None
    aws_region: str = "us-east-1"
    s3_bucket_name: str = "accessible-audio-submissions"
    allowed_origins: list[str] = field(default_factory=list)
    max_upload_bytes: int = 10 * 1024 * 1024

    @classmethod
    def from_env(cls) -> "Settings":
        return cls(
            supabase_url=_required_env("SUPABASE_URL"),
            supabase_service_role_key=_required_env("SUPABASE_SERVICE_ROLE_KEY"),
            supabase_anon_key=os.getenv("SUPABASE_ANON_KEY"),
            supabase_jwt_secret=os.getenv("SUPABASE_JWT_SECRET"),
            supabase_jwks_url=os.getenv("SUPABASE_JWKS_URL"),
            aws_region=os.getenv("AWS_REGION", "us-east-1"),
            s3_bucket_name=os.getenv("S3_BUCKET_NAME", "accessible-audio-submissions"),
            allowed_origins=_split_csv(os.getenv("ALLOWED_ORIGINS")),
            max_upload_bytes=int(os.getenv("MAX_UPLOAD_BYTES", str(10 * 1024 * 1024))),
        )


def _required_env(name: str) -> str:
    value = os.getenv(name)
    if not value:
        raise RuntimeError(f"Missing required environment variable: {name}")
    return value

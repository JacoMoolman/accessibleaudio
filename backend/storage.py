import re
import uuid
from pathlib import Path

from fastapi import HTTPException, status


SAFE_FILENAME_RE = re.compile(r"[^A-Za-z0-9._-]+")


def safe_filename(filename: str) -> str:
    name = Path(filename).name.strip()
    name = SAFE_FILENAME_RE.sub("_", name)
    name = name.strip("._")
    return name or "upload.txt"


def validate_txt_upload(filename: str, content: bytes, max_bytes: int = 10 * 1024 * 1024) -> None:
    if not filename.lower().endswith(".txt"):
        raise ValueError("Only .txt files are accepted")
    if not content:
        raise ValueError("Uploaded .txt file is empty")
    if len(content) > max_bytes:
        raise ValueError(f"Uploaded file is larger than {max_bytes} bytes")
    try:
        content.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise ValueError("Uploaded .txt file must contain readable plain text") from exc


def validation_http_error(error: ValueError) -> HTTPException:
    return HTTPException(
        status_code=status.HTTP_400_BAD_REQUEST,
        detail=str(error),
    )


def build_upload_key(user_id: str, upload_id: uuid.UUID, filename: str) -> str:
    return f"users/{user_id}/uploads/{upload_id}/{safe_filename(filename)}"


class S3ObjectStorage:
    def __init__(self, bucket_name: str, region_name: str):
        self.bucket_name = bucket_name
        self.region_name = region_name
        self._client = None

    @property
    def client(self):
        if self._client is None:
            import boto3

            self._client = boto3.client("s3", region_name=self.region_name)
        return self._client

    def upload_bytes(self, content: bytes, key: str, content_type: str) -> str:
        self.client.put_object(
            Bucket=self.bucket_name,
            Key=key,
            Body=content,
            ContentType=content_type,
            ServerSideEncryption="AES256",
        )
        return self.bucket_name

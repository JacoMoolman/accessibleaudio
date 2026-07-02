from typing import Any


class SupabaseUploadedFilesRepository:
    def __init__(self, supabase_url: str, service_role_key: str):
        try:
            from supabase import create_client
        except ImportError as exc:
            raise RuntimeError("supabase-py is required for database access") from exc

        self.client = create_client(supabase_url, service_role_key)

    def create_uploaded_file(self, record: dict[str, Any]) -> dict[str, Any]:
        response = (
            self.client.table("uploaded_files")
            .insert(record)
            .execute()
        )
        return response.data[0]

    def list_uploaded_files(self, user_id: str) -> list[dict[str, Any]]:
        response = (
            self.client.table("uploaded_files")
            .select("*")
            .eq("user_id", user_id)
            .order("created_at", desc=True)
            .execute()
        )
        return response.data

    def get_uploaded_file(self, user_id: str, file_id: str) -> dict[str, Any] | None:
        response = (
            self.client.table("uploaded_files")
            .select("*")
            .eq("user_id", user_id)
            .eq("id", file_id)
            .limit(1)
            .execute()
        )
        return response.data[0] if response.data else None

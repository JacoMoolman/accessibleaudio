from dataclasses import dataclass
from typing import Any

from fastapi import HTTPException, status


@dataclass(frozen=True)
class AuthenticatedUser:
    id: str
    email: str | None = None


class SupabaseTokenVerifier:
    def __init__(
        self,
        jwt_secret: str | None = None,
        jwks_url: str | None = None,
        supabase_url: str | None = None,
        supabase_anon_key: str | None = None,
        http_client: Any | None = None,
    ):
        self.jwt_secret = jwt_secret
        self.jwks_url = jwks_url
        self.supabase_url = supabase_url.rstrip("/") if supabase_url else None
        self.supabase_anon_key = supabase_anon_key
        self.http_client = http_client

    def verify_authorization_header(self, authorization: str | None) -> AuthenticatedUser:
        if not authorization or not authorization.lower().startswith("bearer "):
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail="Missing bearer token",
            )
        token = authorization.split(" ", 1)[1].strip()
        if not token:
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail="Missing bearer token",
            )

        if not self.jwt_secret and not self.jwks_url:
            return self._verify_with_supabase_user_endpoint(token)

        payload = self._decode_token(token)
        user_id = payload.get("sub")
        if not user_id:
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail="Token is missing subject",
            )
        return AuthenticatedUser(id=str(user_id), email=payload.get("email"))

    def _decode_token(self, token: str) -> dict[str, Any]:
        try:
            import jwt
        except ImportError as exc:
            raise RuntimeError("PyJWT is required for Supabase JWT verification") from exc

        try:
            if self.jwt_secret:
                return jwt.decode(
                    token,
                    self.jwt_secret,
                    algorithms=["HS256"],
                    audience="authenticated",
                    options={"verify_aud": False},
                )
            if self.jwks_url:
                signing_key = jwt.PyJWKClient(self.jwks_url).get_signing_key_from_jwt(token)
                return jwt.decode(
                    token,
                    signing_key.key,
                    algorithms=["ES256", "RS256"],
                    audience="authenticated",
                    options={"verify_aud": False},
                )
        except jwt.PyJWTError as exc:
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail="Invalid bearer token",
            ) from exc

        raise RuntimeError(
            "Configure SUPABASE_JWT_SECRET, SUPABASE_JWKS_URL, or SUPABASE_ANON_KEY"
        )

    def _verify_with_supabase_user_endpoint(self, token: str) -> AuthenticatedUser:
        if not self.supabase_url or not self.supabase_anon_key:
            raise RuntimeError(
                "Configure SUPABASE_ANON_KEY to verify tokens through Supabase Auth"
            )
        client = self.http_client
        if client is None:
            import httpx

            client = httpx.Client()
        response = client.get(
            f"{self.supabase_url}/auth/v1/user",
            headers={
                "apikey": self.supabase_anon_key,
                "Authorization": f"Bearer {token}",
            },
            timeout=10,
        )
        if response.status_code != 200:
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail="Invalid bearer token",
            )
        data = response.json()
        user_id = data.get("id") or data.get("sub")
        if not user_id:
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail="Token is missing user id",
            )
        return AuthenticatedUser(id=str(user_id), email=data.get("email"))

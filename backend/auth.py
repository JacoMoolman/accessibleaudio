from dataclasses import dataclass
from typing import Any

from fastapi import HTTPException, status


@dataclass(frozen=True)
class AuthenticatedUser:
    id: str
    email: str | None = None


class SupabaseTokenVerifier:
    def __init__(self, jwt_secret: str | None = None, jwks_url: str | None = None):
        self.jwt_secret = jwt_secret
        self.jwks_url = jwks_url

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

        raise RuntimeError("Configure SUPABASE_JWT_SECRET or SUPABASE_JWKS_URL")

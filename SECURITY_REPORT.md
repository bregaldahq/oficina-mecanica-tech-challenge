# Security Report — Oficina Mecânica API

This document describes the security measures implemented, known limitations, and recommendations for production deployment.

---

## 1. Authentication & JWT

**Implementation:** Pure PHP HMAC-SHA256 JWT (no third-party library).

| Aspect | Status |
|--------|--------|
| Algorithm | HS256 (HMAC-SHA256) — symmetric, suitable for single-service APIs |
| Signature verification | `hash_equals()` — timing-safe comparison, prevents timing attacks |
| Expiration (`exp` claim) | Validated on every request |
| Secret from environment | `$_ENV['JWT_SECRET']` — never hardcoded |
| Token storage | Client-side — server is stateless |

**Limitations:**
- No token revocation (would require a blocklist/Redis store)
- Secret rotation requires coordinated deployment (all active tokens become invalid)
- Single shared secret — not suitable for multi-issuer scenarios

**Recommendation:** Use asymmetric RS256 keys for multi-service architectures.

---

## 2. SQL Injection Prevention

**Status: Mitigated ✅**

All database interactions use PDO with parameterized statements. Two key PDO attributes enforce this:

```php
PDO::ATTR_EMULATE_PREPARES => false   // Forces native prepared statements in MySQL
PDO::ATTR_ERRMODE => ERRMODE_EXCEPTION // Errors throw exceptions, never silently fail
```

Dynamic query building is only used for the optional `status` filter in `findByDocumentAndLicensePlate()`,
where the value is passed as a bound parameter — never concatenated.

---

## 3. Cross-Site Scripting (XSS)

**Status: Not applicable ✅**

This is a pure JSON REST API. It never renders HTML. The `Content-Type: application/json` header
and `X-Content-Type-Options: nosniff` prevent browsers from interpreting responses as HTML.

---

## 4. Cross-Site Request Forgery (CSRF)

**Status: Not applicable ✅**

The API is stateless (JWT-based). There are no session cookies to forge. CORS headers restrict
which origins can make cross-origin requests.

---

## 5. Rate Limiting

**Status: Implemented (basic) ✅**

The `POST /api/auth/login` endpoint enforces a limit of **5 requests per IP per 60 seconds**
using a file-based counter in the system temp directory. Exceeding the limit returns HTTP 429
with a `Retry-After` header.

**Limitation:** File-based rate limiting does not work correctly in multi-server deployments.
**Recommendation:** Replace with Redis-backed rate limiting (e.g., `INCR` + `EXPIRE`) in production.

---

## 6. Security HTTP Headers

The following headers are sent on every response:

| Header | Value | Purpose |
|--------|-------|---------|
| `X-Content-Type-Options` | `nosniff` | Prevents MIME-type sniffing |
| `X-Frame-Options` | `DENY` | Prevents clickjacking |
| `X-XSS-Protection` | `1; mode=block` | Legacy browser XSS filter |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Limits referrer leakage |

**Recommendation for production:** Add `Strict-Transport-Security` (HSTS) at the nginx level.

---

## 7. Input Validation

All controller inputs are validated through `RequestValidator` before reaching the domain layer.
Domain Value Objects (`Document`, `LicensePlate`) perform mathematical validation and throw
typed domain exceptions on invalid input — these are caught and returned as HTTP 422.

---

## 8. Sensitive Data in Logs

**Recommendation:**
- Never log JWT tokens, passwords, or CPF/CNPJ values.
- The `APP_DEBUG=false` environment variable ensures production error responses do not expose stack traces.
- PHP error logs (`/var/log/php/error.log`) should have restricted file permissions (600, owned by www-data).

---

## 9. Environment Variables

- `.env` is in `.gitignore` — never commit secrets.
- `EnvLoader::require()` is called on startup and throws `RuntimeException` if required variables
  (`JWT_SECRET`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) are missing.
- `JWT_SECRET` should be at least 32 random bytes, generated with `openssl rand -hex 32`.

---

## 10. MySQL Security

- MySQL port 3306 is exposed to the host in the development `docker-compose.yml`.
  **In production, remove the `ports:` block** from the `db` service.
- MySQL uses `mysql_native_password` for compatibility; consider upgrading to `caching_sha2_password`
  in MySQL 8.0+ production deployments.
- The root password is configurable via `MYSQL_ROOT_PASSWORD` environment variable.

---

## Summary

| Threat | Status |
|--------|--------|
| SQL Injection | ✅ Mitigated (PDO parameterized queries) |
| XSS | ✅ N/A (JSON API) |
| CSRF | ✅ N/A (Stateless JWT) |
| Authentication Brute-force | ✅ Rate limited (file-based, replace with Redis in prod) |
| Token Tampering | ✅ Mitigated (HMAC-SHA256 + `hash_equals`) |
| Token Expiry | ✅ Enforced (exp claim validation) |
| Sensitive data exposure | ✅ APP_DEBUG=false in prod |
| Missing env vars | ✅ Validated at startup |
| Clickjacking | ✅ X-Frame-Options: DENY |
| MIME sniffing | ✅ X-Content-Type-Options: nosniff |
| DB port exposure | ⚠️ Remove ports in production |
| Token revocation | ⚠️ Not implemented (stateless by design) |

---
name: xpayment
description: Work with the XPayment payment gateway and API. Use when implementing or reviewing XPayment integration code, creating payment requests, checking payment statuses, handling idempotency, or wiring XPayment webhooks and authentication. Read this skill when the request mentions xpayment, api.xpayment.kz, payment creation, payment status sync, or Kaspi QR payments through XPayment.
---

# XPayment

Use `references/api.md` as the primary reference for endpoints, request payloads, response shapes, and bearer authentication.

## Workflow

1. Read `references/api.md` before proposing code or request payloads.
2. Identify the exact XPayment flow:
   - create payment
   - fetch payment by ID
   - list payments
   - handle webhook or status sync
3. Keep all credentials in config or environment variables. Do not hardcode API keys.
4. Use Laravel's HTTP client with explicit `timeout()`, `connectTimeout()`, `retry()`, and `throw()`.
5. Add idempotency headers for payment creation when the API supports them.
6. Normalize XPayment statuses into local domain statuses in one place instead of scattering mapping logic.
7. Log enough request context for debugging, but never log raw secrets.

## Implementation Notes

- Base URL and route details should come from `references/api.md`.
- Authentication uses Bearer authorization.
- Payment creation may use `X-Idempotency-Key`.
- Treat external API calls as unreliable: handle timeouts, retries, and non-2xx responses explicitly.
- Prefer a dedicated client class such as `XPaymentClient` and keep controller or job code thin.

## References

- `references/api.md` — endpoints, payloads, example responses, and auth details

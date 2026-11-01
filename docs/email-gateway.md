# Email Gateway (Gmail API + SMTP)

Backend-only MVP. The core runs in Iran; the outbound-only worker runs in Germany. Both use the same Laravel codebase but separate environment settings.

## Iran environment

- EMAIL_GATEWAY_URL=https://<germany-gateway-host>
- EMAIL_GATEWAY_SECRET=<openssl rand -hex 32>
- EMAIL_GATEWAY_ENABLED=false
- GMAIL_OAUTH_CLIENT_ID=<Google Cloud OAuth client ID>
- GMAIL_OAUTH_REDIRECT_URI=https://<iran-domain>/api/v1/email/accounts/gmail/callback
- EMAIL_USER_DAILY_LIMIT=50; EMAIL_PROJECT_DAILY_LIMIT=30

## Germany environment

- EMAIL_GATEWAY_ENABLED=true
- EMAIL_GATEWAY_SECRET=<same secret as Iran>
- GMAIL_OAUTH_CLIENT_ID=<same Google client ID>
- GMAIL_OAUTH_REDIRECT_URI=<same Iran callback URI>
- GMAIL_OAUTH_CLIENT_SECRET=<Google Cloud client secret: NEVER put this on Iran>
- CACHE_STORE=file (or Redis, shared across all instances)

Expose ONLY POST /internal/mail-gateway over HTTPS on Germany via reverse proxy/firewall. Do not connect the Germany Laravel app to Iran MySQL. Enforce outbound firewall rules blocking private IP networks and metadata endpoints; DNS-only SMTP checks are not sufficient against DNS rebinding. Allow SMTP ports 465/587 and Google HTTPS.

## Account connection

User browser must have an authenticated Laravel web session and CSRF token:
- GET /api/v1/email/accounts/gmail/authorize returns Google authorization URL.
- Google callback GET /api/v1/email/accounts/gmail/callback validates one-use state and same Hub login session, exchanges code through Germany, checks verified email via Google OIDC userinfo, encrypts refresh token in Iran DB.
- POST /api/v1/email/accounts/smtp accepts {email,host,port,username,password}; gateway sends a six-digit verification code to the address. Only SMTP over TLS 465/587 supported.
- POST /api/v1/email/accounts/smtp/{id}/verify with {code} within 10 min activates account.
- GET /api/v1/email/accounts/ lists accounts. DELETE /api/v1/email/accounts/{id} disables them; Gmail revocation is attempted and any failure is reported.
- POST /api/v1/email/accounts/{id}/projects with {project_id} explicitly grants a project use of that verified sender; DELETE the same URI with {project_id} revokes access.

## REST API for external projects

POST /api/v1/emails with Authorization: Bearer <project API key> and Idempotency-Key: <16-128 char string>.
Body JSON: account_id, to[], cc[], bcc[], subject, text, html, reply_to, priority (1-5), attachments[].
Attachments are {filename,content_type,content_base64}; up to three files / 6MiB decoded total. Text or HTML required.
Returns HTTP 202 and notification_id. GET /api/v1/emails/{notification_id} returns provider acceptance status and errors.

Sender is forced to verified account.email; project_email_accounts allowlist mandatory. Provider-accepted means Gmail/SMTP accepted submission, NOT arrival in recipient mailbox. User/project daily quotas are enforced by DB-lock admission in the queued job. Jobs are scheduled to five existing priority queues.

Credentials and full messages are encrypted at rest with Laravel Crypt; keep APP_KEY secret. All email sending, Google OAuth token exchange/refresh, and Google userinfo queries occur on Germany via a timestamp+nonce HMAC-signed endpoint.

On uncertain downstream outcomes jobs are marked needs_review instead of blind resend; accepted sends are deduplicated on Germany for 7 days. Exactly-once delivery and bounce reporting are not available from this implementation.

## Not included

Browser UI, inbound support mail, Outlook Graph, real provider delivery confirmation, and automated reconciliation of external mail quotas. Google Cloud OAuth public-app verification and credential values must be configured before live testing.

Sources: https://developers.google.com/workspace/gmail/api/reference/rest/v1/users/messages/send
https://developers.google.com/identity/protocols/oauth2/web-server
https://developers.google.com/identity/openid-connect/openid-connect
https://symfony.com/doc/current/mailer.html

Project owners can create, list, and revoke API keys using GET/POST /api/v1/email/projects/{project}/keys and DELETE /api/v1/email/projects/{project}/keys/{key}; the raw token is displayed only once at creation.

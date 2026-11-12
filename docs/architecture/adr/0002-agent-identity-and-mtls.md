# ADR 0002: Agent identity and mTLS

- Status: Accepted
- Date: 2026-11-12
- Phase: 1

## Context

Agents run on customer-owned VPS nodes and report inventory, heartbeats and
command results. The control plane must be able to prove that a request comes
from a specific enrolled server, survive a compromised enrollment token, and
revoke a node immediately. Agents must not require inbound connectivity.

## Decision

### Enrollment

- An owner or administrator creates an enrollment token. The plaintext token is
  returned once; only its SHA-256 hash is stored.
- Tokens expire after 15 minutes by default and are single-use. Redemption
  happens in a database transaction with a row lock.
- The agent generates an RSA-2048 key locally and sends a CSR whose common name
  is the CloudForge server UUID. The private key never leaves the host.
- The control plane validates the CSR subject and public key, signs it with a
  private agent CA (RSA-4096, ten-year CA certificate, one-year client
  certificate by default), and records the certificate serial, fingerprint and
  expiry against the server.

### Request authentication

Every authenticated agent request is signed with the certificate private key:

```text
METHOD \n request-uri \n unix-timestamp \n nonce \n sha256(raw-body)
```

The signature is sent as base64 RSA-SHA256. The request also carries the
lowercase SHA-256 certificate fingerprint, which selects the registered
certificate and therefore the public key. The control plane:

1. Rejects missing or malformed headers.
2. Rejects timestamps outside a 300-second window.
3. Rejects nonces that do not match `[A-Za-z0-9_-]{16,128}`.
4. Resolves the certificate by fingerprint and rejects unknown, expired or
   revoked certificates.
5. Caches each nonce for twice the clock skew and rejects reuse.
6. Verifies the signature over the canonical string.

The canonical request URI is the path plus optional query string, not the full
URL. This keeps the signature stable behind TLS terminators and reverse proxies.

### TLS and mTLS

Production control-plane URLs must use HTTPS. The agent uses the system trust
store by default and accepts an additional PEM CA bundle through
`CLOUDFORGE_CA_FILE` for private certificate authorities.

The TLS terminator may require client certificates and forward the presented
certificate serial or fingerprint. When
`CLOUDFORGE_REQUIRE_CLIENT_CERT_HEADER=true`, the application rejects a request
whose forwarded certificate identity does not match the registered certificate.
This lets an operator enforce mutual TLS at the edge without terminating or
re-originating the signed application request.

### Rotation and revocation

- The agent checks its certificate every 12 hours and rotates inside the final
  30 days using `POST /api/agent/v1/certificates/rotate`. Rotation requires a
  valid current signature and a new CSR with the same common name.
- Revocation sets `revoked_at` and a reason. The verifier checks it on every
  request, so revocation takes effect on the next call.
- Deleting a server revokes its active certificates.

## Consequences

Positive:

- A leaked enrollment token cannot be reused and cannot be used without the
  matching private key.
- Request signing works through reverse proxies and does not require the
  application to terminate TLS.
- Revocation is immediate and does not depend on certificate expiry.
- The agent needs no inbound firewall rule.

Negative:

- Two identity mechanisms must stay aligned: the application signature and the
  optional TLS client certificate.
- The agent CA private key is a high-value secret. Compromise allows issuing
  certificates for any server ID, although the attacker still needs the
  corresponding private key and a valid server record.
- Clock drift beyond the skew window breaks authentication. Hosts must run NTP.

## Alternatives considered

- **Bearer tokens per agent.** Simpler, but a stolen token grants full agent
  identity and must be rotated manually. Rejected.
- **Certificate fingerprint alone.** The fingerprint is public and appears in
  headers and logs; it is an identifier, not a secret. Rejected.
- **Mutual TLS only, terminated in PHP.** PHP-FPM does not reliably expose the
  client certificate to the application, and requiring it would prevent
  operating behind a load balancer. Request signatures plus an optional
  forwarded certificate header are more portable.
- **Enrollment token with a long lifetime.** Rejected because enrollment is a
  bootstrap secret and should be consumed immediately.

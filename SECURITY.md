# Security

If you find a vulnerability in Belvedere, email **contact@cote-dev.fr** rather than opening a public issue. Include the affected version, the steps to reproduce and, if you have one, a suggested fix. You will get an answer within a few days, and a fix or a mitigation before any public disclosure.

Things worth keeping in mind when you deploy Belvedere:

- The dashboard is only reachable in the `local` environment until you define the `viewBelvedere` gate or a `Belvedere::auth()` callback. Do not expose it without one.
- Snapshots store the public properties of your jobs. Keys such as `password`, `token` or `secret` are redacted (`snapshot.hidden_keys`), jobs implementing `ShouldBeEncrypted` get no snapshot, and `snapshot.enabled` turns the feature off entirely.
- `read_only` disables every action (retry, delete, purge, pause) while keeping the dashboard readable.

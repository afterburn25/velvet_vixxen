# Security Policy

<!-- road-to-1.0-integration:start -->
## Active work — build through 1.0

The owner requested **build through 1.0**. The integration branch displays **0.9.0 / Build 20261021.4** while the remaining safeguards and production release gates are completed. The latest verified delivered baseline remains **0.8.14 / Build 20261021.3**. This in-progress record does not claim final CI, merge, new customer downloads or production certification. See [the active integration record](docs/ROAD_TO_1_0.md) and [issue #34](https://github.com/afterburn25/velvet_vixxen/issues/34).

Administrator two-factor security, atomic private state/configuration, deleted editorial recovery, campaign content, attribution/analytics, consent-aware notifications, authenticated mail-provider events, encrypted backup/recovery and protected-media consent/report workflows are integrated with local acceptance. Age assurance, geographic/policy controls and member privacy export/deletion are being completed. Preserve all previously delivered features and every required release gate. Complete feasible engineering and artifact verification before asking for live merchant/provider/host inputs. Payments remain sandbox-only until an approved provider is selected and tested.

**Owner-confirmed resolution:** Photo depth became available after the installed version was updated. Do not restart the missing-control troubleshooting. Broader production-host certification remains a separate acceptance requirement.
<!-- road-to-1.0-integration:end -->

## Reporting

Do not open a public issue containing an exploitable vulnerability, credentials, customer/identity/payment data, private media, private messages, or signing material. Contact the repository owner privately with the minimum evidence needed to reproduce the problem.

## Never commit

- production database, SMTP, payment, webhook, storage, or licensing credentials;
- generated application/media-signing secrets;
- Ed25519 private signing keys;
- customer, member, billing, identity, release, message, or device records;
- private or paid media;
- installed configuration, sessions, logs, cache, temporary files, add-on staging/quarantine runtime contents, or install/safe-mode locks.

## Add-on boundary

Add-ons are executable PHP. Package scanning, checksums, optional signatures, permission declarations, dependency checks, backups, safe mode, quarantine, and rollback reduce risk but do not fully sandbox untrusted code. Install only packages from trusted sources. Invalid signatures and blocking package findings must remain non-bypassable.

## Payment boundary

The bundled provider is sandbox-only. Production payment collection must use an approved hosted/tokenized provider adapter. Raw card numbers must never be stored by Velvet Vixxen.

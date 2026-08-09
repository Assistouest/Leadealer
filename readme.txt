=== Leadealer ===
Contributors: adrienpiron
Tags: forms, contact form, lead management, anti-spam, smtp
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.7.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A reliable lead-focused form builder with Lead Vault, conditional logic, cache-safe Proof of Work anti-spam, and native wp_mail() delivery.

== Description ==

Leadealer is a visual WordPress form builder by Adrien Piron. Its Lead Vault is designed around a simple rule: once a valid submission has passed Proof of Work and server-side validation, a mail or SMTP failure must not erase the lead.

The accepted lead is stored durably before wp_mail() runs. Mail delivery is then tracked as a separate recoverable operation with a frozen notification snapshot, bounded retries, a crash watchdog, a redundant recovery worker, a delivery timeline, and a dead-letter state for administrator attention.

Main features:

* Lead Vault stores accepted leads before any mail attempt.
* Frozen recipient, subject, body, and headers allow retries even after the form is edited.
* Atomic mail claims prevent concurrent workers from sending the same queued attempt.
* Six bounded delivery attempts with progressive backoff.
* Individual retry events plus a redundant recurring recovery worker.
* Crash watchdog recovers deliveries left in a stale processing state.
* Dead-letter state, administrator retry, and per-lead reliability timeline.
* Submission UUID plus a browser-held 256-bit idempotency secret protects lost-response retries without exposing Lead Vault IDs.
* Public success means the lead was stored safely; mail delivery is tracked independently.
* Visual three-panel builder with field library, live canvas, contextual settings, and starter templates.
* Pointer-based drag and drop supports grid-aware reordering, palette-to-canvas insertion, mouse/touch handles, keyboard reordering, undo, and redo.
* Responsive 12-column layout with desktop, tablet, and mobile previews.
* Text, email, phone, long text, number, URL, dropdown, radio, checkbox, consent, and photo upload fields.
* Photo uploads are fully re-encoded server-side (EXIF/GPS stripped, auto-oriented, downscaled) before storage, never exposed through a plugin-generated public URL, and delivered as real email attachments. HEIC/HEIF is converted to JPEG only when the visitor's browser can decode it natively; the server never decodes HEIC/HEIF.
* Conditional field display with all/any matching and multiple rules.
* Stable internal choice values, so visible labels can be renamed without breaking conditions.
* International phone selector with +33 default and E.164-style normalization.
* Cache-safe stateless HMAC-signed Proof of Work challenges.
* SQL replay protection, rate limiting, honeypot, and submission idempotency.
* Native wp_mail() delivery for compatibility with SMTP and mail-delivery plugins.
* Schema-driven responsive HTML email template with inline styles and a live saved-schema preview in the form editor.
* Email, phone, and URL values are rendered as safe clickable links while conditional fields follow the validated submission.
* Reliability notices stay inside Leadealer screens, use WordPress notice styling, and remain dismissed until a newer delivery failure appears.
* Privacy exporter/eraser and configurable Lead Vault retention.
* Consent fields can automatically link to the WordPress Privacy Policy page.
* US English source strings with bundled French translations.
* One shortcode: `[leadealer_form id="123"]`.
* Dynamic Leadealer Form block for the block editor.

== Installation ==

1. Upload and activate Leadealer.
2. Open Forms > Add New.
3. Add fields or start from a built-in template.
4. Arrange fields, responsive widths, and conditional display rules.
5. Configure the notification and publish the form.
6. Insert the Leadealer Form block or use `[leadealer_form id="123"]`.
7. Open Lead Vault to review received leads and delivery status.

== Frequently Asked Questions ==

= What happens if SMTP or wp_mail() fails? =

The accepted lead remains in Lead Vault. Leadealer records the failure, schedules another bounded attempt, and also relies on a redundant recovery worker to find due or stale deliveries. After the automatic attempt limit is reached, the lead moves to Needs attention and can be retried manually.

= Does a successful form response mean the email reached the inbox? =

No. Leadealer only reports success to the visitor after the lead has been stored safely. A successful wp_mail() result means WordPress handed the notification to its configured mail transport; final inbox delivery remains the responsibility of that transport and the receiving mail system.

= Why does Leadealer store a notification snapshot? =

The recipient, subject, body, and headers are frozen when the lead is received. A retry therefore does not depend on later edits to the form and can continue even if notification settings change afterward.

= What if WP-Cron is disabled? =

The first mail attempt still runs immediately after the lead is stored. For automatic retries and recovery, configure a real system cron to call wp-cron.php regularly when DISABLE_WP_CRON is enabled.

= Does Leadealer work with page caching? =

Yes. Public form HTML contains no per-visitor nonce. Proof of Work challenges are requested dynamically with no-store headers and a cache-busting request URL.

= Does it work with SMTP plugins? =

Yes. Leadealer sends notifications through WordPress wp_mail(), allowing WP Mail SMTP, FluentSMTP, Post SMTP, Brevo, Amazon SES integrations, and similar delivery plugins to intercept mail normally.

= How do conditional fields work? =

Place the field where it belongs in the form, enable conditional display, and choose the source field, operator, and value. Hidden fields are also ignored by server-side validation and storage.

= Are photo uploads safe? =

Only JPEG, PNG, and WEBP files are accepted by the server. Every accepted image is fully decoded and re-encoded from scratch — the original bytes are discarded, along with EXIF/GPS/metadata — before being written under a secret-derived hardened storage directory using a server-generated random filename. The supported viewing path requires an authenticated administrator, an explicit capability check, and a per-entry nonce. HEIC/HEIF is accepted only when the browser can decode it natively and convert it to JPEG before upload; browsers without a safe native decoder ask the visitor to use JPEG instead.

== Third-party code ==

The distributed plugin does not bundle a third-party HEIC/WASM decoder and does not load JavaScript, CSS, fonts, or executable code from a CDN. It makes no third-party network request at runtime. HEIC/HEIF conversion relies only on a browser-native decoder when available; the converted JPEG must still pass the same server-side size, MIME, signature, dimension, full-decode, re-encode, and stored-file integrity checks as every other upload.

== Changelog ==

= 0.7.3 =
* Fixed the remaining photo-loss path by sending prepared photos atomically in the same multipart REST request as the lead instead of relying on a separate pre-upload request and hidden-token hand-off.
* A selected photo can no longer be omitted silently: the browser blocks submission until preparation is complete, and the server requires every declared file part to be present and mapped to a real file field in the stored form schema.
* Direct photo submissions still pass the complete server-side image security pipeline: upload error checks, real temporary-file size, MIME/signature validation, dimension and pixel limits, full decode/re-encode, random server filename, signed token reservation, and storage integrity verification.
* The legacy /upload endpoint remains available for older cached 0.7.2 JavaScript, but current front-end code no longer depends on it.
* Attachment claiming is now fail-closed. If an expected upload cannot be durably linked to the Lead Vault entry, email delivery is blocked instead of continuing without the photo.
* Failed or conditionally inapplicable atomic uploads are deleted immediately instead of being left pending.

= 0.7.2 =
* Fixed photo attachments being silently omitted from an otherwise successful notification. A lead that expects a photo now fails mail delivery closed if the claimed file is missing or fails integrity verification; the lead remains recoverable instead of sending a misleading attachment-less message.
* Added final attachment enforcement at both the last wp_mail argument filter and the PHPMailer initialization stage. Queued transports that short-circuit PHPMailer receive the verified paths, while normal transports are checked again immediately before send; if a required photo cannot be attached, delivery fails instead of being marked as handed off.
* Lead Vault now behaves more like a durable mailbox: the list shows the frozen subject, recipient and attachment count, and each lead opens with the complete stored email snapshot before the raw submitted-data/timeline sections.
* Lead Vault email backup now displays recipient, Reply-To, subject, received time, the sandboxed HTML message, and secure thumbnails for every retained photo attachment.
* Added attachment-preparation timeline evidence so administrators can distinguish a stored photo, a transport handoff, and a transport whose final PHPMailer attachment list could not be inspected.
* Added a frozen attachment manifest to each new Lead Vault entry. Mail retries no longer depend on the current form schema to remember that a photo was expected, so editing or deleting the form cannot silently turn an old retry into an attachment-less email.
* Lead Vault now flags missing or integrity-failed expected photos explicitly, and the form setting explains that retaining completed leads also retains the readable email backup and its photos for delivery protection.
* Updated the privacy-policy helper to explain retained Lead Vault photo storage as well as the 24-hour grace period used when completed lead retention is disabled.

= 0.7.1 =
* Upload storage now fails closed when WordPress cannot provide a valid absolute writable uploads directory; no relative-path fallback is possible.
* File fields can no longer persist arbitrary default values in the builder; only server-issued signed upload tokens can populate their hidden submission value.
* The WordPress privacy-policy helper now discloses the up-to-24-hour private attachment grace period used when lead retention is disabled and a mail transport may consume attachment paths asynchronously.
* Removed the bundled legacy heic2any/libheif browser decoder after the security review. HEIC/HEIF now uses only native browser decoding when available; otherwise the visitor is asked to export the image as JPEG. The server never accepts HEIC/HEIF bytes.
* Multisite hardening: network activation initializes existing sites and newly-created sites, while the new-site hook verifies that Leadealer is actually network-active before modifying another site.
* Security hardening: upload tokens are atomically reserved to a submission before lead creation, preventing cross-submission replay/races.
* Security hardening: private attachment storage uses a secret-derived directory plus Apache/IIS deny rules and strict server-side file integrity checks.
* Security hardening: canonical conditional values, strict per-field bounds, HTTP(S)-only URL validation, mail-header allowlisting, and redacted/bounded transport errors.
* Abuse-control hardening: invalid requests can no longer exhaust the submit/upload rate-limit budget before presenting a fresh valid Proof of Work token, reducing quota-starvation against legitimate visitors sharing an IP.
* Security hardening: admin builder attribute escaping now prevents DOM-based attribute injection.
* Privacy/cleanup: uninstall removes the upload table and plugin-managed attachment files when data deletion is enabled, including multisite sites.
* Fixed failed photo pre-uploads being silently discarded while the rest of the form was still submitted. A failed photo upload now blocks submission until the visitor retries or removes the photo, so an email can no longer appear successful while quietly losing the selected attachment.
* The advertised and enforced photo-size limit now respects the lower of Leadealer's 10 MB cap and WordPress/PHP's real upload limit.
* Hardened the public upload path against decompression-bomb style images with a total-pixel cap before GD decoding, and verifies the actual temporary-file size instead of relying only on the multipart size field.
* Added graceful handling when the GD image extension/functions are unavailable instead of allowing the upload REST request to fatal.
* Restored declared PHP 7.4 compatibility for GD images by accepting the resource type used before PHP 8 as well as GdImage objects.
* Kept claimed attachment files for a 24-hour grace period after wp_mail() handoff when lead storage is disabled, preventing asynchronous SMTP/queue plugins from receiving a path that Leadealer deletes immediately.
* Added IIS storage-deny rules and an empty HTML index alongside the existing Apache/OpenLiteSpeed hardening files.
* Security review cross-checked the upload implementation against recent WordPress arbitrary-file-upload/file-move vulnerability patterns; uploaded bytes still must pass extension, MIME, magic-byte and full image decode checks and are re-encoded under a server-generated filename before storage.

= 0.7.0 =
* Fixed the main reason an attached photo could go missing: submitting the form while the photo was still uploading captured an empty attachment and silently dropped it, with no error shown and nothing recorded anywhere. Submission now waits for the upload to finish, showing "Fin de l'envoi de la photo…" while it does. The upload can take several seconds on a phone (HEIC conversion, anti-spam Proof of Work, then the transfer itself), so this was easy to hit in practice.
* Fixed a related case where dismissing the file picker on some mobile browsers discarded an already-uploaded photo without any message. Only the Remove button clears an attached photo now.
* Lead Vault entries now record when a visible optional photo field was submitted with no photo, so a missing attachment is visible to an administrator instead of leaving no trace. Photo fields hidden by conditional display are correctly not reported.

= 0.6.5 =
* Removed a hardcoded 760px maximum width from the public form, which made it render narrower than the surrounding page content on many themes. The form now fills its container width, matching the active theme's own content column like the rest of the page.

= 0.6.4 =
* Fixed a rare timing issue where a duplicated or retried form submission (for example, a slow network causing the visitor's browser to resend the same request) could send the notification email before the attached photo was linked to it, leaving the email without its attachment even though the lead itself was stored correctly. Only the request that actually creates the lead now claims the photo and sends the notification.

= 0.6.3 =
* Removed the redundant "Optional." prefix from the photo upload field's description text, since the field is already clearly marked as not required.

= 0.6.2 =
* IT services template: the "Attach a photo of the error" field now appears after the service address field instead of before it. Already-created forms self-repair to the new order automatically the next time their schema is read, with no manual migration.

= 0.6.1 =
* Copywriting fix: removed the em dash and stray colons from the photo upload field's English and French text (the accepted-formats hint, the optional-field description, the oversized-file message, and the Lead Vault "what was actually sent" panel), and filled in a few French translations that had been left blank.

= 0.6.0 =
* Added a full automated test suite (PHPUnit + Pest, real WordPress bootstrap via wp-phpunit against a dedicated test database) covering existing reliability behavior (submission validation, Proof of Work, mail retry/dead-letter, entry lifecycle, template repairs, privacy exporter/eraser) and dedicated offensive-security tests for the photo upload feature (extension-bypass tricks, Content-Type spoofing, magic-byte/polyglot forgery, EXIF/GPS stripping, path traversal, decompression-bomb dimensions, upload-token replay/cross-form binding, concurrent-claim races, and IDOR on the admin attachment viewer)..
* Lead Vault: the entries list is no longer capped at the first 100 leads with no way to see the rest — it is now paginated, so every lead stays reachable regardless of volume.
* Lead Vault: added a per-form filter, so a site with more than one form can review each form's leads separately.
* Lead Vault: entry detail now shows a "What was actually sent" panel with the frozen subject/body that was really emailed (rendered in a sandboxed iframe), so an admin can always tell apart "nothing sent yet", "sent then redacted per this form's storage setting", and the real sent content — instead of only ever seeing the re-derived submitted-values table.
* Added a Rector-based code-quality audit (analysis only, no automatic changes) alongside the existing PHPCS/WordPress Coding Standards check; the audit specifically flagged that a couple of Rector's own suggested rewrites (short array syntax, short ternaries) would conflict with this project's own coding standards, and one (`str_starts_with()`) would silently break the plugin's declared PHP 7.4 support — a useful reminder that automated refactoring tools need human review, not blind application.

= 0.5.0 =
* Added an optional, securely-validated photo upload field type. Every accepted image (JPEG, PNG, or WEBP) is fully decoded and re-encoded from scratch server-side — stripped of all EXIF/GPS/metadata, auto-oriented, and downscaled — before it ever touches permanent storage. The re-encoded file is saved under a random filename in a hardened storage directory; the supported access path is an authenticated wp-admin session with an explicit capability check and a per-entry nonce.
* At the time of the 0.5.0 release, HEIC/HEIF photos were converted to JPEG client-side before upload so the server never decoded HEIC/HEIF bytes. The current 0.7.2 behavior is stricter: no third-party HEIC/WASM decoder is bundled, and HEIC conversion is available only through a browser-native decoder when supported.
* The new upload endpoint uses its own lightweight, decoupled Proof of Work challenge and a tighter rate limit than form submission, and never accepts an uploaded file's declared type or extension as fact.
* This reverses the 0.4.0 decision to reject all multipart/file-upload requests outright; that hardening remains unchanged for every other endpoint, and the new upload endpoint is intentionally narrow and defensive.
* Added the built-in "Attach a photo of the error" field (optional) to the IT services contact starter template, shown only when the visitor selects home computer support or computer repair. Existing forms created from this template are upgraded automatically the next time they are loaded, with no manual migration step.
* Added a new wp_leadealer_uploads table (schema version 4) and a daily cleanup pass that removes attached photos once their Lead Vault entry is redacted, expires, or is erased via a personal-data request, and separately reclaims uploads that were never attached to a submission.

= 0.4.5 =
* Fixed a bug where saving a form's notification settings with an empty Subject field froze that blank value forever, instead of falling back to the translated default subject as intended. Existing forms are repaired automatically, with no migration needed, because the default is resolved every time the form is read.
* Added a safeguard in the mail sender so wp_mail() can never be called with an empty Subject header, which many mail providers silently spam-filter or drop without ever reporting an error back to WordPress.
* Applied the same fix to the Success message field, which had the same blank-value bug.

= 0.4.4 =
* Refined the IT services starter template with clearer customer-facing wording for the service selector.

= 0.4.3 =
* Automatically repairs the exact legacy IT services starter-template schema by inserting the missing required Message bubble field at runtime and in the builder, without altering unrelated/custom forms.
* Purges page caches after a form is saved so cached shortcode HTML cannot keep showing the previous schema.
* Added a regression test for the legacy starter-template repair.

= 0.4.1 =
* Added a reusable Message bubble appearance for long-text fields while keeping a native accessible textarea.
* Added a required “How can we help?” message field to the built-in IT services template.
* Added the new appearance controls and template strings to the French translation files.

= 0.4.0 =
* Replaced native HTML5 drag and drop with a grid-aware Pointer Events builder using a live placeholder, palette-to-canvas insertion, auto-scroll, and keyboard reordering.
* Bound public submissions strictly to the published form schema and reject unexpected field keys.
* Added a 256-bit browser-held idempotency secret so only the original browser can resume an already-stored submission after a lost HTTP response.
* Removed internal Lead Vault database IDs from public REST success responses.
* Restricted public endpoints to bounded JSON bodies with top-level key allowlists and no-store/nosniff response headers.
* Hardened Proof of Work token structure, expiry validation, entropy failure handling, and filtered client IP validation.
* Changed manual mail retries from a state-changing GET link to a nonce-protected POST form.
* Made rate limiting fail closed if its security ledger cannot be updated or read.
* Preserve unresolved and dead-letter leads regardless of the age-based retention window; retention now applies only after mail handoff.
* Kept file uploads unsupported and reject multipart submission requests, reducing exposure to the dominant 2026 form-builder upload/RCE vulnerability class.

= 0.3.0 =
* Rebuilt notification emails as a restrained 600px responsive table-based template with inline CSS and no external assets.
* Mail content now follows schema order, field labels, validated conditional visibility, and field-aware formatting.
* Added safe mailto, tel, and URL links and a defensive stable-choice-to-label resolver.
* Added an exact saved-schema email preview to the Notification panel.
* Scoped delivery alerts to Leadealer screens and added a passive Lead Vault menu badge.
* Delivery alerts are dismissible per user and reappear only when a newer dead-letter lead needs attention.

= 0.2.0 =
* Added Lead Vault as the durable source of truth for accepted submissions.
* Store accepted leads and frozen notification snapshots before attempting mail delivery.
* Added atomic mail claims, six bounded retries, progressive backoff, crash watchdog, and redundant recovery worker.
* Added dead-letter handling, administrator retry, delivery counters, filters, and per-lead reliability timeline.
* Changed public success semantics so SMTP or mail failures cannot turn an already stored lead into a visitor-facing submission error.
* Improved submission idempotency for lost HTTP responses and duplicate browser requests.

= 0.1.0 =
* Initial Leadealer release.

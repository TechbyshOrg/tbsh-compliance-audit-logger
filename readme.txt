=== Compliance Audit Trail & Evidence Logger ===
Contributors: techbysh
Tags: compliance, audit-trail, security-log, tamper-evident, nis2
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Tamper-evident audit trail, incident response, risk & vendor registers, signed reports and encrypted off-site evidence for NIS2, DORA, ISO 27001.

== Description ==

Is your website ready for a cybersecurity audit?

When a website is hacked, the first thing intruders do is modify database logs to cover their tracks. Standard security plugins log user actions, but they do not guarantee that the logs themselves have not been altered or deleted.

**Compliance Audit Log & Tamper-Evident Security Trail** transforms your WordPress dashboard into a secure GRC (Governance, Risk, and Compliance) platform. By combining real-time activity tracking with cryptographic SHA-256 chain verification, it ensures your security audit trails are tamper-evident, verifiable, and defensible in an audit. On top of the audit trail it adds incident response with NIS2 deadline tracking, risk and third-party registers, signed compliance reports and encrypted off-site evidence backups.

Every feature described below is included in this plugin. There is no separate paid edition.

= Cryptographic Log Integrity =
Every log entry calculates a SHA-256 signature containing its contents and the signature of the preceding row. If an unauthorized actor inserts, deletes, or edits a log record directly in the database, the cryptographic chain breaks. The built-in verifier identifies exactly when and where the compromise occurred.

= Fulfill Global Compliance Regulations =
Designed to assist compliance officers, developers, and security teams in meeting strict international guidelines:
*   **NIS2 Directive (EU)**: Article 21 risk management and logging, plus Article 23 incident reporting deadlines (24 hours / 72 hours / 1 month).
*   **DORA (Digital Operational Resilience Act)**: ICT incident records, resilience testing with RTO/RPO, and an ICT third-party register (Article 28).
*   **SOC 2 & ISO/IEC 27001:2022**: Access control monitoring, change management and configuration audit evidence.
*   **GDPR & HIPAA**: Mask IPs using HMAC-SHA-256 salted hashes, support data exports and erasures, and enforce retention periods.

= Features =

**Audit trail & integrity**

*   **Tamper-Evident Chaining:** Link logs cryptographically using SHA-256 signatures and verify the chain on demand or with WP-CLI.
*   **Granular Activity Tracking:** Log logins and failed logins, user, role and capability changes, Super Admin grants, plugin and theme changes, edits made in the theme and plugin file editor, content and edits to published content, media, menus, widgets, Customizer and Site Editor changes, comment moderation, categories and tags, settings, privacy requests, content exports, multisite network changes and application passwords. Bulk actions are summarised so the log stays readable.
*   **Core File Integrity:** Compare WordPress core files against the official checksums from WordPress.org, manually or on a schedule.
*   **Plugin, Theme & Uploads File Integrity:** Compare WordPress.org plugins with their official per-version checksums, other plugins, themes and must-use plugins with a signed local baseline (refreshed after updates), and find PHP files hidden in the uploads folder. Changes open an incident.
*   **WooCommerce Audit Trail:** Order status changes and edits by staff, refunds, deleted orders, product price, stock and download changes, coupons and store settings, without logging customer data.
*   **Active Sessions:** See who is signed in on which device and end a session or sign a user out everywhere during an incident.
*   **Logging Exclusions:** Silence routine activity from service accounts, roles or event types; security-relevant events and errors are always kept.
*   **AI Agent Attribution:** Every entry records who or what made the change (a person, an AI agent, an integration, an automated task or a visitor) and how it arrived (browser, REST API, MCP, XML-RPC, cron or WP-CLI), including the application password and the WordPress ability used. Ability runs by AI agents and MCP servers are logged, with refused and failed attempts. Filter the log by "Made by".
*   **Developer API:** Add your own events to the tamper-evident log with `tbsh_cal_log()` or `do_action( 'tbsh_cal_log', ... )`.
*   **Evidence Vault:** Capture point-in-time snapshots of themes, plugins, capabilities and system configuration, manually or daily/weekly.
*   **GDPR Privacy Masking:** Anonymize client IP addresses and user agents using local cryptographic salts, with WordPress personal data exporter and eraser support.
*   **Forensic Timeline:** Browse events chronologically through a responsive, filterable layout.
*   **SIEM Export:** Compile logs into CSV or JSON formats, with optional expiring share links.
*   **Real-time SIEM Forwarding:** Stream every entry with its chain hash to an HTTPS endpoint, Splunk HTTP Event Collector or a syslog server over TLS/TCP, with automatic retries.
*   **Compliance Digest:** A weekly or monthly email summarising activity, incidents and NIS2 deadlines, integrity, readiness and governance, with a signed report attached as evidence of regular log review.
*   **Setup Assistant:** Pick your frameworks and apply recommended alerts, scheduled checks and retention in four steps.

**Incident response**

*   **Incident Center:** Record incidents with severity, status, assignee, root cause, impact, notes and linked evidence snapshots.
*   **Automatic Detection:** Opens incidents for brute-force logins, administrator privilege grants, critical site setting changes, core checksum failures, audit-log tampering, failing off-site backups and high-risk behaviour anomalies. Each rule can be switched off.
*   **NIS2 Article 23 Tracking:** For significant incidents, tracks the early warning (24 hours), incident notification (72 hours) and final report (1 month) with due dates, references and overdue reminders.
*   **Multi-Channel Alerts:** Email, Slack, Discord, Microsoft Teams (Workflows), Telegram and a generic HMAC-signed webhook. Alerts are queued and delivered in the background by WP-Cron with automatic retries, and every delivery is recorded in the Alert Center.
*   **Optional AI Incident Analysis:** Ask Anthropic Claude, OpenAI, Google Gemini or any OpenAI-compatible endpoint (such as a local Ollama server) for a summary, likely causes and recommended actions. Off by default; only incident fields are sent, never log rows or IP hashes.

**Governance**

*   **Risk Register:** Score risks by likelihood and impact on a 5×5 heat map, with owners, review dates and mitigation plans.
*   **Vendor & ICT Third-Party Register (DORA Art. 28):** Track criticality, data access, risk rating, contract renewals and security reviews, with reminders 30 and 7 days ahead.
*   **Software Supply-Chain Inventory:** Lists installed plugins and themes with pending updates (from WordPress's own update data) and every outbound integration you have configured.
*   **Operational Resilience:** Plan and record backup-restore, failover, incident-response and tabletop drills with RTO/RPO targets and actual results.
*   **Behavioural Anomaly Detection (opt-in):** Learns each user's normal hours and actions over 30 days and flags unusual activity.

**Evidence & reporting**

*   **Executive Dashboard & Readiness Score:** Computed from your real data, with framework coverage for NIS2, DORA, ISO/IEC 27001:2022 and SOC 2.
*   **Signed Compliance Reports:** Executive, NIS2, DORA, ISO/IEC 27001, SOC 2, incident, risk and third-party reports in print-ready HTML (use your browser's "Save as PDF"), CSV or JSON. Each report is signed with SHA-256 and HMAC-SHA256 and can be verified later.
*   **Encrypted Off-Site Evidence Vaults:** Push encrypted evidence packages (logs, incidents and snapshots) to S3-compatible storage (AWS S3, Google Cloud Storage via HMAC interoperability keys, Cloudflare R2, Wasabi, Backblaze B2, MinIO), an FTP/FTPS server, or another WordPress site running this plugin. Packages are encrypted with libsodium secretstream (XChaCha20-Poly1305) using a PBKDF2-derived passphrase key, and can be restored with `wp tbsh-cal vault-decrypt` or the bundled `tools/decrypt-vault.php`, even without WordPress.
*   **Read-Only Auditor Links:** Give external auditors expiring (up to 90 days), revocable, read-only access. Tokens are stored only as hashes.
*   **Unified Data Retention:** Per-type retention for logs (archived, then purged with hash-chain re-anchoring), closed incidents, evidence and alert/vault delivery history.
*   **WP-CLI:** `wp tbsh-cal vault-decrypt`, `wp tbsh-cal vault-sync` and `wp tbsh-cal verify-chain`.
*   **Encrypted Credentials:** API keys, webhook URLs and vault credentials are encrypted at rest and never sent back to the browser. Define `TBSH_CAL_ENCRYPTION_KEY` in wp-config.php to decouple them from your WordPress salts.
*   **Granular Capabilities:** Separate view/manage capabilities for logs, evidence, incidents, risks, vendors, reports, vaults, alerts, analytics and auditor access.

== Source Code ==

The complete, unminified source code and build tooling for this plugin can be found in our public repository at:
https://github.com/TechbyshOrg/tbsh-compliance-audit-logger

== External services ==

The plugin works without any external service. Apart from the items below, nothing leaves your server, and none of them is used until an administrator configures or triggers it. Alert emails are sent with your site's own mailer (`wp_mail()`), not through a service operated by this plugin.

1. **Google Apps Script** (https://script.google.com): optional deactivation feedback, sent to the plugin author (Techbysh) and stored in a Google Sheet. It is sent **only** when an administrator deactivates the plugin and clicks **Submit & Deactivate** in the feedback popup. **Skip & Deactivate** and closing the popup send nothing. It contains the chosen reason, any text typed, the plugin, WordPress, and PHP versions, the site language, whether the site is multisite, and the active theme name. The site URL and admin email are included only if the administrator ticks the opt-in checkbox. Privacy: https://policies.google.com/privacy Terms: https://policies.google.com/terms

2. **WordPress.org plugin checksums** (https://downloads.wordpress.org): plugin file integrity checks. Used only when an administrator runs a file scan in the Integrity Center, or when scheduled plugin and theme file scans are enabled in Settings (off by default) and the daily/weekly task runs. For each installed plugin, the plugin's folder name and version are requested to download its official checksums; nothing else is sent. Results are cached for a day. Privacy: https://wordpress.org/about/privacy/ Terms: https://wordpress.org/about/license/

3. **WordPress.org API** (https://api.wordpress.org): core file integrity checks. Used only when an administrator runs a core checksum check, or when automatic core checksum verification is enabled in Settings (off by default) and the daily/weekly task runs. WordPress requests the official checksums for your WordPress version and locale; nothing else is sent. The supply-chain inventory reuses the update data WordPress already fetches and makes no extra request. Privacy: https://wordpress.org/about/privacy/ Terms: https://wordpress.org/about/license/

4. **Anthropic API** (https://api.anthropic.com): optional AI incident analysis. Used only when "Anthropic Claude" is selected as the AI provider with an API key, and only when a user clicks to analyse a specific incident. Sent: that incident's title, type, severity, status, detection time, description, affected systems, impact assessment, root-cause notes and compliance tags, with your API key. Log rows, IP hashes and user records are never sent. Terms: https://www.anthropic.com/legal/commercial-terms Privacy: https://www.anthropic.com/legal/privacy

5. **OpenAI API** (https://api.openai.com): optional AI incident analysis, under the same conditions and with the same incident fields as item 4, when "OpenAI" is the selected provider. Terms: https://openai.com/policies/business-terms/ Privacy: https://openai.com/policies/privacy-policy/

6. **Google Gemini API** (https://generativelanguage.googleapis.com): optional AI incident analysis, under the same conditions and with the same incident fields as item 4, when "Google Gemini" is the selected provider. Terms: https://aistudio.google.com/docs/terms Privacy: https://policies.google.com/privacy

7. **OpenAI-compatible endpoint** (the URL the administrator enters, for example a self-hosted Ollama, LM Studio or vLLM server): optional AI incident analysis, under the same conditions and with the same incident fields as item 4. The terms and privacy policy of whoever operates that endpoint apply.

8. **Slack** (the incoming-webhook URL the administrator enters, on https://hooks.slack.com): optional alerts. Sent only when the Slack channel is enabled and an alert at or above the configured minimum severity is raised, or when a test alert is sent. Contains the alert type, severity, subject and message (which may include a username, user ID, option name or vault ID from the triggering event), your site name and URL, the time and a link to the plugin's admin screen. Terms: https://slack.com/terms-of-service Privacy: https://slack.com/trust/privacy/privacy-policy

9. **Discord** (the webhook URL the administrator enters, on https://discord.com): optional alerts, under the same conditions and with the same alert content as item 8. Terms: https://discord.com/terms Privacy: https://discord.com/privacy

10. **Microsoft Teams** (the Teams Workflows webhook URL the administrator enters, hosted by Microsoft): optional alerts, under the same conditions and with the same alert content as item 8. Terms: https://www.microsoft.com/servicesagreement Privacy: https://privacy.microsoft.com/privacystatement

11. **Telegram Bot API** (https://api.telegram.org): optional alerts, under the same conditions and with the same alert content as item 8, sent with your bot token to the chat ID you configure. Terms: https://telegram.org/tos Privacy: https://telegram.org/privacy

12. **Custom webhook** (the https URL the administrator enters): optional alerts, under the same conditions as item 8. The alert is sent as JSON (type, severity, subject, message, context such as an incident ID, site name and URL, time and admin link), signed with HMAC-SHA256 when a signing secret is set. The terms and privacy policy of whoever operates that endpoint apply.

13. **S3-compatible object storage** (the endpoint the administrator enters; Amazon S3 in the chosen region when left empty): optional off-site evidence vault. Used only after an administrator creates and activates an S3-compatible vault: when "Test connection" is clicked (a small probe object is written and deleted), on each scheduled sync (hourly, daily or weekly), and on manual or WP-CLI syncs. Sent: an encrypted package containing audit log rows added since the last sync, the incident register with notes, and evidence snapshots captured since the last sync, plus your access key for request signing. The file name contains your site's host name and a timestamp. The content is encrypted on your server with your vault passphrase, which is never sent. Terms and privacy policies of common providers: Amazon S3 https://aws.amazon.com/service-terms/ and https://aws.amazon.com/privacy/ ; Google Cloud Storage https://cloud.google.com/terms and https://cloud.google.com/terms/cloud-privacy-notice ; Cloudflare R2 https://www.cloudflare.com/terms/ and https://www.cloudflare.com/privacypolicy/ ; Wasabi https://wasabi.com/legal/terms-of-use and https://wasabi.com/legal/privacy-policy ; Backblaze B2 https://www.backblaze.com/company/policy/terms-of-service and https://www.backblaze.com/company/policy/privacy . For any other endpoint (for example your own MinIO server), the terms of whoever operates it apply.

14. **FTP / FTPS server** (the host the administrator enters): optional off-site evidence vault, under the same conditions and with the same encrypted package as item 13, plus the FTP username and password you configured. Use FTPS (the default) so credentials are not sent in clear text. The terms and privacy policy of whoever operates the server apply.

15. **Another WordPress site running this plugin** (the receiver URL the administrator enters): optional off-site evidence vault, under the same conditions and with the same encrypted package as item 13. Each request also carries your site URL, the file name, a timestamp and an HMAC-SHA256 signature made with the receiver's token. The receiving site stores the package encrypted and never learns the passphrase. The terms and privacy policy of whoever operates that site apply.

16. **Your SIEM or log platform** (the HTTPS endpoint, Splunk HTTP Event Collector URL or syslog host the administrator enters): optional real-time log forwarding. Used only after an administrator enables SIEM forwarding in Settings, and when a test event is sent. Sent: each new audit log entry at or above the chosen severity (event type, category, severity, title, message, username, user ID and role, object type and ID, hashed IP address, request method and path, compliance tags, event metadata and the entry's chain hashes), your site URL and, for HTTPS and Splunk, the token you configured. Private and internal addresses are refused unless a developer allows them. The terms and privacy policy of whoever operates that destination apply.

== Installation ==

1. Back up your database if you are upgrading.
2. Upload the `tbsh-compliance-audit-logger` folder to the `/wp-content/plugins/` directory.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Navigate to 'Compliance Logs' in the admin sidebar.
5. Run your first database integrity check in the 'Integrity Center'.
6. Optional: configure alert channels, detection rules, retention and AI analysis in 'Settings', and add an off-site evidence vault.
7. Recommended: before saving any credentials, add `define( 'TBSH_CAL_ENCRYPTION_KEY', 'a-random-string-of-at-least-32-characters' );` to wp-config.php so rotating your WordPress salts does not make stored credentials unreadable.

If you used the separate edition of this plugin before, see "I used the separate edition. What happens when I upgrade?" below.

== Frequently Asked Questions ==

= How does the cryptographic hash chain protect my logs? =
Every log entry combines its ID, message, timestamp, and metadata with the hash of the preceding entry to calculate its own SHA-256 signature. If a database record is altered, its signature changes, causing validation checks to fail for that row and all subsequent rows.

= Does this plugin affect page load times? =
No. The plugin uses an asynchronous-like queue. Logs are stored in memory during the runtime and written to the database during the WordPress `shutdown` hook, after the page has finished loading for the visitor. Alert delivery, vault uploads and retention run in the background through WP-Cron, never during a page view.

= Can I use this on a WordPress Multisite network? =
Yes. The plugin is fully multisite-ready, supports network-wide activation, and stores logs with site identifiers to allow filtering by site.

= Are IP addresses stored in a GDPR-compliant manner? =
Yes. When IP address anonymization is enabled (default), client IPs are salted and hashed using HMAC-SHA-256 before insertion. They cannot be reversed to reveal the raw IP.

= Are there any limits? =
No. There is no limit on the number of logs, incidents, risks, vendors, reports, exports, evidence snapshots or vaults, and no feature is reserved for a paid plan.

= How do I run a database validation check? =
Go to 'Compliance Logs' -> 'Integrity Center' and click the 'Run Integrity Verification' button. The verifier will scan all records and report if any anomalies are found. You can also run `wp tbsh-cal verify-chain`.

= What happens if the hash chain is compromised? =
If the Integrity Center reports a compromised status, it lists the exact log ID where the validation failed, indicating that the database row was modified or deleted. With tamper detection enabled (default), an incident is opened and an alert is queued automatically.

= How does the Evidence Vault work? =
The Evidence Vault takes a snapshot of your site's technical configuration (plugins list, theme versions, user capability definitions, security constants). It provides proof of the site's state at a specific point in time. Snapshots can be linked to incidents and copied off-site with an encrypted vault.

= How do off-site evidence vaults work? =
Add a vault (S3-compatible storage, FTP/FTPS, or another WordPress site running this plugin) with an encryption passphrase. On its schedule, the plugin builds a package with new log rows, the incident register and new evidence snapshots, encrypts it on your server and uploads it. Keep the passphrase safe: without it a package cannot be decrypted. To restore, run `wp tbsh-cal vault-decrypt <file>` or `php tools/decrypt-vault.php <file>` (works without WordPress).

= Is AI analysis enabled by default? =
No. It stays off until an administrator picks a provider, model and API key (or endpoint), and it runs only when someone clicks to analyse a specific incident. Only incident fields are sent. The key is stored encrypted.

= Can I generate a PDF report? =
Reports are produced as print-ready HTML, CSV or JSON. Open the HTML report and use your browser's "Print" -> "Save as PDF". Each stored report is signed and can be verified in 'Compliance Reports'.

= How do auditor links work? =
In 'Auditor Access', create a link with a label and a lifetime of 1 to 90 days. The link is shown only once. It gives read-only access to dashboards, logs, evidence, incidents, registers and reports, and can be revoked at any time.

= Can I export logs for external analysis? =
Yes. You can generate CSV or JSON log exports from the Export Center, using filters like date ranges, compliance categories, or severity levels.

= Does this plugin support capability-based access? =
Yes. Administrators receive every capability by default: `tbsh_cal_view_logs`, `tbsh_cal_view_evidence`, `tbsh_cal_export_data`, `tbsh_cal_manage_settings`, `tbsh_cal_verify_integrity`, `tbsh_cal_view_incidents`, `tbsh_cal_manage_incidents`, `tbsh_cal_view_risks`, `tbsh_cal_manage_risks`, `tbsh_cal_view_vendors`, `tbsh_cal_manage_vendors`, `tbsh_cal_view_reports`, `tbsh_cal_manage_reports`, `tbsh_cal_manage_vaults`, `tbsh_cal_view_alerts`, `tbsh_cal_manage_alerts`, `tbsh_cal_view_analytics`, `tbsh_cal_manage_auditor_access` and `tbsh_cal_manage_sessions`. Grant them to other roles with any role editor.

= Can I add my own events to the audit log? =
Yes. After `plugins_loaded`, call `tbsh_cal_log( 'invoice_approved', array( 'title' => 'Invoice approved', 'message' => 'Invoice 1042 approved by finance.', 'severity' => 'notice', 'object_type' => 'invoice', 'object_id' => 1042 ) )` or `do_action( 'tbsh_cal_log', 'invoice_approved', array( ... ) )`. Entries are hash-chained like every other event. Event types used by the detection rules are reserved, and a request can add at most 200 entries.

= Can I tell changes made by AI agents from changes made by people? =
Yes. Every entry records who made it: a person, an AI agent, an integration (an application password without an ability), an automated task (cron or WP-CLI) or a visitor, plus the channel and the application password or WordPress ability involved. A request counts as an AI agent when it arrives through an MCP server route or runs an ability with an application password. Filter Activity Logs by "Made by", and see the count in the compliance digest. Abilities that change the site are logged as their own entries (read-only abilities are skipped, and only input field names are kept, never values). Plugins that run their own AI assistant can mark their changes with the `tbsh_cal_request_origin` filter.

= How do I forward logs to my SIEM? =
In Settings → SIEM & Log Forwarding, choose an HTTPS JSON endpoint, Splunk HTTP Event Collector or syslog over TLS/TCP, enter the destination and save, then click "Send test event". New entries are sent within minutes, in order, with their chain hashes; if the destination is down they are retried automatically and an alert is raised after an hour.

= What does the file integrity scan check? =
Plugins from WordPress.org are compared with the official checksums for their exact version, including PHP files that should not be there. Other plugins, themes and must-use plugins are compared with a baseline recorded on the first scan and refreshed automatically after updates; if you change files on purpose, use "Accept current files" in the Integrity Center. The uploads folder is searched for PHP files. Any change opens an incident.

= How often does it capture automated snapshots? =
If enabled in settings, the plugin runs a daily or weekly cron job to capture system configuration archives.

= Why are alerts or vault syncs not arriving? =
They are delivered by WP-Cron. If your site has little traffic or `DISABLE_WP_CRON` is set, make sure a real cron job calls `wp-cron.php`. The Alert Center shows each delivery attempt and its error, and System Health checks that the scheduled tasks exist.

= Does it support WP GDPR Personal Data Erasure? =
Yes. When a WordPress data erasure request is completed for a user, the plugin anonymizes their data and automatically rebuilds the hash chain to keep the cryptographic audit trail unbroken.

= Where is the log data stored? =
All data is stored locally in dedicated custom database tables (`wp_tbsh_cal_logs`, `wp_tbsh_cal_evidence`, `wp_tbsh_cal_integrity`, plus tables for incidents, risks, vendors, reports, vaults, alert history, auditor links and resilience drills). Generated reports, log archives and vault packages are stored in `wp-content/uploads/tbsh-compliance-private/`, which is protected on Apache. On nginx, add a deny rule for that folder; System Health tells you if it is publicly readable.

= What compliance tags are assigned to events? =
Events are automatically tagged with compliance categories such as Access Control, Identity Management, Authentication, Change Management, Security Monitoring, and Operational Security.

= Can I use this for security audit readiness? =
Yes. The Compliance Overview and Executive Dashboard compute a readiness score from your logs, settings, verification history, incidents, registers and drills, and map it to controls in ISO/IEC 27001:2022, SOC 2, NIS2 and DORA.

= I used the separate edition. What happens when I upgrade? =
Back up your database first. Once this version is active, the separate edition is deactivated automatically and its data is migrated: tables are renamed, settings and credentials are re-encrypted, capabilities are renamed, and existing auditor links keep working. Azure and SFTP vaults are no longer supported and are deactivated; migrated Google Cloud Storage and remote-site vaults stay inactive until you enter their new credentials. After the migration, delete the old edition from the Plugins screen while this plugin is still active.

== Screenshots ==

1. Executive compliance dashboard displaying security scores, database integrity states, and framework readiness checkers.
2. Granular activity log grid displaying event parameters and SHA-256 cryptographic signatures.
3. Forensic timeline view tracing administrative actions chronologically.
4. Evidence Vault for capturing and storing technical configuration snapshots as audit evidence.
5. Integrity Center showing cryptographic hash chain verification scan histories and database status.
6. Compliance Overview mapping active audit logs and controls to global compliance frameworks (NIS2, DORA, GDPR, ISO 27001).
7. System Health checkup showcasing technical diagnostics and environment settings logs.
8. Export Center compiling logs into CSV or JSON exports with customized parameters.
9. Settings Module configuring retention limits, IP anonymization, and automatic cron triggers.
10. Help and Technical Reference page containing implementation details and support guidelines.

== Changelog ==

= 2.0.0 =
* New: Plugin, theme and uploads file integrity scans (WordPress.org checksums plus signed local baselines), manual or scheduled, with incidents on change.
* New: WooCommerce audit trail for staff order changes, refunds, deleted orders, product price/stock/download changes, coupons and store settings.
* New: Active Sessions screen to end a login session or sign a user out everywhere (new capability `tbsh_cal_manage_sessions`).
* New: Real-time SIEM forwarding (HTTPS JSON, Splunk HEC, syslog over TLS/TCP) with retries and failure alerts.
* New: Weekly or monthly compliance digest email with a signed executive report attached.
* New: AI agent attribution. Each entry records whether a person, an AI agent, an integration or an automated task made it, through which channel and with which application password or ability; WordPress ability runs (6.9+), refusals and failures (7.1+) are logged. Activity Logs and exports can be filtered by who made the change.
* Improved: Accessibility and usability across the admin: dialogs keep keyboard focus, status messages are announced, focus is always visible, text and form fields meet WCAG 2.2 AA contrast in light and dark mode, and every filter is labelled.
* Improved: Every page shows its title and purpose, the menu collapses on small screens, wide tables scroll instead of being cut off, and dark mode follows your system setting until you choose.
* Improved: The Dashboard shows what needs attention (critical events today, open incidents, file changes, unverified log) and every figure opens the matching filtered activity log.
* Improved: Activity Logs shows times in your site's date format, lists active filters with one-click removal, and remembers your column choice; Settings shows an unsaved-changes bar with Save and Discard and links to each section.
* Improved: Accepting the current files of a very large plugin or theme as its integrity baseline now runs in short steps with progress, so it no longer hits server time limits.
* New: Setup assistant for new installs, logging exclusions for service accounts, roles and event types, and a public `tbsh_cal_log()` developer API.
* Improved: The admin app now loads each screen on demand, making the first load much smaller.
* New: Wider activity coverage: theme and plugin file editor edits (with before/after file hashes), roles and capabilities granted outside the role dropdown, Super Admin changes, edits to published content, menus, widgets, theme customisations, Customizer and Site Editor changes, comment moderation, category and tag changes, privacy requests, content exports and multisite network changes.
* New: Direct grants of administrator or other sensitive capabilities are flagged as privilege escalation, and file editor changes open an incident under the configuration-change rule.
* The separate edition is merged into this plugin: every feature is now included, with no paid tier.
* New: Incident Center with automatic detection rules and NIS2 Article 23 (24h / 72h / 1 month) tracking.
* New: Alerts via email, Slack, Discord, Microsoft Teams Workflows, Telegram and signed webhooks, queued with retries and a delivery log.
* New: Risk register with 5×5 heat map, vendor/ICT third-party register (DORA Art. 28) with reminders, and software supply-chain inventory.
* New: Operational resilience drill register with RTO/RPO, and opt-in behavioural anomaly detection.
* New: Executive dashboard and readiness engine with NIS2, DORA, ISO/IEC 27001:2022 and SOC 2 coverage.
* New: Signed, verifiable compliance reports (print-ready HTML, CSV, JSON).
* New: Encrypted off-site evidence vaults (S3-compatible, FTP/FTPS, another WordPress site) with `wp tbsh-cal vault-decrypt` and a standalone decryptor.
* New: Expiring, revocable read-only auditor links.
* New: Optional AI incident analysis (Anthropic, OpenAI, Google Gemini, OpenAI-compatible), off by default.
* New: Unified data retention with log archiving and hash-chain re-anchoring.
* New: WP-CLI commands `vault-decrypt`, `vault-sync` and `verify-chain`.
* New: Credentials encrypted at rest, with an optional `TBSH_CAL_ENCRYPTION_KEY` constant.
* Automatic migration of data, settings, credentials, capabilities and auditor links from the separate edition, which is deactivated automatically.

= 1.1.0 =
* Hardened cryptographic logging (flush locking, retention chain repair, activation flush).
* Fixed GDPR privacy exporter/eraser registration and failed-login alert detection.
* Expanded activity tracking for content, media, plugin deletion, and application passwords.
* Added email alerts for critical events, core file checksum checks, and expiring export share links.
* Improved admin UX with capability-gated navigation, safer API error handling, and timeline pagination.
* Tested up to WordPress 7.1.

= 1.0.3 =
* General Bug Fixes and Improvements.

= 1.0.2 =
* General Bug Fixes and Improvements.

= 1.0.1 =
* General Bug Fixes and Improvements.

= 1.0.0 =
* Initial release.
* Added cryptographic SHA-256 logs chaining.
* Implemented point-in-time evidence snapshots.
* Added GDPR-compliant IP masking controls.
* Installed compliance checklists for DORA, NIS2, and ISO 27001.

== Upgrade Notice ==

= 2.0.0 =
Major release: back up your database first. All features of the former separate edition are now included. If it is installed, it is deactivated automatically and its data migrated; then delete it while this plugin is active.

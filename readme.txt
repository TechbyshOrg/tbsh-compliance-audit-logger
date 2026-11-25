=== Compliance Audit Log – Tamper-Evident Activity Log ===
Contributors: techbysh
Tags: audit-log, activity-log, audit-trail, compliance, nis2
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tamper-evident audit log and activity log for WordPress, with incident response, signed reports and audit evidence for NIS2, DORA and ISO 27001.

== Description ==

**Compliance Audit Log is a tamper-evident audit log for WordPress.** It records who changed what on your site (people, AI agents, integrations and scheduled tasks) and proves that the record has not been edited since.

It is built for site owners, agencies and security teams who need audit evidence for NIS2, DORA, ISO/IEC 27001 or SOC 2. Everything is free. There is no paid tier and no usage limit.

= Why a tamper-evident audit log? =

A normal activity log is just rows in your database. Anyone with database access can change or delete them, and nobody would notice.

Compliance Audit Log links every entry to the one before it with a SHA-256 hash. If a single entry is edited or removed, the chain breaks, and the built-in check shows exactly where. That turns your activity log into an audit trail you can show an auditor as evidence.

= How it works =

1. **Install and run the Setup Assistant.** Pick your frameworks (NIS2, DORA, ISO 27001, SOC 2) and apply recommended alerts, checks and retention in four steps.
2. **Let it record.** Sign-ins, user and role changes, plugin and theme changes, content, settings, WooCommerce changes and more are logged automatically, with very little overhead.
3. **Use the evidence.** Verify the log, handle incidents, create signed reports and give your auditor a read-only link.

= What it tracks =

* Sign-ins, failed sign-ins and application passwords.
* Users, roles and capabilities, including Super Admin grants and capabilities granted outside the role menu.
* Plugin and theme installs, updates, activations and deletions, and edits made in the theme and plugin file editor.
* Posts, pages and media, including edits to published content.
* Menus, widgets, Customizer and Site Editor changes, comments, categories and tags.
* Settings, privacy requests, content exports and multisite network changes.
* **WooCommerce:** staff order changes, refunds, deleted orders, product price, stock and download changes, coupons and store settings. Customer details are not logged.
* **AI agents:** each entry says who made the change (a person, an AI agent, an integration, an automated task or a visitor) and how it arrived (browser, REST API, MCP, XML-RPC, cron or WP-CLI). WordPress abilities run by AI agents are logged, including refused and failed attempts. Filter the log by "Made by".

Bulk actions are summarised so the log stays readable. Logging exclusions can silence routine activity from service accounts, roles or event types, while errors, critical events and security-relevant events are always kept.

= Protect and verify your log =

* **Hash-chain verification** in one click or with `wp tbsh-cal verify-chain`. If the chain breaks, an incident is opened and an alert is sent.
* **File integrity scans:** WordPress core and WordPress.org plugins are checked against official checksums. Other plugins, themes and must-use plugins are checked against a signed local baseline. The uploads folder is searched for hidden PHP files.
* **Evidence snapshots** of your plugins, themes, capabilities and configuration, taken manually or daily or weekly.
* **Encrypted off-site copies** of logs, incidents and snapshots on S3-compatible storage (AWS S3, Cloudflare R2, Wasabi, Backblaze B2, MinIO, Google Cloud Storage), an FTPS server or another WordPress site running this plugin.

= Respond to incidents =

* **Incident Center** with severity, status, owner, root cause, impact, notes and linked evidence.
* **Automatic detection** of brute-force sign-ins, new administrators, critical setting changes, changed core or plugin files, log tampering, failing off-site copies and unusual user behaviour. Each rule can be switched off.
* **NIS2 Article 23 deadlines:** the 24-hour early warning, 72-hour notification and 1-month final report, with due dates and overdue reminders.
* **Alerts** by email, Slack, Discord, Microsoft Teams, Telegram or a signed webhook, sent in the background with retries.
* **Active sessions:** see who is signed in and sign a user out everywhere during an incident.
* **Optional AI incident analysis** with Anthropic Claude, OpenAI, Google Gemini or a self-hosted OpenAI-compatible model. Off by default.

= Governance registers =

* **Risk register** with a 5×5 likelihood and impact heat map, owners and review dates.
* **Vendor and ICT third-party register** (DORA Article 28) with contract and review reminders.
* **Software supply-chain inventory** of installed plugins and themes, pending updates and connected services.
* **Operational resilience drills** with RTO and RPO targets and results.
* **Behavioural anomaly detection** (opt-in) that learns each user's normal hours and actions.

= Evidence and reporting =

* **Readiness score** computed from your real data, with coverage for NIS2, DORA, ISO/IEC 27001:2022 and SOC 2.
* **Signed compliance reports:** executive, NIS2, DORA, ISO 27001, SOC 2, incident, risk and vendor reports as PDF, HTML, CSV or JSON. Each report is signed and can be verified later.
* **Read-only auditor links** that expire (after up to 90 days) and can be revoked. Auditors need no WordPress account.
* **Weekly or monthly compliance digest** email with a signed report attached.
* **Exports and SIEM forwarding:** CSV or JSON exports, or real-time forwarding to an HTTPS endpoint, Splunk HTTP Event Collector or syslog over TLS.
* **Site Network:** see the readiness, errors, incidents and log integrity of all your sites on one page.

= Perfect for =

* **EU businesses under NIS2 or DORA** that must log security events and report incidents on time.
* **Teams preparing for ISO 27001 or SOC 2** who need logging and change-management evidence.
* **Agencies** that manage client sites and need to show who changed what.
* **WooCommerce stores** that want accountability for staff changes to orders, prices and stock.
* **Sites using AI agents or MCP servers** that need to see what the agents changed.

= Compliance frameworks =

The plugin helps you collect evidence for these frameworks. It does not make a site compliant on its own, and it is not legal advice.

* **NIS2 Directive:** Article 21 logging and monitoring, Article 23 incident reporting deadlines.
* **DORA:** ICT incident records, resilience testing and the ICT third-party register.
* **ISO/IEC 27001:2022:** logging (A.8.15), monitoring (A.8.16) and evidence collection (A.5.28).
* **SOC 2:** access control and change-management evidence.
* **GDPR:** IP masking, personal data export and erasure, and retention periods.

= What it does not do =

* It is not a firewall or malware scanner, and it does not block attacks. Use it alongside your security plugin.
* It does not make your site compliant by itself. It gives you the records and reports that audits ask for.
* It only records activity from the time it is activated.

= Privacy and your data =

All data stays in your WordPress database and uploads folder. IP addresses and browser details are hashed by default. Nothing is sent to an outside service unless you set one up (see "External services" below). Credentials are encrypted at rest. Your data is kept when you uninstall, unless you choose to delete it in Settings.

= Documentation and support =

* Open **Compliance Logs → Help & Reference** in your dashboard for a guide to every feature.
* Ask questions in the [support forum](https://wordpress.org/support/plugin/tbsh-compliance-audit-logger/).
* Developers can add their own events with `tbsh_cal_log()`, and use WP-CLI commands and granular capabilities.

== Credits ==

PDF reports in non-Western scripts use DejaVu Sans (https://dejavu-fonts.github.io/), bundled in `assets/fonts/` with its license (Bitstream Vera license; DejaVu changes are in the public domain).

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

14. **FTP / FTPS server** (the host the administrator enters): optional off-site evidence vault, under the same conditions and with the same encrypted package as item 13, plus the FTP username and password you configured. Only FTPS is supported, so credentials are not sent in clear text (plain FTP is turned off unless a developer enables it with the `tbsh_cal_allow_plain_ftp` filter). The terms and privacy policy of whoever operates the server apply.

15. **Another WordPress site running this plugin** (the receiver URL the administrator enters): optional off-site evidence vault, under the same conditions and with the same encrypted package as item 13. Each request also carries your site URL, the file name, a timestamp and an HMAC-SHA256 signature made with the receiver's token. The receiving site stores the package encrypted and never learns the passphrase. The terms and privacy policy of whoever operates that site apply.

16. **Your SIEM or log platform** (the HTTPS endpoint, Splunk HTTP Event Collector URL or syslog host the administrator enters): optional real-time log forwarding. Used only after an administrator enables SIEM forwarding in Settings, and when a test event is sent. Sent: each new audit log entry at or above the chosen severity (event type, category, severity, title, message, username, user ID and role, object type and ID, hashed IP address, request method and path, compliance tags, event metadata and the entry's chain hashes), your site URL and, for HTTPS and Splunk, the token you configured. Private and internal addresses are refused unless a developer allows them. The terms and privacy policy of whoever operates that destination apply.

17. **Your other websites (Site Network)** (the sites an administrator connects with their auditor links): optional. Used only after an administrator connects a site, then hourly and when "Refresh" is clicked. Sent to that site: read-only requests with the auditor link token in a header. Received: that site's readiness score, event counts, open incidents, log integrity status and its latest five error or critical events. Only https and public addresses are contacted unless a developer allows private ones. Those sites are operated by you or your clients.

== Installation ==

1. In your dashboard, go to **Plugins → Add New Plugin** and search for "Compliance Audit Log".
2. Click **Install Now**, then **Activate**.
3. Open **Compliance Logs** in the admin menu and click **Start setup**. The Setup Assistant applies recommended settings for the frameworks you pick.
4. Open **Integrity Center** and click **Run Integrity Verification** to check the log for the first time.
5. Optional: in **Settings**, add alert channels, adjust detection rules and retention, and add an encrypted off-site vault.
6. Recommended before saving any credentials: add `define( 'TBSH_CAL_ENCRYPTION_KEY', 'a-random-string-of-at-least-32-characters' );` to `wp-config.php`, so changing your WordPress salts later does not make stored credentials unreadable.

To install manually, upload the `tbsh-compliance-audit-logger` folder to `/wp-content/plugins/` and activate it on the **Plugins** screen. If you are upgrading, back up your database first.

== Frequently Asked Questions ==

= What does Compliance Audit Log do? =
Compliance Audit Log records who changed what on a WordPress site and links every entry with a SHA-256 hash chain, so any later edit or deletion can be detected. On top of the audit log it adds incident response with NIS2 deadlines, risk and vendor registers, signed compliance reports, encrypted off-site copies and read-only auditor links.

= How is it different from a regular activity log plugin? =
A regular activity log shows what happened, but cannot prove that the log itself was not changed. Compliance Audit Log can: each entry includes the hash of the entry before it, and the verifier reports the first entry where the chain breaks. It also turns the log into audit evidence with incidents, signed reports, evidence snapshots and auditor access.

= How does the hash chain prove the log was not changed? =
Each entry's SHA-256 hash covers its contents (event, user, object, hashed IP, request, details and time) and the hash of the previous entry. If an entry is edited or deleted in the database, its hash no longer matches and the chain breaks from that point. Run the check in **Integrity Center** or with `wp tbsh-cal verify-chain`. With tamper detection on (the default), a broken chain opens an incident and sends an alert.

= Will it slow down my site? =
The impact is very small. Entries are collected during a request and written to the database once, at the end of the request. Alerts, off-site copies, SIEM forwarding and retention run in the background through WP-Cron, never during a page view.

= Does it make my site NIS2, DORA, ISO 27001 or SOC 2 compliant? =
No plugin can do that on its own. Compliance Audit Log gives you the records that these frameworks ask for: a protected audit log, incident records with NIS2 reporting deadlines, risk and third-party registers, resilience drills and signed reports. The readiness score shows which controls still need work.

= Is it GDPR friendly? =
Yes. IP addresses and browser details are hashed with a key unique to your site before they are stored (on by default). The plugin works with the WordPress personal data export and erasure tools; after an erasure it anonymises that user's entries and rebuilds the hash chain. Retention periods delete old data automatically.

= Who can see the audit log? =
Administrators can see everything. Access is controlled by separate capabilities for logs, evidence, incidents, risks, vendors, reports, vaults, alerts, analytics, sessions and auditor access, which you can give to other roles with any role editor. External auditors can get a temporary read-only link.

= Can I see what AI agents changed on my site? =
Yes. Every entry records whether a person, an AI agent, an integration, an automated task or a visitor made the change, and through which channel (browser, REST API, MCP, XML-RPC, cron or WP-CLI). A request counts as an AI agent when it arrives through an MCP server or runs a WordPress ability with an application password. Filter Activity Logs and exports by "Made by". Only the field names of an ability's input are kept, never the values.

= Does it work with WooCommerce? =
Yes. When WooCommerce is active, staff changes to orders, refunds, deleted orders, product prices, stock and downloads, coupons and store settings are logged. Customer personal data is not logged.

= Does it work on WordPress Multisite? =
Yes. It supports network activation, logs multisite network changes such as new sites and Super Admin grants, and stores the site ID with each entry. To see several separate sites on one page, use **Site Network**.

= Can an external auditor see the logs without a WordPress account? =
Yes. In **Auditor Access**, create a link with a label and a lifetime of 1 to 90 days. The link is shown once, stored only as a hash, gives read-only access to dashboards, logs, evidence, incidents, registers and reports, and can be revoked at any time.

= Can I export logs or send them to my SIEM? =
Yes. The Export Center creates CSV or JSON exports with filters for dates, categories, severity and who made the change, and can create expiring share links. For real-time forwarding, open **Settings → SIEM & Log Forwarding** and choose an HTTPS endpoint, Splunk HTTP Event Collector or syslog over TLS/TCP. Entries are sent in order with their chain hashes and retried if the destination is down.

= Can I create PDF compliance reports? =
Yes. In **Compliance Reports**, choose PDF, HTML, CSV or JSON. PDF files are created on your server without extra libraries. Every report is signed with SHA-256 and HMAC-SHA256 and can be verified later. PDFs show Latin (including Central and Eastern European), Greek, Cyrillic, Armenian, Georgian and Vietnamese text, using a bundled font only when a report needs it. For Arabic, Hebrew, Indic, Thai, Chinese, Japanese and Korean, choose HTML and use your browser's "Save as PDF".

= How do encrypted off-site vaults work, and how do I restore one? =
Add a vault (S3-compatible storage, FTPS, or another WordPress site running this plugin) and choose a passphrase. On its schedule, the plugin packs new log entries, the incident register and new evidence snapshots, encrypts them on your server (XChaCha20-Poly1305) and uploads them. Keep the passphrase safe: without it a package cannot be decrypted. To restore, run `wp tbsh-cal vault-decrypt <file>`, or use the standalone `decrypt-vault.php` from the plugin's GitHub repository (https://github.com/TechbyshOrg/tbsh-compliance-audit-logger/tree/main/tools), which runs on any computer with PHP and the sodium extension, without WordPress.

= Is AI analysis on by default, and what is sent? =
No, it is off by default. It works only after an administrator chooses a provider and API key (or a self-hosted endpoint), and only when someone clicks to analyse a specific incident. Only that incident's fields are sent. Log entries, IP hashes and user records are never sent.

= How long are logs kept, and what happens when I uninstall? =
You choose the retention period for each type of data in Settings. Old log entries are archived and then deleted, and the hash chain is re-anchored so verification still passes. When you uninstall, your data is kept unless you first set **Data Action on Uninstall** to "Delete All Tables and Settings" in Settings.

= Is there a paid version or any limits? =
No. Every feature is included for free, and there is no limit on logs, incidents, risks, vendors, reports, exports, snapshots or vaults.

= Why are alerts or vault syncs not arriving? =
They are sent by WP-Cron. If your site has little traffic or `DISABLE_WP_CRON` is set, make sure a real cron job calls `wp-cron.php`. The Alert Center shows each delivery attempt and its error, and System Health checks that the scheduled tasks exist.

= Where is the data stored? =
In dedicated database tables (such as `wp_tbsh_cal_logs` and `wp_tbsh_cal_evidence`) and, for reports, archives and vault packages, in `wp-content/uploads/tbsh-compliance-private/`. That folder is protected on Apache. On nginx, add a deny rule for it; System Health tells you if it is publicly readable.

= Can developers add their own events? =
Yes. After `plugins_loaded`, call `tbsh_cal_log( 'invoice_approved', array( 'title' => 'Invoice approved', 'severity' => 'notice', 'object_type' => 'invoice', 'object_id' => 1042 ) )` or `do_action( 'tbsh_cal_log', 'invoice_approved', array( ... ) )`. Custom entries are hash-chained like all others. Event types used by detection rules are reserved, and one request can add at most 200 entries. Plugins with their own AI assistant can mark their changes with the `tbsh_cal_request_origin` filter.

= I used the separate edition. What happens when I upgrade? =
Back up your database first. Once this version is active, the separate edition is deactivated automatically and its data is migrated: tables are renamed, settings and credentials are re-encrypted, capabilities are renamed, and existing auditor links keep working. Azure and SFTP vaults are no longer supported and are deactivated; migrated Google Cloud Storage and remote-site vaults stay inactive until you enter their new credentials. After the migration, delete the old edition from the Plugins screen while this plugin is still active.

== Screenshots ==

1. Dashboard: today's events, errors and failed sign-ins, what needs attention, and the latest activity.
2. Executive Dashboard: an overall compliance score and what drives it, with open incidents, risks, vendors and backups at a glance.
3. Activity Logs: search and filter every change and sign-in by user, severity, category, date and who made it.
4. Event details: the full record of one entry, with compliance tags and its SHA-256 tamper-evidence hashes.
5. Forensic Timeline: reconstruct an incident in time order, here an Author account briefly given Administrator rights.
6. Incident Center: security incidents from detection to closure, with NIS2 reporting deadlines.
7. Incident details: status, owner, notes, timeline, linked evidence, NIS2 stages and optional AI analysis.
8. Compliance Overview: readiness checks behind your NIS2, DORA, ISO/IEC 27001 and GDPR evidence.
9. Risk Register: a 5×5 likelihood and impact heat map with owners, treatment plans and review dates.
10. Vendor Governance: the site's software supply chain, with pending updates, inactive plugins and mapped vendors.
11. Evidence Vault: encrypted off-site backups of the audit log and evidence, with their sync history.
12. Integrity Center: verify the log hash chain, WordPress core checksums and plugin, theme and uploads files.
13. Compliance Reports: generate signed PDF, HTML, CSV or JSON reports for auditors and verify them later.
14. Behavioral Anomalies: activity that differs from each user's usual behaviour, with the reasons for each score.
15. Settings: choose what is logged, how long it is kept, IP masking, alerts and scheduled integrity scans.

== Changelog ==

= 2.0.0 =
The separate edition is merged into this plugin: every feature is now included, with no paid tier. The plugin is renamed "Compliance Audit Log – Tamper-Evident Activity Log"; its folder, settings and data are unchanged.

New: audit trail and monitoring

* New: AI agent attribution. Each entry records whether a person, an AI agent, an integration or an automated task made it, through which channel and with which application password or ability. WordPress ability runs (6.9+), refusals and failures (7.1+) are logged. Activity Logs and exports can be filtered by who made the change.
* New: Wider activity coverage: theme and plugin file editor edits (with before/after file hashes), roles and capabilities granted outside the role dropdown, Super Admin changes, edits to published content, menus, widgets, theme customisations, Customizer and Site Editor changes, comment moderation, category and tag changes, privacy requests, content exports and multisite network changes.
* New: WooCommerce audit trail for staff order changes, refunds, deleted orders, product price/stock/download changes, coupons and store settings.
* New: Plugin, theme and uploads file integrity scans (WordPress.org checksums plus signed local baselines), manual or scheduled, with incidents on change.
* New: Direct grants of administrator or other sensitive capabilities are flagged as privilege escalation, and file editor changes open an incident under the configuration-change rule.
* New: Active Sessions screen to end a login session or sign a user out everywhere (new capability `tbsh_cal_manage_sessions`).
* New: Setup assistant for new installs, logging exclusions for service accounts, roles and event types, and a public `tbsh_cal_log()` developer API.
* New: Real-time SIEM forwarding (HTTPS JSON, Splunk HEC, syslog over TLS/TCP) with retries and failure alerts.

New: incidents, governance and evidence

* New: Incident Center with automatic detection rules and NIS2 Article 23 (24h / 72h / 1 month) tracking.
* New: Alerts via email, Slack, Discord, Microsoft Teams Workflows, Telegram and signed webhooks, queued with retries and a delivery log.
* New: Optional AI incident analysis (Anthropic, OpenAI, Google Gemini, OpenAI-compatible), off by default.
* New: Risk register with 5×5 heat map, vendor/ICT third-party register (DORA Art. 28) with reminders, and software supply-chain inventory.
* New: Operational resilience drill register with RTO/RPO, and opt-in behavioural anomaly detection.
* New: Executive dashboard and readiness engine with NIS2, DORA, ISO/IEC 27001:2022 and SOC 2 coverage.
* New: Signed, verifiable compliance reports as PDF (generated on the server), print-ready HTML, CSV and JSON.
* New: Weekly or monthly compliance digest email with a signed executive report attached.
* New: Encrypted off-site evidence vaults (S3-compatible, FTP/FTPS, another WordPress site) with `wp tbsh-cal vault-decrypt` and a standalone decryptor (in the GitHub repository).
* New: Expiring, revocable read-only auditor links.
* New: Site Network: one overview of other sites running this plugin, connected with their read-only auditor links.
* New: Unified data retention with log archiving and hash-chain re-anchoring.
* New: WP-CLI commands `vault-decrypt`, `vault-sync` and `verify-chain`.
* New: Credentials encrypted at rest, with an optional `TBSH_CAL_ENCRYPTION_KEY` constant.

Improved

* Improved: Accessibility and usability across the admin: dialogs keep keyboard focus, status messages are announced, focus is always visible, text and form fields meet WCAG 2.2 AA contrast in light and dark mode, and every filter is labelled.
* Improved: Every page shows its title and purpose, the menu collapses on small screens, wide tables scroll instead of being cut off, and dark mode follows your system setting until you choose.
* Improved: The Dashboard shows what needs attention (critical events today, open incidents, file changes, unverified log) and every figure opens the matching filtered activity log.
* Improved: Activity Logs shows times in your site's date format, lists active filters with one-click removal, remembers your column choice, and can show errors and critical events together.
* Improved: Settings shows an unsaved-changes bar with Save and Discard, links to each section, and asks before you leave with unsaved changes, including with the browser's back and forward buttons.
* Improved: Accepting the current files of a very large plugin or theme as its integrity baseline now runs in short steps with progress, so it no longer hits server time limits.
* Improved: The admin app now loads each screen on demand, making the first load much smaller.
* Improved: Clearer plugin description, FAQ and in-plugin help text. The Help page now covers every feature and uses ISO/IEC 27001:2022 control numbers.
* Improved: Activity Logs, the "Made by" filter, the Dashboard and Compliance Overview stay fast on very large logs (tested with 2.3 million entries: pages that took 7–17 seconds now load in milliseconds). Lists with more than 10,000 matching entries say so instead of counting every one.
* Improved: The Dashboard's "Most common actions" and "Most active users" now cover the last 30 days.
* Improved: The plugin no longer adds a database query to front-end page views.
* Improved: Searching Activity Logs and the Forensic Timeline uses a full-text index on large logs (whole words and word beginnings), and falls back to an exact search when that finds nothing.
* Improved: PDF reports show Greek, Cyrillic, Central and Eastern European, Armenian, Georgian and Vietnamese text, with an embedded subset of the bundled DejaVu Sans font; Western European reports stay as small as before.
* Improved: Site Network changes (a site that cannot be read or recovers, a failed audit-log integrity check, new errors) are recorded in the audit log, so they reach SIEM forwarding and alerts, and the compliance digest has a Site Network section.
* Security: Plain FTP vaults are turned off (they send the FTP password unencrypted); use FTPS. On multisite, FTP hosts and self-hosted AI endpoints must be public addresses unless the network administrator allows private ones.
* Improved: Build tools updated (development only); the release workflow uses Node 22.
* Security: Secret values in logged addresses (auditor link tokens, password-reset keys, access tokens) are replaced with "REDACTED" before they are stored.
* Security: CSV log exports neutralise cells that a spreadsheet would run as a formula.
* Security: Changing where an off-site vault, the AI analysis or SIEM forwarding sends data now requires entering its password, key or token again.
* Security: Plain-http S3 endpoints are refused unless private vault endpoints are allowed; the auditor portal rate-limits invalid links on every path; unfinished vault packages are cleaned up; uninstall respects each site's own choice and also removes exports and cached data.

Fixed

* Fixed: Off-site vault sync failed on servers without the PHP sodium extension; packages are now encrypted with WordPress's built-in sodium_compat as well, and stay compatible both ways.
* Fixed: Sign-ins were recorded under "system" instead of the person who signed in, so they were missing from user filters, "Most active users" and behavioural baselines. Anomaly detection now logs a sign-in at an unusual hour for that user (sign-ins are scored on timing and bursts, never as a new or rare action).
* Fixed: Score colours, status icons and the integrity check time now meet WCAG AA contrast; long hashes and URLs wrap instead of widening the page on small screens; tab rows wrap.

Upgrade

* Automatic migration of data, settings, credentials, capabilities and auditor links from the separate edition, which is deactivated automatically.

= 1.1.0 =
* Hardened cryptographic logging (flush locking, retention chain repair, activation flush).
* Fixed GDPR privacy exporter/eraser registration and failed-login alert detection.
* Expanded activity tracking for content, media, plugin deletion, and application passwords.
* Added email alerts for critical events, core file checksum checks, and expiring export share links.
* Improved admin UX with capability-gated navigation, safer API error handling, and timeline pagination.
* Tested up to WordPress 7.1.

= 1.0.3 =
* General bug fixes and improvements.

= 1.0.2 =
* General bug fixes and improvements.

= 1.0.1 =
* General bug fixes and improvements.

= 1.0.0 =
* Initial release.
* Added cryptographic SHA-256 logs chaining.
* Implemented point-in-time evidence snapshots.
* Added GDPR-compliant IP masking controls.
* Installed compliance checklists for DORA, NIS2, and ISO 27001.

== Upgrade Notice ==

= 2.0.0 =
Major release: back up your database first. Every feature of the former separate edition is now included for free. If it is installed, it is deactivated automatically and its data migrated; then delete it while this plugin is active.

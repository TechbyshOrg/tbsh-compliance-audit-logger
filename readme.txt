=== Compliance Audit Trail & Evidence Logger ===
Contributors: Techbysh
Tags: audit-trail, compliance-log, security-audit, tamper-evident, nis2-dora
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Enterprises-grade tamper-evident compliance logging, audit trails, and evidence archiving for WordPress. Aligned with NIS2, DORA, and SOC 2.

== Description ==

Compliance Audit Trail & Evidence Logger is a production-ready, compliance-focused audit trail, security monitoring, and evidence collection platform. Unlike simple activity logs, it is designed from the ground up for cybersecurity governance, providing legally defensible, tamper-evident audit records.

= Cryptographic Log Integrity =
Every log entry is protected using a blockchain-style cryptographic SHA-256 hash chain. Each log contains the hash of the preceding entry. If a database record is modified or deleted, subsequent records will fail validation. The built-in verifier identifies exactly when and where the audit trail was compromised.

= Compliance Framework Alignment =
Designed to assist compliance officers, developers, and system administrators in meeting the strict logging requirements of international regulatory standards:
* **NIS2 Directive**: Meets continuous incident logging and system monitoring requirements.
* **DORA (Digital Operational Resilience Act)**: Provides ICT incident tracking and reporting logs.
* **SOC 2 & ISO/IEC 27001**: Satisfies logical access monitoring and change management controls.
* **PCI DSS & HIPAA**: Creates secure, persistent audit trails of configuration changes.

= Evidence Vault Snapshots =
The built-in Evidence Vault allows you to capture full system state archives. Automatically or manually archive installed plugins, active themes, user roles, database table sizes, PHP version, and security configurations. These snapshots serve as proof of system integrity during audits.

= Privacy & Security Safeguards =
* **Irreversible Masking**: Client IP addresses and User Agents are hashed using HMAC-SHA-256 with a unique local site salt.
* **GDPR Compliance**: Integrates with core WordPress Personal Data Exporters and Erasers, automatically recalculating the hash chain when personal details are erased.
* **No Cloud Dependency**: All data is stored locally in custom tables. No external logging servers are contacted.

== Installation ==

1. Upload the `tbsh-compliance-audit-logger` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to 'Compliance Logs' in the admin sidebar.
4. Go to the Settings tab to adjust logging policies and retention periods.

== Frequently Asked Questions ==

= How does the cryptographic hash chain protect my logs? =
Every log entry combines its ID, message, timestamp, and metadata with the hash of the preceding entry to calculate its own SHA-256 signature. If a database record is altered, its signature changes, causing validation checks to fail for that row and all subsequent rows.

= Does this plugin affect page load times? =
No. The plugin uses an asynchronous-like queue. Logs are stored in memory during the runtime and written to the database during the WordPress `shutdown` hook, after the page has finished loading for the visitor.

= Can I use this on a WordPress Multisite network? =
Yes. The plugin is fully multisite-ready, supports network-wide activation, and stores logs with site identifiers to allow filtering by site.

= Are IP addresses stored in a GDPR-compliant manner? =
Yes. When IP address anonymization is enabled (default), client IPs are salted and hashed using HMAC-SHA-256 before insertion. They cannot be reversed to reveal the raw IP.

= Does the free version have any logs limits? =
No. The free version does not limit the number of logs, exports, or evidence snapshots you can generate.

= How do I run a database validation check? =
Go to 'Compliance Logs' -> 'Integrity Center' and click the 'Run Integrity Verification' button. The verifier will scan all records and report if any anomalies are found.

= What happens if the hash chain is compromised? =
If the Integrity Center reports a compromised status, it lists the exact log ID where the validation failed, indicating that the database row was modified or deleted.

= How does the Evidence Vault work? =
The Evidence Vault takes a snapshot of your site's technical configuration (plugins list, theme versions, user capability definitions, security constants). It provides proof of the site's state at a specific point in time.

= Can I export logs for external analysis? =
Yes. You can generate CSV or JSON log exports from the Export Center, using filters like date ranges, compliance categories, or severity levels.

= Does this plugin support capability-based access? =
Yes. The plugin creates custom capabilities (`tbsh_cal_view_logs`, `tbsh_cal_view_evidence`, `tbsh_cal_export_data`, `tbsh_cal_manage_settings`, `tbsh_cal_verify_integrity`) assigned to administrators by default.

= How often does it capture automated snapshots? =
If enabled in settings, the plugin runs a daily or weekly cron job to capture system configuration archives.

= Does it support WP GDPR Personal Data Erasure? =
Yes. When a WordPress data erasure request is completed for a user, the plugin anonymizes their data and automatically rebuilds the hash chain to keep the cryptographic audit trail unbroken.

= Where is the log data stored? =
All data is stored locally in dedicated custom database tables (`wp_tbsh_cal_logs`, `wp_tbsh_cal_evidence`, `wp_tbsh_cal_integrity`). No logs are written to default options tables.

= What compliance tags are assigned to events? =
Events are automatically tagged with compliance categories such as Access Control, Identity Management, Authentication, Change Management, Security Monitoring, and Operational Security.

= Can I use this for security audit readiness? =
Yes. The Compliance Overview screen maps your active logs, settings, and verification histories to specific controls in ISO 27001, SOC 2, NIS2, and DORA frameworks.

== Screenshots ==

1. Dashboard overview page showing compliance metrics, recent logs feeds, and the cryptographic verification status indicator.
2. Advanced logs search grid with date filtering, user filter, category filter, and customizable visible columns checkboxes.
3. Inspecting a log entry showing custom JSON metadata properties, client IP hashes, and SHA-256 sequence hashes.
4. Forensic chronological timeline layout for site activities.
5. Integrity Center verification history scan, listing detailed anomalies when database tables are altered.
6. Evidence Vault listing active theme and plugin configuration snapshots.
7. Compliance Framework alignment stats for audit checklists.
8. Export Center config panel and list of compiled downloads.

== Changelog ==

= 1.0.0 =
* Initial release.
* Automated custom tables schema deployment on activation.
* Cryptographic SHA-256 logs chaining.
* Asynchronous shutdown logging.
* Compliance indicators for NIS2, DORA, SOC 2, and ISO 27001.

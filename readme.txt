=== Compliance Audit Trail & Evidence Logger ===
Contributors: techbysh
Tags: compliance, audit-trail, security-log, tamper-evident, nis2
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Tamper-evident compliance log & security audit trail. Cryptographic chain verification & evidence snapshots for NIS2, DORA, GDPR, and ISO 27001.

== Description ==

Is your website ready for a cybersecurity audit? 

When a website is hacked, the first thing intruders do is modify database logs to cover their tracks. Standard security plugins log user actions, but they do not guarantee that the logs themselves have not been altered or deleted.

**Compliance Audit Log & Tamper-Evident Security Trail** transforms your WordPress dashboard into a secure GRC (Governance, Risk, and Compliance) platform. By combining real-time activity tracking with cryptographic SHA-256 chain verification, it ensures your security audit trails are immutable, verified, and legally defensible.

= Cryptographic Log Integrity =
Every log entry calculates a SHA-256 signature containing its contents and the signature of the preceding row. If an unauthorized actor inserts, deletes, or edits a log record directly in the database, the cryptographic chain breaks. The built-in verifier identifies exactly when and where the compromise occurred.

= Fulfill Global Compliance Regulations =
Designed to assist compliance officers, developers, and security teams in meeting strict international guidelines:
*   **NIS2 Directive (EU)**: Fulfill Article 21 risk management and operational logging.
*   **DORA (Digital Operational Resilience Act)**: Monitor ICT incident events and operational resilience.
*   **SOC 2 & ISO 27001**: Satisfy access control monitoring and configuration audit requirements.
*   **GDPR & HIPAA**: Mask IPs using HMAC-SHA-256 salted hashes and support data erasures.

= Features (Free Version) =
*   **Tamper-Evident Chaining:** Link logs cryptographically using SHA-256 signatures.
*   **Granular Activity Tracking:** Log logins, session failures, plugin edits, and file updates.
*   **Evidence Vault:** Capture point-in-time snapshots of themes, active plugins, capabilities, and system configurations.
*   **GDPR Privacy Masking:** Anonymize client IP addresses using local cryptographic salts.
*   **Forensic Timeline:** Browse events chronologically through a responsive, filterable layout.
*   **SIEM Export:** Compile logs into CSV or JSON formats for security analysis.

= Upgrade to Pro for Enterprise Governance =
Unlock advanced features to manage cyber risks and supply chains networked across your organization:
*   **Off-site Evidence Vaults:** Synchronize encrypted logs to AWS S3, Google Cloud, Azure, or SFTP.
*   **Automated Incident Detection:** Trigger Slack, Discord, and Email alerts on brute force and privilege escalations.
*   **AI Incident Analysis:** Suggest root-cause mitigations using integrated OpenAI, Claude, and Gemini models.
*   **Auditor Portal:** Generate secure, expirable, read-only dashboard links for external compliance checkers.
*   **Risk & Vendor registers:** Track supplier risk metrics using an interactive 5x5 Heat Map.
*   **File Integrity Monitor:** Validate core system checksums using official WordPress.org APIs.

== Installation ==

1. Upload the `tbsh-compliance-audit-logger` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to 'Compliance Logs' in the admin sidebar.
4. Run your first database integrity check in the 'Integrity Center'.

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

1. Executive compliance dashboard displaying security scores, database integrity states, and framework readiness checkers.
2. Granular activity log grid displaying event parameters and SHA-256 cryptographic signatures.
3. Forensic timeline view tracing administrative actions chronologically.
4. Database integrity checks showing verification logs and success statuses.
5. Cyber risk 5x5 Heat Map detailing supply chain vulnerabilities and criticality levels.
6. Temporary auditor portals access configuration panel.
7. Cloud vaults storage sync credentials and transfer logs.
8. AI-assisted threat mitigation panel outlining remediation summaries.

== Changelog ==

= 1.0.0 =
* Initial release.
* Added cryptographic SHA-256 logs chaining.
* Implemented point-in-time evidence snapshots.
* Added GDPR-compliant IP masking controls.
* Installed compliance checklists for DORA, NIS2, and ISO 27001.

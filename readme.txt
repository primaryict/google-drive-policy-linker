=== Google Drive Folder Viewer & Policy Click Tracker ===
Contributors: Primary ICT Support Ltd
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.2.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display Google Drive policy folders with styled file links, click tracking, alerts, IP logs, and simple admin reporting.

== Description ==

Google Drive Folder Viewer & Policy Click Tracker displays public Google Drive folder contents using a shortcode and tracks document clicks for administrative review.

Features include:

* Google Drive folder display via shortcode.
* Shared Drive folder support.
* Configurable list design.
* Click tracking with IP address logging.
* Threshold-based alert emails.
* Primary ICT Support admin dashboard.
* Click stats with IP address search.
* Daily document click graph.
* WordPress dashboard stats widget.
* Optional Gemini AI analysis before threshold alerts are sent.
* Manual Gemini AI date-range checks from the admin area.
* Gemini review history logs.
* WordPress plugin-screen updates from GitHub Releases.

== Installation ==

1. Upload the plugin folder to wp-content/plugins.
2. Activate the plugin in WordPress.
3. Open Primary ICT Support > Drive Folder Viewer.
4. Add a Google Drive API key.
5. Use the shortcode with a public Google Drive folder ID.

Example:

[gdrive_folder id="GOOGLE_DRIVE_FOLDER_ID" title="Policies"]

== Privacy ==

This plugin can store visitor IP addresses when users click listed documents. Site owners should mention this in their privacy policy where required.

== Changelog ==

= 1.2.8 =
* Export and import saved plugin settings as JSON for deployment across schools, including design, Gemini credentials and IP exclusions.
* Use the WordPress site logo at the top of alert emails when configured.
* Add labelled IPv4 and IPv6 exclusions in Settings, with an add-current-IP button.
* Skip excluded clicks before recording or checking alert thresholds; existing history is retained.

= 1.2.7 =
* Increased the default Gemini request timeout and added a configurable timeout setting.

= 1.2.6 =
* Added a Gemini Logs tab with stored review outcomes for threshold, test, and manual checks.

= 1.2.5 =
* Switched alert email graphs from inline SVG to generated PNG images.
* Added a separate Gemini review data window setting, defaulting to 14 days.

= 1.2.4 =
* Updated test alert emails to run the real Gemini review against current click data.

= 1.2.3 =
* Moved Gemini settings to the Gemini AI tab and improved the alert explanation and email layout.

= 1.2.2 =
* Updated the default Gemini model and added a configurable Gemini model setting.

= 1.2.1 =
* Added a Gemini AI admin tab for manual date-range analysis without sending alert emails.

= 1.2.0 =
* Added optional Gemini AI click-pattern analysis with configurable confidence threshold.

= 1.1.1 =
* Fixed duplicate Y-axis labels on low-count click graphs.

= 1.1.0 =
* Added bot click filtering, nofollow policy links, daily click graphs, graph email content, and a WordPress dashboard widget.
* Changed click timestamps to store UTC and display as UK local time.

= 1.0.10 =
* Improved click stats time handling for the WordPress site timezone and daylight saving time.

= 1.0.9 =
* Improved click tracking for middle-clicks and right-click context menu opens.

= 1.0.8 =
* Added Google Shared Drive support to folder document requests.

= 1.0.7 =
* Added GitHub Releases updater support.

= 1.0.6 =
* Added Primary ICT Support branded admin styling.

= 1.0.5 =
* Added Primary ICT Support dashboard.

= 1.0.4 =
* Added shared Primary ICT Support admin menu.

= 1.0.3 =
* Added IP address logging and IP search in click stats.

= 1.0.2 =
* Added design settings and improved front-end list layout.

= 1.0.1 =
* Improved click tracking reliability.

= 1.0.0 =
* Initial release.

# Inspection Window

This optional filter uses public Ofsted state-funded school data. No spreadsheet access, Google OAuth or additional API key is needed.

1. Open Inspection Window and select Detect School. The plugin reads the configured WordPress homepage and looks for an official school-specific Ofsted or GIAS link. It does not guess from school names or query a nationwide website directory.
2. Confirm the suggested school and save. If the homepage has no unique link (common on local/staging sites), enter the current six-digit URN manually.
3. Refresh Ofsted Data and check the saved school name and eligibility.
4. Enable the inspection check and save.

The plugin discovers the latest CSV from the official monthly publication page, streams it to a temporary file and retains only the selected school's record for 24 hours. Temporary files are deleted. Downloads are bounded to 40 MiB. Errors are cached for five minutes and pause inspection alerts. The optional filter defaults to disabled.

The latest qualifying routine inspection is used, not a monitoring visit. The estimated next date is four calendar years later. Alerts become eligible six weeks (42 calendar days) before that date and remain eligible if overdue. The same lead-in applies to manual expected-date overrides. Month-end dates in the four-year estimate are clamped to the last valid day. The estimate is not an official inspection deadline: Ofsted can inspect sooner.

Current-school attribution, outcomes and data freshness must be clear. Concern outcomes, shorter-cycle cases, predecessor records, new schools without qualifying inspections, changed schemas and data over 100 days old do not receive an automatic estimate. Set a verified expected-date override where appropriate. This explicit override takes precedence even during public-data outages. Clear it when automatic estimates should resume.

Ofsted publishes monthly; recently completed inspections may not yet be present. Review the displayed data date and use an override when necessary. Independent schools are not covered by this state-funded dataset.

The check runs after the click threshold and before Gemini, avoiding unnecessary API spend. Inspection alerts still require a successful Gemini determination meeting the confidence threshold. Ineligible/unknown records cancel pending reviews and log why; manual Gemini analysis remains available. Existing review-failure notifications after Gemini retry exhaustion remain separate from inspection determinations.

Settings exports include the URN and date override: review these when moving settings between schools. Detection and refresh require administrator permission and valid nonces; neither sends email nor calls Gemini. Homepage requests use WordPress's safe HTTP API, so local/private addresses can be rejected; use the manual URN in that case.

Sources:
- https://www.gov.uk/government/statistical-data-sets/monthly-management-information-ofsteds-school-inspections-outcomes
- https://www.gov.uk/government/publications/school-inspection-toolkit-operating-guide-and-information/inspection-information-for-state-funded-schools-for-use-from-september-2026

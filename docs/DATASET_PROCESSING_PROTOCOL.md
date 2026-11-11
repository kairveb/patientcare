# Dataset processing protocol

## Selection and filters

Reports are selected by their report route and use the shared `ReportExportService`. Supported request filters are date range (`start`, `end`), department, and doctor where relevant. Doctor exports are scoped to that doctor's own records in the export service; a supplied doctor filter narrows the result and does not widen access. Report access requires the existing report permission and recent password confirmation.

## Minimization and validation

Export rows use patient reference codes rather than names, email addresses, phone numbers, or full patient identifiers where patient-related records are included. Only fields required for the selected report are selected for the export. Inputs are validated before querying, and the date range is checked to prevent a start date later than the end date. CSV, TXT, and XLS cells are neutralized against spreadsheet formula interpretation; XLS is generated as string-only SpreadsheetML. PDF values are rendered through escaped Blade output.

## Formats

The application supports CSV, PDF, tab-delimited TXT, and Excel-compatible XLS (SpreadsheetML 2003). XLSX is not generated. All formats use the same report dataset, filters, doctor scoping, and export authorization.

## Audit and retention

Each export records the report name, format, user, and validated filter values in the existing audit log; exported row content is not copied into that audit event. The application does not keep a server-side copy of downloaded report files. Downloaded files are under the recipient's custody and must be stored, transferred, and deleted under the hospital's approved retention and access policy. Database backup retention is separate from downloaded-file retention.

## Intended use

Reports support authorized clinical, operational, and quality-review work. They are not a substitute for the source clinical record. AI evaluation exports support clinician review and quality measurement and are not an AI training pipeline.

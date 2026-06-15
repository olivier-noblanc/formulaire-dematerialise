---
Task ID: 1
Agent: Main
Task: Fix multiple bugs in CircuitDémat (CSS, copy buttons, email field type, dynamic recipients, version, owners section)

Work Log:
- Fixed CSS: .section-card-header button.btn-secondary now has proper color (dark text on white bg) instead of invisible white-on-white
- Fixed CSS: Non-secondary buttons in section-card-header keep white text
- Fixed "Copier" buttons: Added fallback from navigator.clipboard.writeText() to document.execCommand('copy') for non-HTTPS contexts
- Fixed install.php: APP_VERSION was hardcoded to '3.0.0', now matches current version '5.9.0'
- Added 'email' field type: $field_types, $valid_field_types, field_type_icon(), field_type_label(), validate_form_json(), AI prompt
- Added 'email' case in form.php renderer: renders <input type="email"> with pattern validation
- Added 'email' and 'file' cases in form_preview.php
- Added dynamic recipient support: {{field_name}} syntax accepted in step recipients
- Added resolve_dynamic_recipient() function in helpers.php
- Updated advance_workflow() to resolve {{field_name}} references from submission data
- Updated AI prompt to explain dynamic recipients with example (Demande de congé)
- Updated import validation to accept {{field_name}} in recipients
- Updated add_recipient POST handler and UI to support {{field_name}} syntax
- Added visual distinction for dynamic recipients (dashed border, purple color, "dynamique" label)
- Fixed owners section: added section-card-header/body structure for consistent rendering
- Updated CHANGELOG.md with v5.9.0 entry
- Updated APP_VERSION to 5.9.0 in config.php and install.php

Stage Summary:
- All user-reported bugs fixed: CSS readability, copy button, email field type, dynamic workflow recipients
- Version bumped to 5.9.0
- CHANGELOG updated

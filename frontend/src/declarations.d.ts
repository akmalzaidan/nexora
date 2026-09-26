// ═══════════════════════════════════════════════════════════════════
// Declaration file for NX utility class names
// Makes utility classes available in templates without TS errors.
// ═══════════════════════════════════════════════════════════════════

// Style utility classes are defined via SCSS and applied as class names.
// TypeScript does not need to know about them.
declare namespace ng {
  // This file exists to provide a TS-safe anchor for template references
  // to CSS utility classes used throughout the app.
}

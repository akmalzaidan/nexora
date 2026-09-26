# NEXORA Design System

> Design-language reference for the NEXORA Operational Intelligence Workspace.
> Factual summary of the frontend implementation under `frontend/src/theme/`
> and the `app-nx-*` component library under `frontend/src/app/shared/components/`.

## Architecture

The design system is token-first with two layers:

1. **SCSS design tokens** (authoring source of truth)
   `frontend/src/theme/_design-tokens.scss` — SCSS variables for the full palette,
   typography, spacing, radii, shadows, transitions, z-index, and layout.
2. **CSS custom properties (build-time bridge)**
   `frontend/src/theme/variables.scss` maps the SCSS tokens to `--nx-*` custom
   properties scoped to `:root[data-theme='dark']` and `:root[data-theme='light']`.

Components consume `var(--nx-*)` custom properties at runtime. Hex values are never
hardcoded in component styles.

Load order in `src/global.scss` (Ionic first, NX last so NX wins):

```text
@import '@ionic/angular/css/*.css'   (9 core sheets)
@import './theme/variables.scss'
@import './theme/typography.scss'
@import './theme/status.scss'
@import './theme/components.scss'
@import './theme/utilities.scss'
```

Remaining theme files:

| File | Role |
|---|---|
| `_typography.scss` | Base type styles (Inter / JetBrains Mono families, font-feature fallbacks) |
| `_status.scss` | Status color classes and helper styles used by status indicators |
| `_components.scss` | Component-scoped styling for shared primitives |
| `_utilities.scss` | Layout/utility helpers |

## Theme System

Three modes — `dark` (default), `light`, `system` — managed by `ThemeService`.

- Applied via the `data-theme` attribute on the root element.
- Persisted to `localStorage` under key `nx-theme` (default: `dark`).
- `system` resolves through `prefers-color-scheme`.
- A pre-render script applies the stored/saved theme before first paint to avoid
  a flash of the wrong theme.
- Each mode defines the full `--nx-*` surface/border/text/accent/semantic set plus
  matching Ionic overrides (`--ion-background-color`, `--ion-text-color`,
  `--ion-color-primary`, toolbar/tab-bar/item/card/modal/overlay backgrounds).

## Color Palette

### Dark (primary)

| Token | Value | Use |
|---|---|---|
| `--nx-bg` | `#0B0D10` | Page background |
| `--nx-surface` | `#111419` | Cards, panels |
| `--nx-surface-elevated` | `#171B21` | Dropdowns, elevated surfaces |
| `--nx-surface-level-3` | `#1E2530` | Modal backdrops, hover |
| `--nx-border` / `--nx-border-strong` | `#242932` / `#3A4250` | Borders |
| `--nx-border-subtle` | `#1C2029` | Hairline dividers |
| `--nx-text` / `--nx-text-secondary` / `--nx-text-muted` | `#F3F5F7` / `#B0B6C0` / `#858C98` | Text hierarchy |
| `--nx-accent` | `#E8FF4F` | Brand/action color |

### Light

| Token | Value | Use |
|---|---|---|
| `--nx-bg` | `#F7F7F5` | Page background |
| `--nx-surface` / `--nx-surface-elevated` | `#FFFFFF` | Surfaces |
| `--nx-border` / `--nx-border-strong` | `#E5E5E0` / `#D4D4CC` | Borders |
| `--nx-text` / `--nx-text-secondary` / `--nx-text-muted` | `#151515` / `#555A61` / `#777C83` | Text hierarchy |
| `--nx-accent` | `#A3C400` | Brand/action color |

`--nx-accent-subtle` is derived per theme as `color-mix(in srgb, var(--nx-accent) 12%, transparent)`.

## Semantic Status Colors

Used by `NxBadge` and the status system. Both themes map the same status set:

| Status | Dark | Light |
|---|---|---|
| `active` / `success` | `#36D399` | `#059669` |
| `inactive` / `neutral` | `#6B7280` | `#9CA3AF` |
| `pending` / `warning` | `#FBBF24` | `#D97706` |
| `maintenance` / `info` | `#60A5FA` | `#2563EB` |
| `danger` | `#F87272` | `#DC2626` |

Badge statuses: `active`, `inactive`, `pending`, `maintenance`, `success`,
`warning`, `danger`, `info`, `neutral`.

## Typography

- **Sans:** `Inter` (fallbacks: `-apple-system`, `BlinkMacSystemFont`, `Segoe UI`, `Roboto`).
- **Mono:** `JetBrains Mono` (fallbacks: `Fira Code`, `Consolas`).

| Token | Value |
|---|---|
| `$nx-font-size-xs` … `$nx-font-size-4xl` | 11 / 12 / 14 / 16 / 18 / 20 / 24 / 30 / 36 px |
| Base size on `html, body` | 14px, line-height 1.5 |
| Weights | 400 / 500 / 600 / 700 |
| Line heights | `tight` 1.25, `normal` 1.5, `relaxed` 1.625 |

## Spacing, Radii, Shadows

- **Spacing scale:** 0, 2, 4, 6, 8, 10, 12, 16, 20, 24, 32, 40, 48, 64 px.
- **Radii:** `sm` 4, `md` 6, `lg` 8, `xl` 12, `2xl` 16, `full` 9999 px.
- **Shadows:** `sm`/`md`/`lg`/`xl` (elevated black alpha) plus `focus`
  (2px `--nx-accent` ring).
- **Transitions:** `fast` 120ms, `normal` 200ms, `slow` 300ms — cubic-bezier(0.4, 0, 0.2, 1).

## Z-Index Layers

| Layer | Value |
|---|---|
| `base` | 0 |
| `relative` | 1 |
| `dropdown` | 100 |
| `sticky` | 200 |
| `overlay` | 300 |
| `modal` | 400 |
| `toast` | 500 |
| `tooltip` | 600 |

## Layout Tokens

| Token | Value |
|---|---|
| `$nx-sidebar-width` | 232px |
| `$nx-sidebar-width-collapsed` | 56px |
| `$nx-header-height` | 48px |
| `$nx-max-content-width` | 1440px |
| `$nx-table-row-height` | 36px |

## Global Behaviour (`src/global.scss`)

- Box-sizing reset; button/input/textarea/select resets preserving inherited fonts.
- Custom scrollbars (8px, surface track, strong-border thumb).
- `::selection` uses accent-on-bg; `:focus-visible` renders a 2px accent ring.
- `prefers-reduced-motion: reduce` collapses animations/transitions.
- Ionic component theming overrides (content, header, toolbar, title, tab-bar,
  item, card, button, input, modal, loading, toast, footer, tabs) all bound to
  `--nx-*` / `--ion-*` custom properties.

## Component Library (`app-nx-*`)

All shared primitives live in `frontend/src/app/shared/components/` and share the
`app-nx-` selector prefix. Consuming pages/components import them standalone.

| Component | Notes |
|---|---|
| `app-nx-button` | `primary/secondary/ghost/danger/icon` variants, `sm/lg` sizes, `nxClick` output, `loading`/`disabled` states |
| `app-nx-badge` | Status set above; maps status → semantic color |
| `app-nx-input` | Signal API: `[value]` input + `(valueChange)` output; label/hint/error/loading states |
| `app-nx-search` | Compact search with clear button and keyboard shortcut hint |
| `app-nx-panel` | Section container with optional header/footer slots |
| `app-nx-table` | Data table with `loading`/`error`/`empty` states, `app-nx-badge` status cells, monospace IDs, resizable columns, selected-row highlight, hover support |
| `app-nx-loading-state` | `spinner`/`skeleton`/`overlay` modes |
| `app-nx-empty-state` | Icon + title + description for empty lists |
| `app-nx-error-state` | Error placeholder with optional `nxRetry` output |
| `app-nx-confirm-dialog` | Destructive-action confirmation |
| `app-nx-toast-container` | Toast presentation; driven by `NxToastService` |
| `app-nx-master-detail` | Split-pane layout for future list→detail workflows |

### UI Convention Rules

- Use `var(--nx-*)` custom properties; never hardcode theme colors.
- Prefer signal inputs/outputs over two-way `ngModel` bindings in primitives.
- Stateful primitives (`nx-table`, `nx-search`) must render explicit
  loading/error/empty states rather than blank surfaces.
- New business components reuse `app-nx-*`; do not fork a bespoke styling layer.

## Related

- [Frontend architecture](./frontend.md)
- [Global architecture & decision records](./README.md)
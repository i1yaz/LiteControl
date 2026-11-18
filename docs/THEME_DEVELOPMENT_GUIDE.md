# LiteControl Panel — Theme Development & Design System Guide

> **Audience:** AI Coding Agents & Frontend Engineers  
> **Scope:** `panel/resources/css/app.css`, `panel/resources/js/Shared/Ui/*`, `panel/resources/js/Themes/*`, `panel/resources/js/Pages/*`, and `panel/resources/js/Domains/*`

LiteControl features a **Multi-Theme Engine** capable of supporting tens of distinct **Dark**, **Light**, **High-Contrast**, **Tactical/HUD**, and **Enterprise** themes without visual bleed ("rainbow themes" or "half-light / half-dark split-brain themes") or duplicated business logic.

This document is the authoritative contract for:
1. **Building or editing canonical pages/components** so they adapt automatically to every current and future theme (Light or Dark).
2. **Designing and implementing new themes** under `panel/resources/js/Themes/<theme-id>/`.

---

## 1. Golden Rules (Zero "Rainbow" & Zero "Half-Light / Half-Dark" Policy)

When creating or modifying **ANY `.vue` file anywhere in `panel/resources/js/`** — including `Pages/**`, `Domains/**`, `Shared/**`, **AND `Themes/<theme-id>/**/*.vue`** — you **MUST** obey these six rules:

1. **All `.vue` files (even inside `Themes/<theme-id>/Layouts/` and `Themes/<theme-id>/Pages/`) MUST use `--theme-*` semantic classes — NEVER raw colors or hex values:**
   - **Forbidden in ALL `.vue` files:** `bg-slate-*`, `bg-gray-*`, `bg-zinc-*`, `bg-black`, `bg-white`, `text-white`, `text-slate-*`, `border-slate-*`, or arbitrary hex classes like `bg-[#0c1017]`.
   - **Where colors live:** Actual hex/rgb values live **ONLY** inside `Themes/<theme-id>/theme.css` under `html[data-theme="<theme-id>"]`.
   - **Why (The "Split-Brain Light/Dark Trap"):** If a theme defines light tokens in `theme.css` (`--theme-bg-card: #ffffff`, `--theme-text-primary: #0b1c30`) while its `Layouts/AppLayout.vue` uses `bg-slate-950 text-slate-100`, the header/sidebar/main canvas render **dark** while inner cards render **light**, and page headings (`text-theme-text-primary`) become dark-text-on-dark-canvas (invisible)!
   - **Required in `AppLayout.vue`:**
     - Outer wrapper: `bg-theme-bg-main text-theme-text-primary`
     - Top header: `bg-theme-bg-header border-b border-theme-border`
     - Sidebar: `bg-theme-bg-sidebar border-r border-theme-border`
     - Main viewport: `bg-theme-bg-main`

2. **NEVER hardcode per-page brand/accent colors ("Rainbow Pages"):**
   - **Forbidden:** Using `bg-amber-600` on Tuning, `bg-purple-600` on PHP pages, `bg-emerald-600` on Subdomains, `bg-cyan-600` on Backups, `bg-indigo-600` on Packages, `bg-blue-600` on Services, or `bg-rose-600` on Security save buttons.
   - **Required:** Every primary action button, active tab, active navigation state, focus ring, and brand highlight **MUST** use the active theme's accent (`<UiButton variant="primary">`, `bg-theme-accent`, `text-theme-accent`, `border-theme-accent`).

3. **Reserve semantic status tokens strictly for semantic states (never raw Tailwind `emerald-*`, `amber-*`, `rose-*`):**
   - `success` (`bg-theme-success-bg text-theme-success-text border-theme-success-border`, solid dot `bg-theme-success`): Online daemons, active SSL, running services, enabled status badges.
   - `warning` (`bg-theme-warning-bg text-theme-warning-text border-theme-warning-border`, solid dot `bg-theme-warning`): Suspended domains/users, pending jobs, non-critical warnings.
   - `danger` (`bg-theme-danger-bg text-theme-danger-text border-theme-danger-border`, solid dot `bg-theme-danger`): Destructive actions (`Delete`, `Terminate`, `Stop`, `Kill`), offline daemons, expired SSL, validation errors.
   - `info` (`bg-theme-info-bg text-theme-info-text border-theme-info-border`, solid dot `bg-theme-info`): Informational callouts and neutral system hints.

4. **NEVER hardcode border radii (`rounded-xl`, `rounded-2xl`, `rounded-3xl`, `rounded-lg`) in `.vue` templates:**
   - **Why:** Tactical themes like **Avionics** use sharp `2px` corners (`--theme-radius-md: 2px`), **Cobalt Precision** uses crisp `4px–8px` corners (`--theme-radius-md: 0.375rem`, `--theme-radius-lg: 0.5rem`), and **Horizon** uses `8px–12px` corners (`--theme-radius-lg: 0.75rem`).
   - **Required:** Always use `@shared/Ui/*` components or the semantic radius utilities (`rounded-theme-sm`, `rounded-theme-md`, `rounded-theme-lg`, `rounded-theme-badge`) and `theme-ui-control`. (Only `rounded-full` on small circular dots/avatars like `w-2 h-2 rounded-full` or `w-8 h-8 rounded-full` is permitted.)

5. **Always use `text-theme-text-on-accent` for foreground text on `bg-theme-accent` backgrounds:**
   - Some themes use bright accents that require dark text (e.g., Avionics `#ff6b35` orange uses `#000000` black text), while others use deep accents that require white text (e.g., Horizon `#0284c7` and Cobalt `#004ac6` use `#ffffff` white text). Never pair `bg-theme-accent` with `text-white`—always pair `bg-theme-accent` with `text-theme-text-on-accent`.

6. **Do NOT create unnecessary `Themes/<theme-id>/Pages/**` overrides unless the theme has a genuinely distinct page layout:**
   - Canonical pages (`Pages/Identity/Auth/Login.vue`, `Pages/ServerOps/Dashboard.vue`, and all 31 pages) are already 100% tokenized. Only create `Themes/<theme-id>/Pages/ServerOps/Dashboard.vue` if your theme features a bespoke dashboard arrangement, and write that override using 100% `--theme-*` tokens!

---

## 2. Semantic CSS Token Contract (`resources/css/app.css`)

Every theme defines the following CSS custom properties under `html[data-theme="<theme-id>"]` in its `theme.css`. Tailwind v4 maps these in `resources/css/app.css` under `@theme`:

### 2.1 Surface Tokens
| CSS Variable | Tailwind Class (`bg-*`) | Purpose | Dark Example (Avionics) | Light Example (Cobalt) |
| :--- | :--- | :--- | :--- | :--- |
| `--theme-bg-body` | `bg-theme-bg-body` | Root `<body>` background | `#080a0e` | `#f8f9ff` |
| `--theme-bg-main` | `bg-theme-bg-main` | Main layout viewport canvas | `#090b0e` | `#f8f9ff` |
| `--theme-bg-header` | `bg-theme-bg-header` | Top navigation header bar | `#0d1015` | `#ffffff` |
| `--theme-bg-sidebar` | `bg-theme-bg-sidebar` | Sidebar navigation surface | `#0a0d12` | `#ffffff` |
| `--theme-bg-card` | `bg-theme-bg-card` | Primary card & table container | `#0c1017` | `#ffffff` |
| `--theme-bg-surface` | `bg-theme-bg-surface` | Secondary/nested surface, hover rows, table headers | `#121721` | `#eff4ff` |
| `--theme-bg-input` | `bg-theme-bg-input` | Form inputs (`input`, `select`, `textarea`) | `#080a0e` | `#ffffff` |
| `--theme-bg-elevated` | `bg-theme-bg-elevated` | Modals, dropdown menus, popovers | `#0e1217` | `#ffffff` |
| `--theme-bg-overlay` | `bg-theme-bg-overlay` | Modal backdrop overlay | `rgba(6, 8, 11, 0.82)` | `rgba(11, 28, 48, 0.45)` |

### 2.2 Border & Divider Tokens
| CSS Variable | Tailwind Class (`border-*` / `divide-*`) | Purpose | Dark Example | Light Example (Cobalt) |
| :--- | :--- | :--- | :--- | :--- |
| `--theme-border` | `border-theme-border` | Primary card, input, header, sidebar, and table borders | `#1e2634` | `#d8e2f3` |
| `--theme-border-subtle` | `border-theme-border-subtle` | Inner section headers, table row dividers | `#181f2c` | `#e5eeff` |
| `--theme-border-strong` | `border-theme-border-strong` | Emphasized control borders & scrollbar thumb | `#262f3e` | `#c3c6d7` |
| `--theme-border-hover` | `border-theme-border-hover` | Interactive card hover border | `#313d52` | `#737686` |

### 2.3 Typography Color Tokens
| CSS Variable | Tailwind Class (`text-*`) | Purpose | Dark Example | Light Example (Cobalt) |
| :--- | :--- | :--- | :--- | :--- |
| `--theme-text-primary` | `text-theme-text-primary` | Page titles, headings, primary values | `#f1f5f9` | `#0b1c30` |
| `--theme-text-secondary` | `text-theme-text-secondary` | Body text, form labels, table cells | `#98a6b8` | `#434655` |
| `--theme-text-muted` | `text-theme-text-muted` | Hints, placeholders, disabled text, timestamps | `#586477` | `#737686` |
| `--theme-text-on-accent` | `text-theme-text-on-accent` | Text placed directly on `bg-theme-accent` | `#000000` or `#ffffff` | `#ffffff` |

### 2.4 Brand Accent Tokens
| CSS Variable | Tailwind Class | Purpose |
| :--- | :--- | :--- |
| `--theme-accent` | `bg-theme-accent`, `text-theme-accent`, `border-theme-accent` | Primary brand color (buttons, active tabs, focus rings) |
| `--theme-accent-hover` | `hover:bg-theme-accent-hover` | Hover state for primary buttons |
| `--theme-accent-subtle` | `bg-theme-accent-subtle` | Soft tint for active nav items & primary badges |
| `--theme-accent-border` | `border-theme-accent-border` | Border for accented elements |
| `--theme-accent-text` | `text-theme-accent-text` | Legible accent text on subtle/card backgrounds (dark & light safe) |

### 2.5 Semantic Status Tokens (Light & Dark Adaptive)
Each status (`success`, `warning`, `danger`, `info`) provides 5 tokens so status badges, alerts, and buttons remain legible in both Dark and Light themes:
- **Success:** `--theme-success`, `--theme-success-hover`, `--theme-success-bg`, `--theme-success-border`, `--theme-success-text` (`bg-theme-success-bg text-theme-success-text border-theme-success-border`)
- **Warning:** `--theme-warning`, `--theme-warning-hover`, `--theme-warning-bg`, `--theme-warning-border`, `--theme-warning-text`
- **Danger:** `--theme-danger`, `--theme-danger-hover`, `--theme-danger-bg`, `--theme-danger-border`, `--theme-danger-text`
- **Info:** `--theme-info`, `--theme-info-hover`, `--theme-info-bg`, `--theme-info-border`, `--theme-info-text`

> **Why `--theme-<status>-text` matters for Light Themes:**  
> On a dark theme, `--theme-success-text` is a light mint (`#34d399`) on a dark translucent emerald background (`rgba(16, 185, 129, 0.12)`). On a light theme, `#34d399` on white is unreadable (1.6:1 contrast); a light theme sets `--theme-success-text: #065f46` (deep emerald) on `--theme-success-bg: #ecfdf5`!

### 2.6 Geometry & Typography Tokens
| CSS Variable | Tailwind / CSS Utility | Purpose |
| :--- | :--- | :--- |
| `--theme-font-sans` | `font-sans` | Primary body & prose font stack |
| `--theme-font-mono` | `font-mono` | Code, paths, IPs, logs, and technical metrics |
| `--theme-font-ui` | `font-ui` / `.theme-ui-control` | Font stack for UI controls (buttons, labels, table headers, badges) |
| `--theme-radius-sm` | `rounded-theme-sm` | Small controls & icon buttons |
| `--theme-radius-md` | `rounded-theme-md` | Standard buttons, inputs, selects, textareas, tabs |
| `--theme-radius-lg` | `rounded-theme-lg` | Cards, data tables, modals, alerts |
| `--theme-radius-badge` | `rounded-theme-badge` | Badges and toggle switches (`2px` in Avionics, `6px` in Cobalt, `9999px` in Horizon) |
| `--theme-button-transform` | `.theme-ui-control` | `uppercase` in tactical themes, `none` in modern themes |
| `--theme-button-tracking` | `.theme-ui-control` | Letter spacing (`0.05em` in tactical themes, `normal` in modern themes) |

---

## 3. Shared UI Component Library (`@shared/Ui/*`)

All canonical pages in `Pages/**` and domain components in `Domains/**` must compose interfaces using `@shared/Ui/*` components. Every `Ui*` component automatically checks `useTheme().resolveComponent('<Name>')` for a theme-specific component override and falls back to the tokenized default template.

| Component | Import Path | Props | Slots |
| :--- | :--- | :--- | :--- |
| **UiPageHeader** | `@shared/Ui/UiPageHeader.vue` | `title` (req), `subtitle` | `#icon`, `#actions` |
| **UiButton** | `@shared/Ui/UiButton.vue` | `variant` (`primary` \| `secondary` \| `danger` \| `danger-soft` \| `warning` \| `success` \| `ghost`), `size` (`xs` \| `sm` \| `md` \| `lg`), `type`, `disabled`, `loading` | `#default` |
| **UiCard** | `@shared/Ui/UiCard.vue` | `title`, `subtitle` | `#header`, `#default`, `#footer` |
| **UiStatCard** | `@shared/Ui/UiStatCard.vue` | `label` (req), `value`, `subtext`, `variant` (`primary` \| `success` \| `warning` \| `danger` \| `info` \| `neutral`) | `#icon`, `#default` |
| **UiDataTable** | `@shared/Ui/UiDataTable.vue` | `headers` (`Array<string \| { label, class }>`), `itemsCount`, `emptyMessage` | `#toolbar`, `#header`, `#default` (`<tr>` rows), `#empty`, `#footer` |
| **UiInput** | `@shared/Ui/UiInput.vue` | `v-model`, `id`, `label`, `hint`, `type`, `placeholder`, `error`, `disabled`, `required` | `#label-right` |
| **UiSelect** | `@shared/Ui/UiSelect.vue` | `v-model`, `id`, `label`, `hint`, `options` (`Array<{value, label} \| primitive>`), `error`, `disabled`, `required` | `#default` (custom `<option>`s) |
| **UiTextarea** | `@shared/Ui/UiTextarea.vue` | `v-model`, `id`, `label`, `hint`, `rows`, `placeholder`, `error`, `disabled`, `required`, `mono` (bool) | `#label-right` |
| **UiTabs** | `@shared/Ui/UiTabs.vue` | `v-model`, `tabs` (`Array<{ id, label, icon? }>`) | — |
| **UiToggle** | `@shared/Ui/UiToggle.vue` | `v-model` (bool), `label`, `description`, `disabled` | — |
| **UiBadge** | `@shared/Ui/UiBadge.vue` | `variant` (`primary` \| `success` \| `warning` \| `danger` \| `info` \| `neutral`) | `#default` |
| **UiAlert** | `@shared/Ui/UiAlert.vue` | `variant` (`info` \| `success` \| `warning` \| `danger`) | `#default` |
| **UiModal** | `@shared/Ui/UiModal.vue` | `show` (bool), `title`, `maxWidth` (`sm` \| `md` \| `lg` \| `xl` \| `2xl` \| `4xl` \| `6xl`), `closeable` (bool) | `#header`, `#default`, `#footer` |

---

## 4. How to Create a New Theme (Step-by-Step)

LiteControl discovers themes automatically from `panel/resources/js/Themes/*/theme.json` on the backend (`App\Domains\PanelConfig\Queries\ThemeManager`) and `import.meta.glob('../../Themes/*/index.js')` on the frontend (`useTheme.js`). **Zero backend PHP changes are required to add a new theme.**

### Directory Structure of a Theme
```text
panel/resources/js/Themes/<theme-id>/
├── theme.json                 # REQUIRED: Theme metadata, color_scheme ("light"|"dark"), fonts
├── theme.css                  # REQUIRED: Scoped html[data-theme="<theme-id>"] CSS tokens
├── index.js                   # REQUIRED: Exports manifest, layouts, icons, and optional component overrides
├── Layouts/
│   ├── AppLayout.vue          # REQUIRED: Main authenticated shell (uses --theme-* tokens)
│   └── AuthLayout.vue         # REQUIRED: Login / 2FA challenge shell (uses --theme-* tokens)
├── Components/                # OPTIONAL (Tier 2): Structural overrides for Button, Card, DataTable, etc.
└── Pages/                     # OPTIONAL (Tier 3): Full page overrides (e.g. ServerOps/Dashboard.vue)
```

### Step 1: Create `theme.json`
```json
{
  "id": "cobalt",
  "name": "Cobalt Precision",
  "description": "Crisp light-mode enterprise theme with cool ice-blue surfaces, cobalt primary accents, and JetBrains Mono telemetry.",
  "version": "1.0.0",
  "author": "LiteControl",
  "layout_type": "vertical-sidebar",
  "color_scheme": "light",
  "progress_color": "#004ac6",
  "fonts": [
    "https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap"
  ]
}
```

### Step 2: Create `theme.css`
Always scope every rule under `html[data-theme="<theme-id>"]` so multiple loaded themes never collide:

```css
/* Cobalt Precision (Light Theme) Tokens */
html[data-theme="cobalt"] {
    color-scheme: light;

    /* Surface Hierarchy */
    --theme-bg-body: #f8f9ff;
    --theme-bg-main: #f8f9ff;
    --theme-bg-header: #ffffff;
    --theme-bg-sidebar: #ffffff;
    --theme-bg-card: #ffffff;
    --theme-bg-surface: #eff4ff;
    --theme-bg-input: #ffffff;
    --theme-bg-elevated: #ffffff;
    --theme-bg-overlay: rgba(11, 28, 48, 0.45);

    /* Borders & Dividers */
    --theme-border: #d8e2f3;
    --theme-border-subtle: #e5eeff;
    --theme-border-strong: #c3c6d7;
    --theme-border-hover: #737686;

    /* Typography Colors */
    --theme-text-primary: #0b1c30;
    --theme-text-secondary: #434655;
    --theme-text-muted: #737686;
    --theme-text-on-accent: #ffffff;

    /* Primary Brand Accent */
    --theme-accent: #004ac6;
    --theme-accent-hover: #2563eb;
    --theme-accent-subtle: #eff4ff;
    --theme-accent-border: #b4c5ff;
    --theme-accent-text: #003ea8;

    /* Semantic Status Colors (High-Contrast for Light Surfaces) */
    --theme-success: #059669;
    --theme-success-hover: #047857;
    --theme-success-bg: #ecfdf5;
    --theme-success-border: #a7f3d0;
    --theme-success-text: #065f46;

    --theme-warning: #d97706;
    --theme-warning-hover: #b45309;
    --theme-warning-bg: #fffbeb;
    --theme-warning-border: #fde68a;
    --theme-warning-text: #92400e;

    --theme-danger: #ba1a1a;
    --theme-danger-hover: #93000a;
    --theme-danger-bg: #ffdad6;
    --theme-danger-border: #fca5a5;
    --theme-danger-text: #93000a;

    --theme-info: #0053db;
    --theme-info-hover: #004ac6;
    --theme-info-bg: #eff4ff;
    --theme-info-border: #b4c5ff;
    --theme-info-text: #003ea8;

    /* Typography & Geometry */
    --theme-font-sans: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    --theme-font-mono: 'JetBrains Mono', monospace;
    --theme-font-ui: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    --theme-radius-sm: 0.25rem;
    --theme-radius-md: 0.375rem;
    --theme-radius-lg: 0.5rem;
    --theme-radius-badge: 0.375rem;
    --theme-button-transform: none;
    --theme-button-tracking: normal;
}
```

### Step 3: Create `Layouts/AppLayout.vue` & `Layouts/AuthLayout.vue`
Your layout components must use `--theme-*` semantic classes (`bg-theme-bg-main`, `bg-theme-bg-header`, `bg-theme-bg-sidebar`, `border-theme-border`, `text-theme-text-primary`, `bg-theme-accent`) and consume the shared headless navigation and theme composables:
- `useNavigation()` (`@shared/Composables/useNavigation`) provides:
  - `user`, `isAdmin`, `filteredNavigation`, `openSubmenus`, `toggleSubmenu(id)`, `isRouteActive(route)`, `isItemActive(item)`
- `useTheme()` (`@shared/Composables/useTheme`) provides:
  - `activeThemeId`, `availableThemes`, `switchTheme(themeId)`
- Include `<LocaleSwitcher />` (`@shared/Components/LocaleSwitcher.vue`) in your header.
- Render flash messages (`$page.props.flash.success` / `$page.props.flash.error`) using `<UiAlert>` or semantic status tokens (`bg-theme-success-bg border-theme-success-border text-theme-success-text`).

### Step 4: Create `index.js`
```js
import './theme.css';
import manifest from './theme.json';
import AppLayout from './Layouts/AppLayout.vue';
import AuthLayout from './Layouts/AuthLayout.vue';

export default {
    ...manifest,
    layouts: {
        default: AppLayout,
        auth: AuthLayout,
    },
    labels: {},
    components: {}, // Leave empty to use tokenized Shared/Ui/* components, or register custom overrides
    icons: {},
};
```

---

## 5. Mandatory Verification & Full-Project Theme Purity Audit

Before completing **ANY** theme or frontend task, run both checks below from `panel/`.

### Check 1: Full-Project Theme Purity Audit (Scans `Pages`, `Domains`, `Shared`, AND `Themes`!)
```powershell
Get-ChildItem -Path "resources/js/Pages","resources/js/Domains","resources/js/Shared","resources/js/Themes" -Recurse -Filter "*.vue" |
    Select-String -Pattern "\b(bg|text|border|ring|divide)-(slate|gray|zinc|amber|purple|emerald|cyan|sky|blue|indigo|rose|red|teal|violet)-\d+|\btext-white\b|\bbg-black\b|\brounded-(xl|2xl|3xl|lg)\b|\[#[0-9a-fA-F]{3,8}\]" |
    Select-Object Path, LineNumber, Line
```
- **Expected result:** Zero matches (other than `text-white` on solid `bg-theme-danger` / `bg-theme-success` inside `UiButton.vue` / `Button.vue`).
- If **any** file in `Themes/<theme-id>/Layouts/*.vue` or `Themes/<theme-id>/Pages/**/*.vue` matches `slate-*`, `sky-*`, `emerald-*`, `text-white`, `[#hex]`, or `rounded-lg`/`rounded-xl`, you MUST replace those classes with `theme-*` semantic utilities before finishing!

### Check 2: Production Vite Build Check
```powershell
npm run build
```
Ensure zero unresolved imports, zero Vue template compilation errors, and clean CSS bundle generation.

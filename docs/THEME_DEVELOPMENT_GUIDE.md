# LiteControl Panel — Theme Development & Design System Guide for Agents

> **Audience:** AI Coding Agents & Frontend Engineers  
> **Scope:** `panel/resources/css/app.css`, `panel/resources/js/Shared/Ui/*`, `panel/resources/js/Themes/*`, `panel/resources/js/Pages/*`, and `panel/resources/js/Domains/*`

LiteControl features a **Multi-Theme Engine** capable of supporting tens of distinct **Dark**, **Light**, **High-Contrast**, **Tactical/HUD**, and **Enterprise** themes without visual bleed ("rainbow themes") or duplicated business logic.

This document is the authoritative contract for:
1. **Building or editing canonical pages/components** so they adapt automatically to every current and future theme (Light or Dark).
2. **Designing and implementing new themes** under `panel/resources/js/Themes/<theme-id>/`.

---

## 1. Golden Rules (Zero "Rainbow Theme" Policy)

When creating or modifying any file in `Pages/**`, `Domains/**`, or `Shared/**`, you **MUST** obey these five rules:

1. **NEVER hardcode Tailwind palette colors for surfaces, text, or borders:**
   - **Forbidden:** `bg-slate-*`, `bg-gray-*`, `bg-zinc-*`, `bg-black`, `bg-white`, `text-white`, `text-slate-*`, `border-slate-*`.
   - **Why:** Hardcoding `text-white` or `bg-slate-900` makes **Light Themes** impossible (producing white-on-white invisible text and black boxes inside white cards).
   - **Required:** Always use semantic tokens: `bg-theme-bg-card`, `bg-theme-bg-surface`, `bg-theme-bg-input`, `text-theme-text-primary`, `text-theme-text-secondary`, `text-theme-text-muted`, `border-theme-border`, `border-theme-border-subtle`.

2. **NEVER hardcode per-page brand/accent colors ("Rainbow Pages"):**
   - **Forbidden:** Using `bg-amber-600` on Tuning, `bg-purple-600` on PHP pages, `bg-emerald-600` on Subdomains, `bg-cyan-600` on Backups, `bg-indigo-600` on Packages, `bg-blue-600` on Services, or `bg-rose-600` on Security save buttons.
   - **Required:** Every primary action button, active tab, active navigation state, focus ring, and brand highlight **MUST** use the active theme's accent (`<UiButton variant="primary">`, `bg-theme-accent`, `text-theme-accent`, `border-theme-accent`).

3. **Reserve semantic status colors strictly for semantic states:**
   - `success` (`--theme-success*`): Online daemons, active SSL, running services, enabled status badges.
   - `warning` (`--theme-warning*`): Suspended domains/users, pending jobs, non-critical warnings.
   - `danger` (`--theme-danger*`): Destructive actions (`Delete`, `Terminate`, `Stop`, `Kill`), offline daemons, expired SSL, validation errors.
   - `info` (`--theme-info*`): Informational callouts and neutral system hints.

4. **NEVER hardcode theme-specific border radii or typography in canonical pages:**
   - **Forbidden:** `rounded-xl`, `rounded-2xl`, `rounded-3xl`, `rounded-lg` on cards, buttons, inputs, tables, or modals.
   - **Why:** Tactical themes like **Avionics** use sharp `2px` corners (`--theme-radius-md: 2px`) and uppercase monospace controls, while modern themes like **Horizon** use rounded `8px–12px` corners (`--theme-radius-lg: 0.75rem`) and sans-serif controls.
   - **Required:** Always use `@shared/Ui/*` components (`<UiCard>`, `<UiButton>`, `<UiInput>`, `<UiSelect>`, `<UiTextarea>`, `<UiDataTable>`, `<UiModal>`, `<UiTabs>`, `<UiBadge>`) or the semantic radius utilities (`rounded-theme-sm`, `rounded-theme-md`, `rounded-theme-lg`, `rounded-theme-badge`) and `theme-ui-control`.

5. **Always use `--theme-text-on-accent` for foreground text on `--theme-accent` backgrounds:**
   - Some themes use bright accents that require dark text (e.g., Avionics `#ff6b35` orange uses `#000000` black text), while others use deep accents that require white text (e.g., Horizon `#0284c7` blue uses `#ffffff` white text). Never pair `bg-theme-accent` with `text-white`—always pair `bg-theme-accent` with `text-theme-text-on-accent`.

---

## 2. Semantic CSS Token Contract (`resources/css/app.css`)

Every theme defines the following CSS custom properties under `html[data-theme="<theme-id>"]` in its `theme.css`. Tailwind v4 maps these in `resources/css/app.css` under `@theme`:

### 2.1 Surface Tokens
| CSS Variable | Tailwind Class (`bg-*`) | Purpose | Dark Example (Avionics) | Light Example (Daylight) |
| :--- | :--- | :--- | :--- | :--- |
| `--theme-bg-body` | `bg-theme-bg-body` | Root `<body>` background | `#080a0e` | `#f8fafc` |
| `--theme-bg-main` | `bg-theme-bg-main` | Main layout viewport canvas | `#090b0e` | `#f1f5f9` |
| `--theme-bg-header` | `bg-theme-bg-header` | Top navigation header bar | `#0d1015` | `#ffffff` |
| `--theme-bg-sidebar` | `bg-theme-bg-sidebar` | Sidebar navigation surface | `#0a0d12` | `#ffffff` |
| `--theme-bg-card` | `bg-theme-bg-card` | Primary card & table container | `#0c1017` | `#ffffff` |
| `--theme-bg-surface` | `bg-theme-bg-surface` | Secondary/nested surface, hover rows, table headers | `#121721` | `#f1f5f9` |
| `--theme-bg-input` | `bg-theme-bg-input` | Form inputs (`input`, `select`, `textarea`) | `#080a0e` | `#ffffff` |
| `--theme-bg-elevated` | `bg-theme-bg-elevated` | Modals, dropdown menus, popovers | `#0e1217` | `#ffffff` |
| `--theme-bg-overlay` | `bg-theme-bg-overlay` | Modal backdrop overlay | `rgba(6, 8, 11, 0.82)` | `rgba(15, 23, 42, 0.45)` |

### 2.2 Border & Divider Tokens
| CSS Variable | Tailwind Class (`border-*` / `divide-*`) | Purpose | Dark Example | Light Example |
| :--- | :--- | :--- | :--- | :--- |
| `--theme-border` | `border-theme-border` | Primary card, input, and table borders | `#1e2634` | `#e2e8f0` |
| `--theme-border-subtle` | `border-theme-border-subtle` | Inner section headers, table row dividers | `#181f2c` | `#f1f5f9` |
| `--theme-border-strong` | `border-theme-border-strong` | Emphasized control borders & scrollbar thumb | `#262f3e` | `#cbd5e1` |
| `--theme-border-hover` | `border-theme-border-hover` | Interactive card hover border | `#313d52` | `#94a3b8` |

### 2.3 Typography Color Tokens
| CSS Variable | Tailwind Class (`text-*`) | Purpose | Dark Example | Light Example |
| :--- | :--- | :--- | :--- | :--- |
| `--theme-text-primary` | `text-theme-text-primary` | Page titles, headings, primary values | `#f1f5f9` | `#0f172a` |
| `--theme-text-secondary` | `text-theme-text-secondary` | Body text, form labels, table cells | `#98a6b8` | `#475569` |
| `--theme-text-muted` | `text-theme-text-muted` | Hints, placeholders, disabled text, timestamps | `#586477` | `#94a3b8` |
| `--theme-text-on-accent` | `text-theme-text-on-accent` | Text placed directly on `bg-theme-accent` | `#000000` or `#ffffff` | `#ffffff` |

### 2.4 Brand Accent Tokens
| CSS Variable | Tailwind Class | Purpose |
| :--- | :--- | :--- |
| `--theme-accent` | `bg-theme-accent`, `text-theme-accent`, `border-theme-accent` | Primary brand color (buttons, active tabs, focus rings) |
| `--theme-accent-hover` | `hover:bg-theme-accent-hover` | Hover state for primary buttons |
| `--theme-accent-subtle` | `bg-theme-accent-subtle` | `10%–15%` alpha tint for active nav items & primary badges |
| `--theme-accent-border` | `border-theme-accent-border` | `25%–35%` alpha border for accented elements |
| `--theme-accent-text` | `text-theme-accent-text` | Legible accent text on subtle/card backgrounds (dark & light safe) |

### 2.5 Semantic Status Tokens (Light & Dark Adaptive)
Each status (`success`, `warning`, `danger`, `info`) provides 5 tokens so status badges, alerts, and buttons remain legible in both Dark and Light themes:
- **Success:** `--theme-success`, `--theme-success-hover`, `--theme-success-bg`, `--theme-success-border`, `--theme-success-text` (`bg-theme-success-bg text-theme-success-text border-theme-success-border`)
- **Warning:** `--theme-warning`, `--theme-warning-hover`, `--theme-warning-bg`, `--theme-warning-border`, `--theme-warning-text`
- **Danger:** `--theme-danger`, `--theme-danger-hover`, `--theme-danger-bg`, `--theme-danger-border`, `--theme-danger-text`
- **Info:** `--theme-info`, `--theme-info-hover`, `--theme-info-bg`, `--theme-info-border`, `--theme-info-text`

> **Why `--theme-<status>-text` matters for Light Themes:**  
> On a dark theme, `--theme-success-text` is a light mint (`#34d399`) on a dark translucent emerald background (`rgba(16, 185, 129, 0.12)`). On a light theme, `#34d399` on white is unreadable (1.6:1 contrast); a light theme sets `--theme-success-text: #047857` (deep emerald) on `--theme-success-bg: #ecfdf5`!

### 2.6 Geometry & Typography Tokens
| CSS Variable | Tailwind / CSS Utility | Purpose |
| :--- | :--- | :--- |
| `--theme-font-sans` | `font-sans` | Primary body & prose font stack |
| `--theme-font-mono` | `font-mono` | Code, paths, IPs, logs, and technical metrics |
| `--theme-font-ui` | `font-ui` / `.theme-ui-control` | Font stack for UI controls (buttons, labels, table headers, badges) |
| `--theme-radius-sm` | `rounded-theme-sm` | Small controls & icon buttons |
| `--theme-radius-md` | `rounded-theme-md` | Standard buttons, inputs, selects, textareas, tabs |
| `--theme-radius-lg` | `rounded-theme-lg` | Cards, data tables, modals, alerts |
| `--theme-radius-badge` | `rounded-theme-badge` | Badges and toggle switches (`2px` in Avionics, `9999px` in Horizon) |
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
│   ├── AppLayout.vue          # REQUIRED: Main authenticated shell (sidebar/topbar/command-bar)
│   └── AuthLayout.vue         # REQUIRED: Login / 2FA challenge shell
├── Components/                # OPTIONAL (Tier 2): Structural overrides for Button, Card, DataTable, etc.
└── Pages/                     # OPTIONAL (Tier 3): Full page overrides (e.g. ServerOps/Dashboard.vue)
```

### Step 1: Create `theme.json`
```json
{
  "id": "nordic-light",
  "name": "Nordic Daylight",
  "description": "High-clarity light enterprise interface with crisp slate borders and cobalt accents.",
  "version": "1.0.0",
  "author": "LiteControl",
  "layout_type": "vertical-sidebar",
  "color_scheme": "light",
  "progress_color": "#2563eb",
  "fonts": [
    "https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap"
  ]
}
```

### Step 2: Create `theme.css` (Light Theme Example)
Always scope every rule under `html[data-theme="<theme-id>"]` so multiple loaded themes never collide:

```css
/* Nordic Daylight (Light Theme) Tokens */
html[data-theme="nordic-light"] {
    color-scheme: light;

    /* Surface Hierarchy */
    --theme-bg-body: #f8fafc;
    --theme-bg-main: #f1f5f9;
    --theme-bg-header: #ffffff;
    --theme-bg-sidebar: #ffffff;
    --theme-bg-card: #ffffff;
    --theme-bg-surface: #f1f5f9;
    --theme-bg-input: #ffffff;
    --theme-bg-elevated: #ffffff;
    --theme-bg-overlay: rgba(15, 23, 42, 0.45);

    /* Borders & Dividers */
    --theme-border: #e2e8f0;
    --theme-border-subtle: #f1f5f9;
    --theme-border-strong: #cbd5e1;
    --theme-border-hover: #94a3b8;

    /* Typography Colors */
    --theme-text-primary: #0f172a;
    --theme-text-secondary: #475569;
    --theme-text-muted: #64748b;
    --theme-text-on-accent: #ffffff;

    /* Primary Brand Accent */
    --theme-accent: #2563eb;
    --theme-accent-hover: #1d4ed8;
    --theme-accent-subtle: rgba(37, 99, 235, 0.10);
    --theme-accent-border: rgba(37, 99, 235, 0.25);
    --theme-accent-text: #1d4ed8;

    /* Semantic Status Colors (High-Contrast for Light Surfaces) */
    --theme-success: #059669;
    --theme-success-hover: #047857;
    --theme-success-bg: #ecfdf5;
    --theme-success-border: #a7f3d0;
    --theme-success-text: #047857;

    --theme-warning: #d97706;
    --theme-warning-hover: #b45309;
    --theme-warning-bg: #fffbeb;
    --theme-warning-border: #fde68a;
    --theme-warning-text: #b45309;

    --theme-danger: #e11d48;
    --theme-danger-hover: #be123c;
    --theme-danger-bg: #fff1f2;
    --theme-danger-border: #fecdd3;
    --theme-danger-text: #be123c;

    --theme-info: #0284c7;
    --theme-info-hover: #0369a1;
    --theme-info-bg: #f0f9ff;
    --theme-info-border: #bae6fd;
    --theme-info-text: #0369a1;

    /* Typography & Geometry */
    --theme-font-sans: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    --theme-font-mono: 'JetBrains Mono', monospace;
    --theme-font-ui: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    --theme-radius-sm: 0.375rem;
    --theme-radius-md: 0.5rem;
    --theme-radius-lg: 0.75rem;
    --theme-radius-badge: 9999px;
    --theme-button-transform: none;
    --theme-button-tracking: normal;
}
```

### Step 3: Create `Layouts/AppLayout.vue` & `Layouts/AuthLayout.vue`
Your layout components should consume the shared headless navigation and theme composables:
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

## 5. Verification & Automated Theme Purity Audit

Before completing any frontend task (whether adding a page, modifying a component, or creating a theme), run the following checks from `panel/`:

1. **Theme Purity Check (Zero Hardcoded Palette / Radius Violations in `Pages/`, `Domains/`, `Shared/`):**
   ```powershell
   Get-ChildItem -Path "resources/js/Pages","resources/js/Domains","resources/js/Shared" -Recurse -Filter "*.vue" |
       Select-String -Pattern "\b(bg|text|border)-(slate|gray|zinc|amber|purple|emerald|cyan|sky|blue|indigo|rose|red)-\d+|\btext-white\b|\brounded-(xl|2xl|3xl)\b"
   ```
   *(Note: The only allowed `text-white` in `Shared/Ui/UiButton.vue` is on solid `bg-theme-danger` / `bg-theme-success` button variants).*

2. **Production Vite Build Check:**
   ```powershell
   npm run build
   ```
   Ensure zero unresolved imports, zero Vue template compilation errors, and clean CSS bundle generation.

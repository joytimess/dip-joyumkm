# DS-3 — Core Components

## 1. Objective
Build a comprehensive, reusable native FigCraft component library covering all generic UI primitives and JoyUMKM-specific domain components required by the live application, using native auto layout, variable bindings, nested linked instances, and consistent naming conventions.

## 2. Implementation Scope
65 native `COMPONENT` masters organized into 7 categorized trays inside `DS-3 — Core Components`:
1. **Actions (5)**: `Action/Button`, `Action/Icon Button`, `Action/Link`, `Action/Button Group`, `Action/Dropdown Action` (Floating Action Button omitted as not applicable to JoyUMKM desktop/mobile patterns).
2. **Form Controls (15)**: `Form/Text Input`, `Form/Email Input`, `Form/Password Input`, `Form/Number Input`, `Form/Search Input`, `Form/Select`, `Form/Textarea`, `Form/Date Picker`, `Form/File Upload`, `Form/Checkbox`, `Form/Radio Button`, `Form/Switch`, `Form/Field Label`, `Form/Helper Text`, `Form/Validation Message`.
3. **Navigation (8)**: `Navigation/Sidebar Item`, `Navigation/Nav Group`, `Navigation/Sidebar`, `Navigation/Topbar`, `Navigation/Breadcrumb`, `Navigation/Tabs`, `Navigation/Pagination`, `Navigation/User Profile Menu`.
4. **Feedback (10)**: `Feedback/Alert`, `Feedback/Toast`, `Feedback/Badge`, `Feedback/Status Indicator`, `Feedback/Tooltip`, `Feedback/Progress Bar`, `Feedback/Spinner`, `Feedback/Skeleton`, `Feedback/Empty State`, `Feedback/Error State`.
5. **Data Display (11)**: `Data/Card`, `Data/KPI Card`, `Data/Statistic Card`, `Data/Table Cell`, `Data/Table Header`, `Data/Table Row`, `Data/Data Table`, `Data/Avatar`, `Data/Divider`, `Data/Accordion`, `Data/Chart Legend`.
6. **Overlays (5)**: `Overlay/Modal`, `Overlay/Confirmation Dialog`, `Overlay/Dropdown Menu`, `Overlay/Popover`, `Overlay/Drawer`.
7. **JoyUMKM-Specific (11)**: `JoyUMKM/Health Classification Badge`, `JoyUMKM/Evaluation Score Indicator`, `JoyUMKM/Report Download Action`, `JoyUMKM/Email Delivery Status`, `JoyUMKM/Analyst Identity Card`, `JoyUMKM/Three-Dimensional Indicator`, `JoyUMKM/Six-Factor Indicator`, `JoyUMKM/UMKM Profile Card`, `JoyUMKM/Evaluation Summary Card`, `JoyUMKM/Intelligence Report Card`, `JoyUMKM/Business Recommendation Card`.

## 3. Actual Execution
1. Created `DS-3 — Core Components` section (`id: 318`, resized to `3260px` height) at `(0, 5390)` with auto-layout board `DS-3 / Core Components Board` (`id: 319`).
2. Built all 65 native components across 4 atomic `batch` operations (`3A`–`3D`), binding key component surfaces, strokes, and corner radii to DS-2 `component/*` variables.
3. Composed nested component instances inside composite masters (`Navigation/Sidebar` → `Navigation/Sidebar Item`; `Data/Data Table` → `Data/Table Header` + `Data/Table Row`; `JoyUMKM/Analyst Identity Card` → `Data/Avatar`; `JoyUMKM/UMKM Profile Card` → `JoyUMKM/Health Classification Badge`; `JoyUMKM/Evaluation Summary Card` → `JoyUMKM/Evaluation Score Indicator`; `JoyUMKM/Intelligence Report Card` → `JoyUMKM/Report Download Action`).
4. Verified all 65 components via `component.list` and saved Checkpoint #2 (`89,027 bytes`).

## 4. Created FigCraft Objects (Verified Native IDs)
| Category | Component Name | Native ID |
|---|---|---|
| Actions | `Action/Button`, `Action/Icon Button`, `Action/Link`, `Action/Button Group`, `Action/Dropdown Action` | `329`, `331`, `333`, `335`, `342` |
| Form Controls | `Form/Text Input`, `Email`, `Password`, `Number`, `Search`, `Select`, `Textarea`, `Date Picker`, `File Upload`, `Checkbox`, `Radio Button`, `Switch`, `Field Label`, `Helper Text`, `Validation Message` | `348`, `350`, `352`, `355`, `358`, `361`, `364`, `366`, `369`, `373`, `377`, `381`, `385`, `387`, `389` |
| Navigation | `Navigation/Sidebar Item`, `Nav Group`, `Sidebar`, `Topbar`, `Breadcrumb`, `Tabs`, `Pagination`, `User Profile Menu` | `394`, `397`, `403`, `416`, `426`, `432`, `436`, `445` |
| Feedback | `Feedback/Alert`, `Toast`, `Badge`, `Status Indicator`, `Tooltip`, `Progress Bar`, `Spinner`, `Skeleton`, `Empty State`, `Error State` | `453`, `456`, `459`, `461`, `464`, `466`, `472`, `476`, `480`, `485` |
| Data Display | `Data/Card`, `KPI Card`, `Statistic Card`, `Avatar`, `Divider`, `Accordion`, `Chart Legend`, `Table Cell`, `Table Header`, `Table Row`, `Data Table` | `493`, `496`, `500`, `504`, `506`, `507`, `512`, `524`, `526`, `528`, `530` |
| Overlays | `Overlay/Modal`, `Confirmation Dialog`, `Dropdown Menu`, `Popover`, `Drawer` | `540`, `551`, `559`, `562`, `565` |
| JoyUMKM Domain | `JoyUMKM/Health Classification Badge`, `Evaluation Score Indicator`, `Report Download Action`, `Email Delivery Status`, `Analyst Identity Card`, `Three-Dimensional Indicator`, `Six-Factor Indicator`, `UMKM Profile Card`, `Evaluation Summary Card`, `Intelligence Report Card`, `Business Recommendation Card` | `573`, `576`, `582`, `588`, `593`, `602`, `616`, `640`, `648`, `657`, `668` |

## 5. Design Decisions
- **JoyUMKM-First Prioritization**: Every component mirrors actual JoyUMKM patterns (e.g., `44px` minimum touch target from `--control-height`, `+62` / `Rp` numeric affordances, 5 UMKM detail tabs, 6-box Six-Factor indicator grid, separated 3D vs 6F score cards).
- **Composable Atomic Nesting**: Built atomic primitives first so higher-order domain cards link directly to live component instances rather than duplicating shapes.
- **No Unnecessary FAB**: JoyUMKM uses top-right page heading CTAs (`Tambah Data`) rather than Floating Action Buttons; FAB is omitted to stay faithful to the product.

## 6. Naming Conventions
- Slash-grouped semantic hierarchy: `<Category>/<Component Name>` (`Action/*`, `Form/*`, `Navigation/*`, `Feedback/*`, `Data/*`, `Overlay/*`, `JoyUMKM/*`), automatically populating FigCraft's Assets panel folders.

## 7. Acceptance Criteria
- [x] All required Actions, Form Controls, Navigation, Feedback, Data Display, Overlays, and 11 JoyUMKM-specific components created as native `COMPONENT` nodes.
- [x] Native auto layout, semantic tokens, editable text layers, and nested linked instances verified.

## 8. Verification Evidence
- `component.list` returned all 65 native components on Page `2` (`Design System`).
- Checkpoint #2 saved to `docs/design-system/joyumkm-design-system.figcraft` (`89,027 bytes`).
- Screenshot captured and inspected in DS-7 (`evidence/ds-3-core-components.png`).

## 9. Known Limitations
- Date picker calendar popover and native HTML `<select>` OS dropdown chrome are depicted in their resting trigger + overlay menu forms.

## 10. Final Verdict
**PASS** — 65 reusable native FigCraft components built, nested, token-bound, and persisted.

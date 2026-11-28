# DS-5 — UI Patterns

## 1. Objective
Compose reusable DS-3 Core Components and DS-4 Component Set Variants into all 23 JoyUMKM UI workflow patterns using live linked instances (`instance.create` + `instance.setProperties`) with zero unjustified detached duplicates.

## 2. Implementation Scope
23 reusable UI patterns organized into 5 rows inside `DS-5 — UI Patterns` (`id: 815`, board `id: 816`):
1. `Pattern 01 / Dashboard Page Header` (`824`)
2. `Pattern 02 / Dashboard KPI Overview` (`843`)
3. `Pattern 03 / Sidebar & Topbar Navigation` (`881`)
4. `Pattern 04 / Search & Filter Toolbar` (`862`)
5. `Pattern 05 / Data Table with Pagination` (`905`)
6. `Pattern 06 / CRUD Form (UMKM)` (`926`)
7. `Pattern 07 / Multi-Step Wizard Form` (`947`)
8. `Pattern 08 / Evaluation Questionnaire` (`968`)
9. `Pattern 09 / Evaluation Score Summary` (`987`)
10. `Pattern 10 / Three-Dimensional Evaluation` (`1003`)
11. `Pattern 11 / Six-Factor Evaluation` (`1029`)
12. `Pattern 12 / UMKM Health Classification Scale` (`1061`)
13. `Pattern 13 / Intelligence & Recommendation Section` (`1078`)
14. `Pattern 14 / Report Download & Email Status` (`1103`)
15. `Pattern 15 / User Management (Analysts)` (`1123`)
16. `Pattern 16 / Analyst Assignment` (`1148`)
17. `Pattern 17 / UMKM Ownership Transfer` (`1164`)
18. `Pattern 18 / Confirmation Dialog` (`1188`)
19. `Pattern 19 / Destructive Action Confirmation` (`1201`)
20. `Pattern 20 / Empty Data State` (`1211`)
21. `Pattern 21 / Loading State` (`1218`)
22. `Pattern 22 / Error Recovery` (`1227`)
23. `Pattern 23 / Responsive Navigation (≤1100px)` (`1237`)

## 3. Actual Execution
1. Adjusted `DS-4 — Variants & States` section (`id: 674`) height to `1928px`.
2. Created `DS-5 — UI Patterns` section (`id: 815`) at `(0, 11060)` with width `3600px` and auto-layout board `DS-5 / UI Patterns Board` (`id: 816`).
3. Composed Patterns 01–23 across batches `5A` and `5B`, instantiating DS-3/DS-4 components via `instance.create` and applying live variant/text overrides via `instance.setProperties`.
4. Saved Checkpoint #3 (`joyumkm-design-system.figcraft`).

## 4. Created FigCraft Objects
- Section: `DS-5 — UI Patterns` (`id: 815`), Board `816`.
- 23 pattern frames (`id: 824` through `1239`) composing **65+ live linked component instances** referencing DS-3 masters and DS-4 Component Sets.

## 5. Design Decisions
- **100% Instance Composition**: Every interactive button, input, select, table, pagination control, modal, confirmation dialog, status badge, health tier, KPI card, 3D/6F indicator, analyst card, and intelligence widget in DS-5 is a linked `INSTANCE` node, ensuring global updates propagate automatically.
- **Faithful Multi-Step Wizard & Governance**: Pattern 07 reflects JoyUMKM's actual 3-step UMKM creation modal (`A. Informasi`, `B. Lokasi`, `C. Kontak`), and Pattern 17 explicitly reflects JoyUMKM's immutable Superadmin ownership transfer audit trail (`admin-transfers.blade.php`).

## 6. Naming Conventions
- Pattern boards: `Pattern NN / <Pattern Name>` (`Pattern 01` through `Pattern 23`).

## 7. Acceptance Criteria
- [x] All 23 requested JoyUMKM UI patterns created natively.
- [x] Every pattern composes linked component instances with live variant/property overrides.
- [x] Zero detached component duplicates.

## 8. Verification Evidence
- All `instance.create` and `instance.setProperties` calls succeeded with zero rollbacks.
- Linked instance counts and propagation verified via `component.list` and DS-7 Verification Playground (`evidence/ds-5-ui-patterns.png`).

## 9. Known Limitations
- Complex interactive states (e.g., Leaflet pin drag on Step B of the UMKM wizard or live ApexCharts hover tooltips) are documented as static editable design compositions.

## 10. Final Verdict
**PASS** — All 23 UI patterns composed cleanly from linked component instances.

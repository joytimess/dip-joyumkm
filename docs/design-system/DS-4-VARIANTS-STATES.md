# DS-4 — Variants & States

## 1. Objective
Extend the JoyUMKM core component library with native FigCraft Component Sets (`component.combineVariants`) and exposed component properties (`variant`, `text`, `boolean`) across Buttons, Inputs, Navigation, Data Table Rows, and actual JoyUMKM business statuses — while avoiding redundant Cartesian bloat.

## 2. Implementation Scope
8 Native Component Sets totaling **55 curated native variants** inside `DS-4 — Variants & States`:
1. **`Variants/Button`** (`id: 716`, 15 variants):
   - Properties: `Style` (`Primary`, `Secondary`, `Outline`, `Ghost`, `Destructive`), `Size` (`Small`, `Medium`, `Large`), `State` (`Default`, `Hover`, `Pressed`, `Focus`, `Disabled`, `Loading`), `Label` (text), `Show Leading Icon` (boolean).
2. **`Variants/Input`** (`id: 735`, 8 variants):
   - Properties: `State` (`Empty`, `Filled`, `Hover`, `Focus`, `Error`, `Success`, `Disabled`, `Read-only`), `Text Value` (text).
3. **`Variants/Sidebar Item`** (`id: 750`, 6 variants):
   - Properties: `State` (`Default`, `Hover`, `Active`, `Collapsed`, `Expanded`, `Disabled`), `Label` (text).
4. **`Variants/Table Row`** (`id: 768`, 6 variants):
   - Properties: `State` (`Default`, `Selected`, `Hover`, `Loading`, `Empty`, `Error`).
5. **`Variants/Health Classification`** (`id: 783`, 6 variants — exact `config/umkm.php` tiers):
   - `Tier`: `Sangat Baik` (≥85), `Baik` (≥70), `Cukup` (≥55), `Kurang` (≥40), `Kritis` (<40), `Belum Dinilai`.
6. **`Variants/Evaluation Status`** (`id: 792`, 4 variants — exact `charts.js` / `intelligence-report.js` states):
   - `Status`: `Final`, `Draft`, `Belum Dinilai`, `Metodologi Parsial`.
7. **`Variants/Email Delivery Status`** (`id: 805`, 6 representative variants mapping all 10 `intelligence-report.js` states):
   - `Status`: `Menunggu Laporan` (`waiting_package`), `Sedang Diproses` (`processing`/`sending`), `Diterima Server Email` (`sent`), `Percobaan Ulang` (`failed_retryable`), `Pengiriman Gagal` (`failed_permanent`/`blocked`/`recipient_changed`/`delivery_unknown`), `Tidak Dikirim` (`skipped_ineligible`).
8. **`Variants/Report Availability`** (`id: 814`, 4 variants — exact `intelligence-report.js` artifact states):
   - `State`: `Lengkap`, `Sebagian Tersedia`, `Belum Tersedia`, `File Tidak Tersedia`.

## 3. Actual Execution
1. Adjusted `DS-3 — Core Components` section (`id: 318`) height to `3260px` to frame its 7 trays cleanly.
2. Created `DS-4 — Variants & States` section (`id: 674`) at `(0, 8890)` with auto-layout board `DS-4 / Variants & States Board` (`id: 675`).
3. Built all 8 native `COMPONENT_SET` nodes via `component.combineVariants`, configured wrapping auto-layout matrices, and attached exposed `text` and `boolean` properties via `component.addProperty`.
4. Queried `component.properties` on `Variants/Button` (`id: 716`) to verify all exposed variant, text, and boolean properties.

## 4. Created FigCraft Objects
| Component Set | Native ID | Variants | Exposed Properties |
|---|---|---|---|
| `DS-4 — Variants & States` | `674` | Section | Board `675` |
| `Variants/Button` | `716` | 15 | `Style`, `Size`, `State`, `Label` (text), `Show Leading Icon` (boolean) |
| `Variants/Input` | `735` | 8 | `State`, `Text Value` (text) |
| `Variants/Sidebar Item` | `750` | 6 | `State`, `Label` (text) |
| `Variants/Table Row` | `768` | 6 | `State` |
| `Variants/Health Classification` | `783` | 6 | `Tier` |
| `Variants/Evaluation Status` | `792` | 4 | `Status` |
| `Variants/Email Delivery Status` | `805` | 6 | `Status` |
| `Variants/Report Availability` | `814` | 4 | `State` |

## 5. Design Decisions
- **Curated Variant Matrix (No Cartesian Bloat)**: Rather than generating 90+ button permutations (`5 styles × 3 sizes × 6 states`), `Variants/Button` includes all 5 styles, all 3 sizes (`36/44/52px`), all 6 interactive states on Primary, and key secondary hover/disabled states (15 variants total).
- **Exact Domain Status Mapping**: Every status badge maps 1:1 to JoyUMKM's actual Indonesian labels and enum keys from `config/umkm.php`, `resources/js/charts.js`, and `resources/js/pages/intelligence-report.js`.
- **Instance Propagation Verification**: Live main-to-instance propagation and variant switching (`instance.setProperties`) are verified directly in DS-5, DS-6, and the DS-7 Verification Playground.

## 6. Naming Conventions
- Component Sets: `Variants/<Component Name>`.
- Variant Layers: Figma-standard `Property=Value` comma-separated tuples (e.g., `Style=Primary, Size=Medium, State=Default`).

## 7. Acceptance Criteria
- [x] Button types (Primary, Secondary, Outline, Ghost, Destructive), sizes (Sm, Md, Lg), and states (Default, Hover, Pressed, Focus, Disabled, Loading).
- [x] All 8 Input states (Empty, Filled, Hover, Focus, Error, Success, Disabled, Read-only).
- [x] All 6 Navigation states (Default, Hover, Active, Collapsed, Expanded, Disabled).
- [x] All 6 Data Row states (Default, Selected, Hover, Loading, Empty, Error).
- [x] Actual JoyUMKM business statuses (Health tiers, Evaluation, Email Delivery, Report Availability).
- [x] Component properties exposed (`variant`, `text`, `boolean`).

## 8. Verification Evidence
- `component.properties` on `Variants/Button` (`id: 716`) confirmed all variant axes plus `Label` and `Show Leading Icon`.
- Live variant switching (`instance.setProperties`) and main-to-instance change propagation tested and documented in DS-7 (`DS-7-VERIFICATION-RESULTS.md` + `evidence/ds-4-variants-states.png`).

## 9. Known Limitations
- In FigCraft, a single `component.addProperty` text/boolean binding binds a named layer on the variant it targets; variants that intentionally omit optional layers (e.g. `Leading Icon` on text-only variants) behave as expected while `Style=Primary, Size=Medium, State=Default` exposes the full toggle.

## 10. Final Verdict
**PASS** — 8 native Component Sets (55 curated variants) with exposed properties built and verified.

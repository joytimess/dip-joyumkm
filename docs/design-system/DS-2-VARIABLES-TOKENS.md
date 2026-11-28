# DS-2 — Variables & Tokens

## 1. Objective
Implement a scalable, three-tier native FigCraft variable and style architecture (`Primitives → Semantic → Components`) preserving JoyUMKM's visual language, using native variable aliases instead of duplicating raw values, binding foundation layers and styles directly to tokens, and exporting a machine-readable token inventory (`design-tokens.json`).

## 2. Implementation Scope
- **3 Native Variable Collections**:
  1. `Primitives` (`VariableCollectionId:1`, mode: `Value`) — 81 raw scale variables across `color/primitive/`, `typography/`, `spacing/`, `sizing/`, `radius/`, `border/`, `elevation/`, `opacity/`.
  2. `Semantic` (`VariableCollectionId:2`, mode: `Light`) — 50 intent-driven color variables aliased to Primitives across `background`, `surface`, `text`, `border`, `interactive`, `focus`, `feedback`, `navigation`, `form`, and `dataviz` (including all 5 chart series and 6 health tiers).
  3. `Components` (`VariableCollectionId:3`, mode: `Default`) — 23 scoped component variables aliased to Semantic/Primitive tokens across `component/button/`, `component/input/`, `component/card/`, `component/sidebar/`, `component/badge/`, and `component/modal/`.
- **25 Native Styles**: 10 Paint styles (`S:1`–`S:10`, with 9 bound to semantic variables via `style.bind`), 14 Poppins Text styles (`S:11`–`S:24`), and 1 Elevation Effect style (`S:25`).
- **Live Foundation Bindings**: Bound DS-1 color chips (`72, 81, 87, 95, 98, 122, 127, 130, 133, 136`) and DS-1 foundation cards (`59, 157, 193`) directly to semantic/component variables.
- **Machine-Readable Exports**: `design-tokens.json`, `evidence/design-tokens.css`, `evidence/tailwind-theme.css`.

## 3. Actual Execution
1. Adjusted `DS-1 — Design Foundations` section (`id: 51`) height to `2427px` to fit its auto-layout board cleanly.
2. Created `Primitives`, `Semantic`, and `Components` collections and 154 native variables (`VariableID:1` through `VariableID:154`) using native `{alias}` references for all 50 semantic tokens and all 23 component tokens.
3. Bound DS-1 foundation swatch chips and cards via `variable.bind`.
4. Created 25 native styles (`S:1`–`S:25`) and bound 9 solid paint styles to semantic color variables via `style.bind`.
5. Built the `DS-2 — Variables & Tokens` visual architecture section (`id: 294`) at `(0, 4130)`.
6. Exported machine-readable token files natively with `variable.export` and saved Checkpoint #1 (`joyumkm-design-system.figcraft`).

## 4. Created FigCraft Objects
| Object | Type | Native ID | Details |
|---|---|---|---|
| `Primitives` | Variable Collection | `VariableCollectionId:1` | Mode: `Value`, 81 variables (`VariableID:1–40`, `91–131`) |
| `Semantic` | Variable Collection | `VariableCollectionId:2` | Mode: `Light`, 50 aliased variables (`VariableID:41–90`) |
| `Components` | Variable Collection | `VariableCollectionId:3` | Mode: `Default`, 23 aliased variables (`VariableID:132–154`) |
| Paint Styles (10) | `PAINT` Styles | `S:1`–`S:10` | `Brand/*`, `Surface/*`, `Text/*`, `Feedback/*` (9 variable-bound) |
| Text Styles (14) | `TEXT` Styles | `S:11`–`S:24` | `Typography/Display` through `Typography/Numeric-KPI` |
| Effect Style (1) | `EFFECT` Style | `S:25` | `Elevation/Card` |
| `DS-2 — Variables & Tokens` | `SECTION` | `294` | Board `295`, 24 native layers (`294`–`317`) |

## 5. Design Decisions
- **Zero Raw Value Duplication in Semantic/Component Layers**: Every single Semantic (`50/50`) and Component (`23/23`) variable resolves via native `{alias}` pointers to lower tiers.
- **Light Mode Only (Production)**: JoyUMKM's `resources/css/app.css` explicitly declares `:root { color-scheme: light; }` and implements no theme toggle. Adding a fabricated production Dark mode would misrepresent application parity; Dark mode is explicitly marked experimental/optional for a future Semantic collection mode.
- **Canonical Naming Prefixes**: Enforced exact slash-delimited prefixes (`color/primitive/`, `color/semantic/`, `typography/`, `spacing/`, `sizing/`, `radius/`, `border/`, `elevation/`, `opacity/`, `component/`) to ensure deterministic CSS custom property and Tailwind v4 `@theme` exports.

## 6. Naming Conventions
- Primitives: `<category>/<group>/<scale>` (e.g., `color/primitive/cobalt-600`, `spacing/4`, `radius/card`).
- Semantics: `color/semantic/<domain>/<role>` (e.g., `color/semantic/interactive/primary`, `color/semantic/feedback/error`).
- Components: `component/<component>/<slot-property>` (e.g., `component/button/primary-bg`, `component/card/radius`).

## 7. Acceptance Criteria
- [x] Three-tier hierarchy (`Primitive → Semantic → Component`) created natively.
- [x] All 10 required naming prefixes implemented.
- [x] Native variable aliases used across 100% of Semantic and Component variables (73 aliased variables).
- [x] Foundations and Paint styles bound directly to native variables.
- [x] Machine-readable `design-tokens.json` exported via `variable.export`.

## 8. Verification Evidence
- `docs/design-system/design-tokens.json` generated natively by FigCraft (`variable.export {format: "json"}`).
- `docs/design-system/evidence/design-tokens.css` and `evidence/tailwind-theme.css` exported natively.
- Native checkpoint saved to `docs/design-system/joyumkm-design-system.figcraft` (`26,197 bytes`).
- Live variable binding propagation verified in DS-7.

## 9. Known Limitations
- FigCraft's gradient paint style (`Brand/Gradient`, `S:4`) uses multi-stop linear gradient stops; individual stop bindings are supported, while CSS `linear-gradient(112deg, ...)` angle mapping requires developer reference in DS-8.
- Multi-axis CSS shadows (`0 2px 8px rgba(...)`) are stored as Effect Styles (`S:25`) alongside scalar blur tokens (`elevation/*`) because CSS shadow strings are composite values.

## 10. Final Verdict
**PASS** — 154 native variables (73 aliased), 25 native styles, foundation variable bindings, visual DS-2 board, and machine-readable JSON/CSS/Tailwind exports verified.

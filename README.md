# GLPI Command Palette

A Ctrl/Cmd+K launcher for GLPI. One box reaching records, every menu
destination, and a handful of named actions.

![Command palette](docs/screenshots/palette-01-search.png)

## Compared to core's Find menu

GLPI 11 ships a fuzzy finder on Ctrl+Alt+G. It is a *menu* finder —
`Html::getMenuFuzzySearchList()` returns navigation entries, so it reaches the
ticket list but not a ticket. This plugin searches records as well and folds the
menu in as one result group. It does not touch core's binding; the two coexist.

| | Find menu (core) | Command palette |
|---|---|---|
| Shortcut | Ctrl+Alt+G | Ctrl/Cmd+K (configurable) |
| Menu destinations | ✔ | ✔ |
| Records (tickets, assets, users…) | ✘ | ✔ via global search |
| Type scoping | ✘ | `computer: dell` |
| Jump by id | ✘ | `#4821` |
| Recents | ✘ | ✔ (browser-local) |

## Usage

| Input | Does |
|---|---|
| `laptop` | Searches records and commands |
| `>ticket` | Commands and menu only |
| `computer: dell` | Records of one type only |
| `#4821` | Direct jump to that ticket |
| `↑` `↓` | Move selection |
| `↵` | Open |
| `⌘↵` / `Ctrl+↵` | Open in a new tab |
| `esc` | Close |

Type scoping accepts any of GLPI's 30 `globalsearch_types`. The six searched by
default are what runs without being asked, not a ceiling.

## Optional: faster record search

Records are matched with GLPI's `contains`, which matches by whole word — type
`priner` and nothing comes back.

With the `glpisearch` plugin installed and indexing, record results come from
there instead: typo-tolerant, matching from the first character, answering in
single-digit milliseconds. Types it is not indexing still go through GLPI's own
search, as does everything if Meilisearch is unreachable.

The switch is under Setup → Plugins → Command palette and reports the other
plugin's actual state. `src/FastSearch.php` is the only file naming it, every
reference is a string rather than an import, and every call is guarded.

## Install

```bash
# from the GLPI root — the directory must be named for the plugin key,
# which is not the repository name
git clone https://github.com/bijstaan/glpi-palette.git plugins/glpipalette
php bin/console plugin:install -u glpi glpipalette
php bin/console plugin:activate glpipalette
```

## Settings

**Setup → Plugins → Command Palette.**

| Setting | Default |
|---|---|
| Searched by default | Ticket, Change, Problem, Computer, User, Software |
| Results per itemtype | 5 |
| Minimum characters | 2 |
| Bind Ctrl/Cmd+K | on |
| Bind `/` | off |

The default type list is short because records go through `Search::getDatas()`
with a `view contains` criterion — the same path as the header search box, which
inherits entity restrictions and profile rights rather than hand-rolling `LIKE`
queries that could return rows the user may not see.

The cost is one query per itemtype across every displayed column. A sweep of all
30 configured types measured ~140ms on a near-empty dev database, and a palette
runs a query per keystroke. Hence six types, a two-character floor before
records are searched, a 180ms debounce and abort-on-retype.

## How it works

```
Ctrl+K
  │
  ├─ bootstrap (once per page, prefetched when idle)
  │     commands + menu entries  ──► matched in the browser, instant
  │
  └─ search (per keystroke, debounced 180ms, abortable)
        Finder ──► Search::getDatas()  ──► grouped records
```

Commands and menu destinations are a bounded per-session list, fetched once and
ranked client-side, so the palette responds to the first keystroke. Only records
need the server.

Ranking is tiered: exact title → prefix → word-start → substring → subsequence.
Without the tiers a subsequence hit in a long menu path can outrank an exact
match.

## Security notes

- **Scopes are bounded by `globalsearch_types`.** The scope name arrives from
  the browser and is used as a class name; unbounded, that turns a search box
  into a way to probe tables the interface never exposes. `Config` is a real
  class and is correctly rejected.
- **`#id` jumps resolve server-side** through `canViewItem()`, so the palette
  cannot confirm which ids exist to someone with no rights to them.
- **Titles render with `textContent`.** GLPI's search rows carry a `displayname`
  field of pre-rendered HTML including an inline `<script>` qtip initialiser;
  the plugin reads the raw name column and never hands markup to the DOM.
- **CSRF rides the `X-Glpi-Csrf-Token` header**, which GLPI 11's kernel
  validates and preserves. In the POST body it would consume a token per
  keystroke and drain the session pool.
- **Nothing is recorded server-side.** No tables. Recents live in
  `localStorage`.

## Tests

```bash
cd tests/browser && node palette-check.js
```

Covers opening, record search, `>` command mode, type scoping (asserting the
scoped result set is narrower than the unscoped one), `#id` jumps, arrow
navigation, Enter-to-open, recents and Escape.

## Limitations

- **Navigation only.** Every command opens something; none submit, assign,
  close or delete. Mutating actions want a confirmation step designed for them
  first.
- No server-side or cross-device recents, by design.
- No fuzzy matching for records without `glpisearch` — those use GLPI's
  `contains` semantics, so `lptop` will not find "Laptop". Commands and menu
  entries are fuzzy either way.

## Licence

GPL-3.0-or-later, the same licence as GLPI. The plugin is loaded into GLPI's
process and extends its classes, so it is a derivative work. See
[LICENSE](LICENSE).

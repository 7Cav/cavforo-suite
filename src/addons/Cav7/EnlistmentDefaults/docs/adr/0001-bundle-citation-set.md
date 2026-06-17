# Bundle the PUC set in the addon, not clone from a template milpac

Every new milpac must receive the standing **PUC set** — the dated
Presidential Unit Citation grants (award 61) and their citation images. The
images are generalized (the same JPG per date for every member), so the data is
a small, near-static set.

We ship the dates and the citation JPGs inside the addon and apply them on
milpac creation, rather than cloning them from a designated "template" milpac at
runtime.

## Considered options

- **Clone from a template/source milpac.** Zero new assets and self-maintaining
  (add the next PUC to one profile through the Rosters UI, future milpacs
  inherit it). Rejected: a template profile is mutable, so an accidental edit
  drifts silently into every milpac created afterward — and nobody notices until
  dozens have the wrong set.
- **Addon-managed admin screen for the citation set.** A custom table plus an
  upload UI. Rejected as overkill for ~6 near-static rows.
- **Bundle in the addon (chosen).** Deterministic and self-contained; matches
  existing practice (`Cav7/AvatarByRole` already ships image assets in the repo).

## Consequences

Earning a new PUC is not zero-touch: it means adding the date and its citation
image to the addon and cutting a release. Accepted because PUCs are earned
roughly once every few years (six dates across 2003–2021), so the release
friction is rare and the drift-proofing is worth it.

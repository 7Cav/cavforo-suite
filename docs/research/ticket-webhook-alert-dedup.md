# Cav7/TicketWebhook: what alert callers send, and what NF/Tickets lets the opener do

Research only, for issue #321, which adds de-duplication and alert state to `Cav7/TicketWebhook`. No addon code was written and nothing here is a design decision. It covers the three callers in view, WUD, Grafana and healthchecks.io, and the NF/Tickets services a later post would need to reach a ticket that already exists.

Everything was read on 2026-10-17. The versions are WUD 9.4.1, Grafana 13.2.4 with the grafana/alerting and grafana/prometheus-alertmanager commits its `go.mod` pins, healthchecks v4.4, and NF/Tickets 2.12.2 on the local dev stack. Each section names its exact tag or commit, and every upstream link points at that tag or commit, so it keeps showing the code that was read. NF/Tickets and XenForo core are third-party code that this repo does not vendor. Their paths are relative to a XenForo `app/src/` tree, and their line numbers drift with vendor upgrades. `Cav7/TicketWebhook` declares NF/Tickets 2.11.1 as its floor, and the NF/Tickets section flags findings that may differ there. The text marks **unverified** any claim it could not trace to a primary source. The example payloads come from reading the code, not from a running tool, unless the text says otherwise.

Hook, caller, token and inactive are the addon's terms, defined in [its glossary](../../src/addons/Cav7/TicketWebhook/GLOSSARY.md). Opener is the suite's, defined in the [suite glossary](../../GLOSSARY.md).

## Across the callers

| | WUD | Grafana | healthchecks.io |
| --- | --- | --- | --- |
| Way in that a hook parses today | The Discord trigger | The Discord contact point | Only the generic Webhook integration, with a Discord-shaped body the operator writes |
| Token as a Bearer header | No, path only | No, path only | Yes, as a custom header |
| One post covers | One container in simple mode, one watcher's run in batch mode | One alert group, by default every instance of one rule in one folder | One transition of one check |
| Stable key in the default post | None | None, and the title changes with state | No default. The operator writes the body |
| Key an operator can template in | `${container.watcher}_${container.name}`. `container.id` changes on every recreate | `.GroupKey`, and each alert's `.Fingerprint` and `.RuleUID` | `$CODE`, the check UUID, which is also its ping secret |
| Resolved signal | None by default | A `[RESOLVED]` post unless disabled, and partial resolutions inside firing posts | A separate up configuration, with `$STATUS` set to `up` |
| Repeats while still open | None with `ONCE=true`. It posts again when the candidate version changes or the container gets a new id | Every repeat interval, 4 hours by default | Never |
| A failed post | Dropped and never sent again | Sent again every group interval, 5 minutes by default, until delivered | Three attempts back to back, then dropped |

The sections below cover what the table leaves out.

- None of the three puts a stable key in its default post. Each can carry one if the operator writes it into a template, and only healthchecks.io lets the operator write the whole body.
- The delivery guarantees differ. Grafana can post the same notification twice in a high-availability setup or after a crash. WUD and healthchecks.io never send a failed post again, so a lost post is gone for good, a healthchecks.io up included. A healthchecks.io instance running more than one alert worker can deliver a check's down and up out of order.
- Some posts that a caller can send would fail at the hook as it stands. healthchecks.io's Discord integration and its Slack-format integrations send no `content` and no `embeds`, so the hook answers 400. Grafana's Discord contact point switches to `multipart/form-data` when alert screenshots are on, and the controller decodes only a JSON body. Grafana then sends the refused post again every group interval.
- healthchecks.io treats a 404 from its Slack and Discord integrations as permanent and disables the integration. The hook answers 404 for an inactive hook and for a wrong token.
- In NF/Tickets, a closed ticket still takes a reply. With the shipped statuses, a reply from the opener moves a Resolved ticket back to Open. A reply notifies fewer people than a new ticket does. Category watchers set to new tickets only, and the category and priority notify addresses, hear nothing.
- Two statements in the addon's docs are wrong today, and a third stops being true once a post can become a reply. The README links WUD's docs at `getwud.dev`, which did not resolve on 2026-10-17. The docs are at `getwud.app`. The README and ADR-0001 say any tool with a Discord target is a caller with nothing to adapt, and healthchecks.io's Discord integration is not one. The README says the category's staff hear about a hook ticket as they do for any other ticket, which a reply does not give them.

## WUD

Read: `getwud/wud` release tag `9.4.1`, commit `2de9927daa952d21e551aef40b30b3c1e096ceb1`, published 2026-10-10 and still the latest release. Docs read from `website/versioned_docs/version-9.x` at the same tag and spot-checked against the live site. Date: 2026-10-17.

`getwud/wud` is the canonical repo. `https://github.com/fmartinou/whats-up-docker` answers `301` with `Location: https://github.com/getwud/wud`, and the GitHub API returns one repository, id 197008093, created 2019-07-15, for both names. That makes it a rename or transfer of a single repo, not a fork. I checked both with `curl -I` and `gh api` on 2026-10-17. The official docs live at `https://getwud.app`. The repo's homepage field names it, and so does [website/docusaurus.config.js#L35](https://github.com/getwud/wud/blob/9.4.1/website/docusaurus.config.js#L35). `getwud.dev` did not resolve in DNS on 2026-10-17. The live pages under `getwud.app/docs/configuration/triggers/` carry the same option lists as the 9.x docs in the repo.

`main` is 28 commits ahead of 9.4.1, at commit `da04eb5` on 2026-10-17. Where those unreleased commits change an answer below, the text says so.

Citations use `T` for [app/triggers/providers/Trigger.ts](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts) and `D` for [app/triggers/providers/discord/Discord.ts](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/discord/Discord.ts). Every other file is linked in full.

### 1. The body the Discord trigger posts

Every Discord post goes through one method, `sendMessage(title, bodyText)`, which sends one axios `POST` with this JSON body. [D#L93-L119](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/discord/Discord.ts#L93-L119)

```text
{
  "username":   <BOTUSERNAME>,
  "avatar_url": <AVATARURL>,
  "embeds": [
    {
      "title": <rendered title>,
      "color": <CARDCOLOR>,
      "fields": [ { "name": <CARDLABEL>, "value": <rendered body> } ]
    }
  ]
}
```

There is always exactly one embed holding exactly one field. The body has no top-level `content`, no embed `description` and no embed `url`. The rendered body text always lands in `embeds[0].fields[0].value`. WUD does not truncate the title or the body. [D#L95-L110](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/discord/Discord.ts#L95-L110)

The Discord options and their defaults come from the schema at [D#L14-L31](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/discord/Discord.ts#L14-L31) and the docs at [discord/README.md#L22-L61](https://github.com/getwud/wud/blob/9.4.1/website/versioned_docs/version-9.x/configuration/triggers/discord/README.md#L22-L61):

- `URL` is required and must be an `https` URI.
- `BOTUSERNAME` defaults to `WUD` and is sent as `username`.
- `AVATARURL` defaults to the empty string and is sent as `avatar_url` even when empty.
- `CARDCOLOR` is a number, defaults to `65280`, and is sent as `color`.
- `CARDLABEL` defaults to the empty string and is sent as `fields[0].name`. With the default, the field name is `""`.

joi applies these defaults when the trigger registers, so a default post always carries all four keys. [registry/Component.ts#L47](https://github.com/getwud/wud/blob/9.4.1/app/registry/Component.ts#L47) Env vars map as `WUD_TRIGGER_DISCORD_<NAME>_<KEY>`, lowercased into the config key. [configuration/index.ts#L14-L33](https://github.com/getwud/wud/blob/9.4.1/app/configuration/index.ts#L14-L33)

The title and body come from three paths:

- In simple mode, the title is SIMPLETITLE and the body is SIMPLEBODY, both rendered against one container. [D#L49-L54](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/discord/Discord.ts#L49-L54), [T#L737-L748](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L737-L748)
- In batch mode, the title is BATCHTITLE rendered against the container list. The body is SIMPLEBODY rendered once per container, each line written as `- <body>\n`, joined with `\n`. [D#L61-L66](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/discord/Discord.ts#L61-L66), [T#L755-L768](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L755-L768) The 9.4.1 schema has no BATCHBODY option, [T#L584-L650](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L584-L650) though the docs list `BATCHBODY` among the templates. [triggers/README.md#L193](https://github.com/getwud/wud/blob/9.4.1/website/versioned_docs/version-9.x/configuration/triggers/README.md#L193)
- For a rollback, the title is ROLLBACKTITLE and the body is ROLLBACKBODY, rendered against a rollback report. [D#L80-L85](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/discord/Discord.ts#L80-L85), [T#L805-L854](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L805-L854)

Trigger.ts sets the template defaults at [T#L609-L647](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L609-L647), and the rollback ones at [T#L82-L86](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L82-L86):

- MODE defaults to `simple`. Valid values are `simple` and `batch`.
- SIMPLETITLE defaults to `New ${container.updateKind.kind} found for container ${container.name}`.
- SIMPLEBODY defaults to `Container ${container.name} running with ${container.updateKind.kind} ${container.updateKind.localValue} can be updated to ${container.updateKind.kind} ${container.updateKind.remoteValue}${container.result && container.result.link ? "\n" + container.result.link : ""}`.
- BATCHTITLE defaults to `${containers.length} updates available`.
- ROLLBACKTITLE renders `Rollback of <name> (<reason>)` on success and `Rollback FAILED for <name>` on failure.
- ROLLBACKBODY renders `Container <name> was rolled back from <newImageRef> to <oldImageRef> (reason: <reason>).` on success. On failure it renders `Rollback of <name> failed at step <step>: <message>`, with a second line naming the archived container when one exists.

The examples below are built from that code. `nginx` runs tag `1.25.0` and the registry has `1.26.0`. `homepage` runs a non-semver tag with digest watching on. Real posts carry full `sha256:` digests of 64 hex characters, which I abbreviated here.

Example 1. Simple mode, all defaults, a tag update, no link template.

```json
{
  "username": "WUD",
  "avatar_url": "",
  "embeds": [
    {
      "title": "New tag found for container nginx",
      "color": 65280,
      "fields": [
        {
          "name": "",
          "value": "Container nginx running with tag 1.25.0 can be updated to tag 1.26.0"
        }
      ]
    }
  ]
}
```

With a link template set on the container, the default body gains `"\n" + container.result.link` at the end. [T#L620-L624](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L620-L624)

Example 2. Simple mode, all defaults, a digest update.

```json
{
  "username": "WUD",
  "avatar_url": "",
  "embeds": [
    {
      "title": "New digest found for container homepage",
      "color": 65280,
      "fields": [
        {
          "name": "",
          "value": "Container homepage running with digest sha256:1a2b... can be updated to digest sha256:9f8e..."
        }
      ]
    }
  ]
}
```

Example 3. Batch mode, all defaults, both containers in one watch run.

```json
{
  "username": "WUD",
  "avatar_url": "",
  "embeds": [
    {
      "title": "2 updates available",
      "color": 65280,
      "fields": [
        {
          "name": "",
          "value": "- Container nginx running with tag 1.25.0 can be updated to tag 1.26.0\n\n- Container homepage running with digest sha256:1a2b... can be updated to digest sha256:9f8e...\n"
        }
      ]
    }
  ]
}
```

Example 4. A rollback with default templates, container scope, status `succeeded`, reason `unhealthy`. `newImageRef` is the fully qualified image the replacement was created from. `oldImageRef` is the `Image` field of the old container's Docker inspect result. [docker/Docker.ts#L1144-L1159](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/docker/Docker.ts#L1144-L1159), [docker/Docker.ts#L889-L904](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/docker/Docker.ts#L889-L904)

```json
{
  "username": "WUD",
  "avatar_url": "",
  "embeds": [
    {
      "title": "Rollback of nginx (unhealthy)",
      "color": 65280,
      "fields": [
        {
          "name": "",
          "value": "Container nginx was rolled back from <newImageRef> to <oldImageRef> (reason: unhealthy)."
        }
      ]
    }
  ]
}
```

### 2. Template variables, and what could identify the same update

WUD renders a template by wrapping it in backticks and passing it to a direct `eval` inside `renderTemplate`, so any JavaScript expression works inside `${...}`. [T#L695-L730](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L695-L730) The documented variable is the full `container` object. [triggers/README.md#L191-L221](https://github.com/getwud/wud/blob/9.4.1/website/versioned_docs/version-9.x/configuration/triggers/README.md#L191-L221) The code also defines deprecated shorthands. They are `id`, `name`, `watcher`, `kind` for `updateKind.kind`, `semver` for `updateKind.semverDiff`, `local` for `updateKind.localValue`, `remote` for `updateKind.remoteValue`, and `link` for `result.link`. [T#L696-L727](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L696-L727)

BATCHTITLE sees `containers`, the filtered array, and `count`. [T#L64-L70](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L64-L70) Rollback templates see `name`, `scope`, `status`, `reason`, `oldImageRef`, `newImageRef`, `archiveName`, `error_step`, `error_message`, `services`, `servicesText` and `container`. [T#L774-L828](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L774-L828) Unreleased on `main`, simple and batch templates gain `hostname`, batch templates gain `watcher`, and `link` falls back to `container.link`.

The container fields come from [model/container.ts#L7-L78](https://github.com/getwud/wud/blob/9.4.1/app/model/container.ts#L7-L78), and the Docker watcher fills them at [watchers/providers/docker/Docker.ts#L1610-L1652](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L1610-L1652). Their stability across posts:

- `container.id` is Docker's full container ID from the list API. [Docker.ts#L1363](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L1363) It changes whenever the container is recreated, which is how an update gets applied. WUD's store keys its row on this id, and rows for ids no longer listed get pruned. [Docker.ts#L368-L399](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L368-L399), [Docker.ts#L1052-L1066](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L1052-L1066), [store/db/schema.ts#L24-L25](https://github.com/getwud/wud/blob/9.4.1/app/store/db/schema.ts#L24-L25)
- `container.name` is the Docker container name. [Docker.ts#L1612](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L1612) It survives recreation whenever the same name is reused, as Compose does.
- `container.watcher` is the name the WUD watcher was configured with. [Docker.ts#L1614](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L1614)
- WUD's own code calls `${watcher}_${name}` "the business id of the container" and uses it as the log key. [model/container.ts#L573-L580](https://github.com/getwud/wud/blob/9.4.1/app/model/container.ts#L573-L580) A template can build the same string from `container.watcher` and `container.name`.
- `container.image.registry.name` and `container.image.name` identify the image. WUD normalizes Docker Hub official images to a `library/` prefix. [registries/providers/hub/Hub.ts#L68-L76](https://github.com/getwud/wud/blob/9.4.1/app/registries/providers/hub/Hub.ts#L68-L76)
- `container.image.tag.value` is the running tag. `container.image.digest.value` is the running digest when digest watching is on. [Docker.ts#L1228-L1286](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L1228-L1286)
- `container.result.tag`, `.digest`, `.created` and `.version` describe the candidate. [model/container.ts#L29-L39](https://github.com/getwud/wud/blob/9.4.1/app/model/container.ts#L29-L39)
- `container.updateKind.kind` is `tag`, `digest` or `unknown`. For a tag update, `localValue` and `remoteValue` hold the current and new tags. For a digest update they hold the current and new digests. `semverDiff` is `major`, `minor`, `patch`, `prerelease` or `unknown`. [model/container.ts#L421-L504](https://github.com/getwud/wud/blob/9.4.1/app/model/container.ts#L421-L504)
- `container.stack` comes from the `wud.compose.project` or `com.docker.compose.project` label. [Docker.ts#L1367-L1371](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L1367-L1371) `container.labels` holds every label.

An operator can put such a key into either string. For example, `WUD_TRIGGER_DISCORD_TICKETS_SIMPLETITLE=[${container.watcher}/${container.name}] ${container.updateKind.localValue} to ${container.updateKind.remoteValue}` renders into `embeds[0].title`, and SIMPLEBODY renders into `embeds[0].fields[0].value`. In a Compose file each `$` must be written `$$`. [triggers/README.md#L284-L304](https://github.com/getwud/wud/blob/9.4.1/website/versioned_docs/version-9.x/configuration/triggers/README.md#L284-L304) A batch post has one title for the whole list. Per-container text appears only in the body, one SIMPLEBODY line per container. [T#L755-L768](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L755-L768)

### 3. When WUD sends

Trigger.ts validates the trigger options and their defaults at [T#L584-L650](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L584-L650) and the docs describe them at [triggers/README.md#L36-L187](https://github.com/getwud/wud/blob/9.4.1/website/versioned_docs/version-9.x/configuration/triggers/README.md#L36-L187).

- `AUTO` defaults to `true`. When it is `false`, the trigger registers no update handlers and runs only when someone clicks it in the UI or calls the API. [T#L494-L529](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L494-L529)
- `EVENTS` defaults to `available`. The simple and batch update handlers register only when it includes `available`. [T#L499-L517](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L499-L517) Container labels such as `wud.trigger.events` can override it. [T#L303-L329](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L303-L329)
- `MODE=simple` subscribes to one report per container. `MODE=batch` subscribes to the report list of a watch run. A trigger runs one mode, not both. [T#L501-L516](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L501-L516)
- `ONCE` defaults to `true`. A post goes out only when the report is `changed`, or when ONCE is false. The container must also have no watch error and an update available. [T#L210-L216](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L210-L216), [T#L271-L280](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L271-L280) The docs describe ONCE as "Execute trigger only once per detected update". [triggers/README.md#L101-L107](https://github.com/getwud/wud/blob/9.4.1/website/versioned_docs/version-9.x/configuration/triggers/README.md#L101-L107)
- `THRESHOLD` defaults to `all`. The other values are `major`, `minor`, `patch`, `major-only` and `minor-only`. It filters only tag updates whose semver diff is known. Digest updates and unknown diffs pass any threshold. [T#L94-L126](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L94-L126) Unreleased on `main`, `patch-only` is added.
- `ONDIGEST` is unset by default, which counts as `true`. Setting it to `false` drops digest updates. [T#L402-L428](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L402-L428)
- `INCLUDEBYDEFAULT` and the `wud.trigger.*` labels decide which containers a trigger covers. [T#L438-L489](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L438-L489)

The watcher decides `changed` in `mapContainerToContainerReport`. [Docker.ts#L1659-L1698](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L1659-L1698)

- If no store row exists for the container id, `changed` is `true`.
- If a row exists, `changed` is true only when the new result's `tag`, `digest` or `created` differs from the stored result and an update is available. [model/container.ts#L511-L521](https://github.com/getwud/wud/blob/9.4.1/app/model/container.ts#L511-L521)
- In one-shot mode, set by `WUD_RUN_MODE=oneshot`, `changed` equals `updateAvailable`, so every run posts every pending update.

A watch runs at these moments:

- On `CRON`, which defaults to `0 * * * *`, delayed at random by up to `JITTER`, 60000 ms by default. [Docker.ts#L575-L582](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L575-L582), [Docker.ts#L608-L613](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L608-L613)
- At start, but only when the store is empty. [Docker.ts#L615-L626](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L615-L626)
- 10 seconds after a Docker `create` or `destroy` event, when `WATCHEVENTS` is on, as it is by default. [Docker.ts#L628-L639](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L628-L639), [Docker.ts#L763-L767](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L763-L767)
- When the API's per-container watch is called, [api/container.ts#L212-L250](https://github.com/getwud/wud/blob/9.4.1/app/api/container.ts#L212-L250) or a registry webhook arrives. The registry webhook was added in 9.4.0. [api/registry.ts#L55-L160](https://github.com/getwud/wud/blob/9.4.1/app/api/registry.ts#L55-L160)

On restart, the store is SQLite at `/store/wud.sqlite` by default. [store/index.ts#L24-L32](https://github.com/getwud/wud/blob/9.4.1/app/store/index.ts#L24-L32) It holds the container rows and a history of results. [store/db/schema.ts#L24-L97](https://github.com/getwud/wud/blob/9.4.1/app/store/db/schema.ts#L24-L97) No table records which trigger sent what. What survives a restart is the last result. If `/store` sits on a volume, an unchanged result gives `changed=false` after the restart, so ONCE=true sends nothing new. The docs tell operators to mount `/store` to persist state. [storage/README.md#L7-L9](https://github.com/getwud/wud/blob/9.4.1/website/versioned_docs/version-9.x/configuration/storage/README.md#L7-L9) Without that volume the store starts empty. Every container then counts as watched for the first time, and every pending update is posted again.

A newer version superseding a notified one changes the result's `tag`, `digest` or `created`. That gives `changed=true` and a new post naming the new `remoteValue`. Nothing in the post refers back to the earlier one. On a floating tag with digest watching, each new remote digest makes a new post. If a container is recreated without the update, for example a forced Compose recreate on the old image, it gets a new id. With no store row for that id, `changed=true`, and the pending update is posted again even with ONCE=true.

A failed post is not retried. `checkContainer` writes the new result to the store before it emits the report that triggers act on. [Docker.ts#L969-L973](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L969-L973) The trigger catches the error and logs it. [T#L241-L246](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L241-L246) With ONCE=true, the next cycle compares equal and sends nothing. The next post for that container comes only when the result changes again or the container gets a new id. This follows from reading the code. I did not run it.

With ONCE=false, every watch run re-posts every container that still has an update available. By default that is hourly, plus every watch set off by an event, an API call or a registry webhook.

Per-container and batch posts fire at different moments:

- In simple mode, one post goes out per container as each container's check finishes. [Docker.ts#L969-L973](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L969-L973)
- In batch mode, one post goes out per watcher per watch run. It lists the containers that passed the filters in that run, and with ONCE=true those are only the changed ones. [Docker.ts#L920-L923](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L920-L923), [T#L268-L301](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L268-L301)
- A registry webhook also emits a list for the containers it checked. [api/registry.ts#L157](https://github.com/getwud/wud/blob/9.4.1/app/api/registry.ts#L157)
- The API's per-container watch emits only the single report, so batch triggers do not fire for it. [Watcher.ts#L61-L74](https://github.com/getwud/wud/blob/9.4.1/app/watchers/Watcher.ts#L61-L74)

A manual run from the UI or API calls `trigger(container)` directly. It renders SIMPLETITLE and SIMPLEBODY whatever MODE, ONCE, THRESHOLD or update availability say. [api/container.ts#L170-L204](https://github.com/getwud/wud/blob/9.4.1/app/api/container.ts#L170-L204)

A cooldown can suppress a post for good. If a watcher or container `DELAY` is active when a result first appears, `updateAvailable` is false in that cycle, so `changed` is false while the store still takes the new result. [model/container.ts#L345-L389](https://github.com/getwud/wud/blob/9.4.1/app/model/container.ts#L345-L389) After the delay passes, the result compares equal, so with ONCE=true no post follows. This is **unverified**. It comes from reading the code. I did not run it, and no test or doc covers it.

### 4. Is there a resolved signal, and which events reach a trigger

[event/index.ts#L8-L25](https://github.com/getwud/wud/blob/9.4.1/app/event/index.ts#L8-L25) defines the event bus names. Here is what reaches a trigger in 9.4.1:

| Event | Emitted by | Does Discord post? |
| :--- | :--- | :--- |
| `available`, as `wud:container-report` and `wud:container-reports` | watchers | Yes, when AUTO is on and EVENTS includes `available`. |
| `pre`, `wud:container-update-pre` | the docker and dockercompose triggers, before applying an update | No. |
| `success`, `wud:container-update-success` | the same triggers, after an update | No. |
| `failure`, `wud:container-update-failure` | the same triggers, on a failed update | No. |
| `rollback`, `wud:container-rollback` | the docker and dockercompose triggers, when the health gate rolls back | Yes, whatever AUTO and EVENTS say. |
| hook of type `trigger`, phase `pre`, `post`, `failure` or `rollback` | the docker and dockercompose triggers | Yes, as a simple-mode post. |
| manual run | UI or API | Yes, as a simple-mode post. |

For `pre`, `success` and `failure`, the base handlers check `mustTrigger` and EVENTS and then write only a debug log. [T#L331-L352](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L331-L352) No provider overrides them. A search of `app/` finds those method names only in Trigger.ts. SUCCESSTITLE, SUCCESSBODY, FAILURETITLE and FAILUREBODY get defaults, [T#L628-L641](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L628-L641) and nothing else in `app/` reads them. The docs describe them as templates "used to render the notification title when an update is successful" and list `pre`, `success`, `failure` and `rollback` as EVENTS values. [triggers/README.md#L58-L83](https://github.com/getwud/wud/blob/9.4.1/website/versioned_docs/version-9.x/configuration/triggers/README.md#L58-L83), [triggers/README.md#L153-L169](https://github.com/getwud/wud/blob/9.4.1/website/versioned_docs/version-9.x/configuration/triggers/README.md#L153-L169) The 9.4.0 changelog entry is "Add lifecycle events and failure/rollback hooks for container updates". [changelog/v9.md#L20](https://github.com/getwud/wud/blob/9.4.1/website/docs/changelog/v9.md#L20) So in 9.4.1 the Discord trigger posts nothing when an update succeeds or fails, even with `EVENTS=available,success,failure`.

Discord declares rollback support. [D#L71-L73](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/discord/Discord.ts#L71-L73) `init` registers the rollback handler outside the AUTO branch and without checking EVENTS. [T#L531-L539](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L531-L539) The handler checks only `shouldNotifyRollback`, which returns `true`, and skips the container's include and exclude rules. [T#L554-L571](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L554-L571) The docker trigger emits a rollback when its health gate rolls back, [docker/Docker.ts#L1144-L1160](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/docker/Docker.ts#L1144-L1160) and so does the compose trigger. [dockercompose/rollback.ts#L374](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/dockercompose/rollback.ts#L374) `ROLLBACK` defaults to `false` on the docker trigger. [docker/Docker.ts#L229](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/docker/Docker.ts#L229) A rollback report's `status` is `succeeded` or `failed`.

Hooks give a path to an "update applied" post. When WUD's docker or dockercompose trigger applies an update, it can call another trigger by name at phase `pre`, `post`, `failure` or `rollback`. An operator sets the hook with `wud.hook.<n>.type=trigger` labels or in the trigger's `hooks` config. [triggers/hooks/HookManager.ts#L25](https://github.com/getwud/wud/blob/9.4.1/app/triggers/hooks/HookManager.ts#L25), [docker/README.md#L158-L239](https://github.com/getwud/wud/blob/9.4.1/website/versioned_docs/version-9.x/configuration/triggers/docker/README.md#L158-L239) The hook calls `targetTrigger.trigger(container)`, which for Discord is the simple path rendering SIMPLETITLE and SIMPLEBODY. Before the call it sets `container.result.hook = { phase, parentTrigger }`. [HookManager.ts#L302-L363](https://github.com/getwud/wud/blob/9.4.1/app/triggers/hooks/HookManager.ts#L302-L363) The container passed is the object the update started with, [docker/Docker.ts#L1450-L1460](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/docker/Docker.ts#L1450-L1460), [docker/Docker.ts#L1515-L1525](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/docker/Docker.ts#L1515-L1525) so `updateKind` still describes the update. With the default SIMPLETITLE, a post-phase post reads like an update-available post. A template has to branch on `container.result.hook` to tell them apart.

Nothing is posted when a container becomes up to date by other means:

- A container recreated on the new image gets a new id with `updateAvailable=false`. Triggers require `updateAvailable`, so no post goes out. [T#L212-L216](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L212-L216)
- Pruning the old id deletes the store row and emits nothing to triggers. [Docker.ts#L388-L399](https://github.com/getwud/wud/blob/9.4.1/app/watchers/providers/docker/Docker.ts#L388-L399)
- Triggers filter out reports that carry a watch error, and no trigger path reports errors. [T#L214](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L214), [T#L278](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L278)
- The remaining bus events go only to the API's server-sent event stream, not to triggers. They are container added, updated and removed, watch start, progress and stop, and watcher start and stop. [api/event.ts#L40-L45](https://github.com/getwud/wud/blob/9.4.1/app/api/event.ts#L40-L45)

### 5. URL constraints, headers, response handling

The URL schema is `joi.string().uri({ scheme: ['https'] }).required()`, which checks the scheme and not the host. [D#L16-L21](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/discord/Discord.ts#L16-L21) The prior spec's claim holds. I ran that schema under joi 17.13.8, the version in `app/package-lock.json`. It accepted `https://board/ticket-webhooks/3/sometoken/`, a dotted hostname, `https://10.0.0.5:8443/...`, a URL with userinfo, and a URL with a query string. It rejected `http://`. The docs describe the field as a "Valid HTTPS Discord webhook URL", [discord/README.md#L23-L29](https://github.com/getwud/wud/blob/9.4.1/website/versioned_docs/version-9.x/configuration/triggers/discord/README.md#L23-L29) but the code does not check for Discord.

`sendMessage` passes axios only `method`, `url` and `data`, with no headers and no auth. [D#L112-L117](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/discord/Discord.ts#L112-L117) WUD pins axios 1.20.0 in its lockfile. I made the same call shape with axios 1.20.0 against a local server and saw these headers:

- `Accept: application/json, text/plain, */*`
- `Content-Type: application/json`
- `User-Agent: axios/1.20.0`
- `Accept-Encoding: gzip, compress, deflate, br`
- `Content-Length`, `Host` and `Connection: keep-alive`

No `Authorization` header is sent, and no option exists to add one. The Discord trigger can only carry a credential in the URL.

Response handling follows axios defaults:

- axios resolves for status 200 to 299 and rejects everything else. [axios lib/defaults/index.js#L162-L164](https://github.com/axios/axios/blob/v1.20.0/lib/defaults/index.js#L162-L164), [axios lib/core/settle.js#L14-L27](https://github.com/axios/axios/blob/v1.20.0/lib/core/settle.js#L14-L27) So `200` and `204` both count as success. My probe confirmed both resolve.
- WUD never inspects the response body. `sendMessage` returns `response.data` and the callers drop it. [D#L117-L118](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/discord/Discord.ts#L117-L118), [T#L238](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L238)
- On a 4xx or 5xx, my probe rejected 400, 401, 404, 429, 500 and 503 with `Request failed with status code N`. In simple mode the handler logs a warning, `Error (<message>)`, and counts the run as `error` in the Prometheus trigger counter. [T#L241-L246](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L241-L246) Batch mode logs the warning and does not touch the counter. [T#L297-L300](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L297-L300) Rollback posts log the warning. [T#L559-L562](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/Trigger.ts#L559-L562)
- Neither the Discord trigger nor the base Trigger retries. `Retry-After` on a 429 is not read, and no timeout is set, axios defaulting to `0`, meaning none. [axios lib/defaults/index.js#L145-L149](https://github.com/axios/axios/blob/v1.20.0/lib/defaults/index.js#L145-L149) Question 3 covers how ONCE makes a failed post final.
- Redirects in my probe: on `301`, `302` and `303`, axios followed with a `GET` and dropped the body. On `307` and `308` it re-sent the `POST` with the body.

### 6. Other triggers that post to an operator-chosen URL

No 9.4.1 trigger accepts an operator-written JSON body. Templated text exists only as SIMPLETITLE, SIMPLEBODY, BATCHTITLE and the ROLLBACK pair. Each provider places those strings into its own fixed request shape. A search of the provider schemas finds no other body, template or header option.

The HTTP trigger's options are `URL` over http or https, `METHOD` with default `POST`, `AUTH_TYPE` set to `BASIC` or `BEARER`, `AUTH_USER`, `AUTH_PASSWORD`, `AUTH_BEARER` and `PROXY`. [http/Http.ts#L13-L38](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/http/Http.ts#L13-L38) A simple post sends the container object as JSON. A batch post sends an array of container objects, and a rollback sends the rollback report. With `GET`, the same object goes into query parameters. [Http.ts#L54-L110](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/http/Http.ts#L54-L110) It uses no template. The prior spec's claim holds.

`AUTH_TYPE=BEARER` sends `Authorization: Bearer <token>`, [Http.ts#L95-L99](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/http/Http.ts#L95-L99) which makes it the WUD trigger that can carry a hook token in a header to an arbitrary URL. Its body has no `embeds` and no `content`.

The HTTP docs header claims "configurable methods, headers, and body payloads", [http/README.md#L13-L16](https://github.com/getwud/wud/blob/9.4.1/website/versioned_docs/version-9.x/configuration/triggers/http/README.md#L13-L16) but the schema offers no headers or body option. The documented payload example shows `id`, `name`, `watcher`, `image`, `result` and `updateAvailable`. [http/README.md#L118-L151](https://github.com/getwud/wud/blob/9.4.1/website/versioned_docs/version-9.x/configuration/triggers/http/README.md#L118-L151) The model defines `updateKind`, `updateAvailable`, `isCoolingDown`, `isSnoozed` and `link` as enumerable getters, [model/container.ts#L271-L414](https://github.com/getwud/wud/blob/9.4.1/app/model/container.ts#L271-L414) so JSON serialization should include them too. That last point is from reading the code. I did not capture a real HTTP trigger post.

The other providers that take an http or https URL:

- Apprise POSTs to `<URL>/notify`, or to `<URL>/notify/<CONFIG>` when CONFIG is set. The body is `{ title, body, format: "text", type: "info" }` plus `urls` or `tag`, and a rollback sends `type: "warning"`. The batch path always posts to `<URL>/notify` with `urls`, even when CONFIG is set. [apprise/Apprise.ts#L14-L132](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/apprise/Apprise.ts#L14-L132)
- Home Assistant POSTs to any http or https `URL`. The body is `{ event, mode, title, message, container }` in simple mode, `{ event, mode, title, message, count, containers }` in batch mode, and `{ event, mode: "rollback", title, message, rollbackReport }` for a rollback. `event` defaults to `wud_container_update`. This is the one shape that carries both the templated text and the structured container object. [homeassistant/Homeassistant.ts#L13-L118](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/homeassistant/Homeassistant.ts#L13-L118)
- Mattermost POSTs `{ text, channel?, username, icon_url? }` to any http or https `URL`. [mattermost/Mattermost.ts#L14-L23](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/mattermost/Mattermost.ts#L14-L23), [Mattermost.ts#L101-L119](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/mattermost/Mattermost.ts#L101-L119)
- Command runs an operator-written shell command. In simple mode it gets `container_json` plus the flattened container fields as environment variables, and in batch mode `containers_json`. The command could be a `curl` with any body and any header. It does not handle rollbacks. [command/Command.ts#L16-L81](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/command/Command.ts#L16-L81)
- Bark, Gotify, Matrix, Ntfy, Prowl, Rocket.Chat, Signal, Uptime Kuma, WhatsApp, Zulip, GitHub Actions and GitLab CI also take an http or https URL. Each wraps the rendered text in its own service's request shape or appends its service's API path. Rocket.Chat, for one, posts to `<URL>/api/v1/chat.postMessage`. [rocketchat/Rocketchat.ts#L125-L134](https://github.com/getwud/wud/blob/9.4.1/app/triggers/providers/rocketchat/Rocketchat.ts#L125-L134) I read only their URL and request lines, not each file in full.

## Grafana

Grafana v13.2.4 is the current stable release, published 2026-10-13 at tag commit [`af4a33e3786594ad72f72d97e6744010330c2977`](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/go.mod). Its `go.mod` pins `github.com/grafana/alerting` at `7f02604ca93b1d6db805848d6a9b4d3c82540507` and replaces `github.com/prometheus/alertmanager` with `github.com/grafana/prometheus-alertmanager` at `18275ca76b0ca61040d85db17cf5f41c5b2d78e2` ([go.mod L94, L159, L727](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/go.mod#L94-L727)). Label printing and hashing come from `github.com/prometheus/common` v0.70.0 ([go.mod L162](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/go.mod#L162)). I read the docs from `docs/sources/alerting` at the same Grafana commit; they publish under grafana.com/docs/grafana/latest/alerting/. Researched 2026-10-17.

I built the example payloads below by reading the code; none was captured from a running Grafana. Hostnames, the rule UID, values and fingerprints in them are made up.

### 1. The body the Discord contact point posts

Grafana creates Discord contact points at integration version v1 ([discord/schema.go L10-20](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/schema.go#L10-L20)). The `v0mimir1` version exists for imported Mimir configurations and cannot be created ([v0mimir1/config.go L104](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v0mimir1/config.go#L104)). Everything below is v1.

The v1 settings are `url`, `title`, `message`, `avatar_url`, `use_discord_username` and `use_embed_description`, and `url` is required and stored as a secure field ([v1/config.go L15-22, L43-92](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/config.go#L15-L92)). An empty title becomes `{{ template "default.title" . }}` and an empty message becomes `{{ template "default.message" . }}` ([config.go L34-39](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/config.go#L34-L39), [default_template.go L15-16](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/default_template.go#L15-L16)). `disableResolveMessage` sits beside the settings on every integration rather than inside them ([models/integration.go L14](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/models/integration.go#L14), [notify/factory.go L106-113](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/notify/factory.go#L106-L113)). The Discord docs page names only the webhook URL ([configure-discord.md L31-46](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/configure-notifications/manage-contact-points/integrations/configure-discord.md#L31-L46)); the schema is the full option list.

`Notify` builds each field as follows ([discord.go L103-238](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L103-L238)):

- `username` is `"Grafana"`. With `use_discord_username` on, the key is omitted so Discord applies the webhook's own name ([L39](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L39), [L114-116](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L114-L116)).
- `content` is the rendered message template, cut to 2000 runes with a trailing `…` ([L123-133](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L123-L133), [util.go L115-130](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/util.go#L115-L130)). The key is always present ([L40](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L40)). With `use_embed_description` on, `content` is `""` and the same text goes to `embeds[0].description` ([L136-140, L169-171](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L136-L171)). That option entered grafana/alerting on 2026-07-02 ([commit 7dadb02d7a8a](https://github.com/grafana/alerting/commit/7dadb02d7a8a)).
- `avatar_url` is the rendered `avatar_url` setting, omitted when the setting is empty ([L142-149](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L142-L149)).
- `embeds[0].title` is the rendered title template, cut to 256 runes ([L158-167](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L158-L167)).
- `embeds[0].type` is `"rich"` ([L173](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L173)).
- `embeds[0].url` is the Grafana root URL joined with `/alerting/list`, the same on every notification ([L178-179](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L178-L179), [util.go L95-105](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/util.go#L95-L105)).
- `embeds[0].color` is 14037554 (`#D63232`) when the notification's status is firing and 3581519 (`#36a64f`) otherwise ([L175-176](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L175-L176), [util.go L28-43](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/util.go#L28-L43)).
- `embeds[0].footer` is `{"text": "Grafana v<version>", "icon_url": "https://grafana.com/static/assets/img/fav32.png"}` ([L151-154](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L151-L154)). The version is Grafana's build version ([ngalert/notifier/alertmanager.go L164](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/pkg/services/ngalert/notifier/alertmanager.go#L164)).
- The embed type has only `title`, `type`, `description`, `url`, `color`, `footer` and `image`. Grafana sends no `fields`, `author`, `timestamp` or `thumbnail` ([L46-56](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L46-L56)).
- `embeds[1]` to `embeds[9]` appear only when alerts carry images, one per distinct alert name, each with `title` set to the alert name, the same color, and `image.url` ([L181-198](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L181-L198), [L244-279](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L244-L279)).

With no image attachments the body is `application/json`. With any image attachment the body is `multipart/form-data`, the JSON goes in a form field named `payload_json`, and images that have no public URL follow as file parts ([L306-355](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L306-L355)). The switch happens whenever the attachment list is non-empty, also when every image has a URL ([L311-315](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L311-L315)). Images exist only when screenshots are on. `[unified_alerting.screenshots] capture` defaults to false ([setting_unified_alerting.go L54, L533-536](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/pkg/setting/setting_unified_alerting.go#L54-L536)) and needs the image renderer ([images-in-notifications.md L43](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/configure-notifications/template-notifications/images-in-notifications.md#L43)). The docs give Discord a maximum of 10 images per notification ([images-in-notifications.md L100](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/configure-notifications/template-notifications/images-in-notifications.md#L100)); the code caps image embeds at 9 beside the main embed, 10 embeds in all ([discord.go L32, L183](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L32-L183)).

The JSON comes from Go's `json.Marshal` ([L213](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L213)), which writes `&`, `<` and `>` inside strings as `\u0026`, `\u003c` and `\u003e`. A JSON decoder returns the original characters. That escaping is Go standard library behaviour and is **unverified** against a Grafana source.

Title, message, avatar URL and webhook URL are all rendered against one notification data object built by `templates.TmplText` ([discord.go L119](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L119), [template_data.go L376-415](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/template_data.go#L376-L415)). They may call any named template saved in Grafana. The default templates, verbatim from [default_template.go L21-45](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/default_template.go#L21-L45):

```
{{ define "__subject" }}[{{ .Status | toUpper }}{{ if eq .Status "firing" }}:{{ .Alerts.Firing | len }}{{ if gt (.Alerts.Resolved | len) 0 }}, RESOLVED:{{ .Alerts.Resolved | len }}{{ end }}{{ end }}] {{ .GroupLabels.SortedPairs.Values | join " " }} {{ if gt (len .CommonLabels) (len .GroupLabels) }}({{ with .CommonLabels.Remove .GroupLabels.Names }}{{ .Values | join " " }}{{ end }}){{ end }}{{ end }}

{{ define "__text_values_list" }}{{ if len .Values }}{{ $first := true }}{{ range $refID, $value := .Values -}}
{{ if $first }}{{ $first = false }}{{ else }}, {{ end }}{{ $refID }}={{ $value }}{{ end -}}
{{ else }}[no value]{{ end }}{{ end }}

{{ define "__text_alert_list" }}{{ range . }}
Value: {{ template "__text_values_list" . }}
Labels:
{{ range .Labels.SortedPairs }} - {{ .Name }} = {{ .Value }}
{{ end }}Annotations:
{{ range .Annotations.SortedPairs }} - {{ .Name }} = {{ .Value }}
{{ end }}{{ if gt (len .GeneratorURL) 0 }}Source: {{ .GeneratorURL }}
{{ end }}{{ if gt (len .SilenceURL) 0 }}Silence: {{ .SilenceURL }}
{{ end }}{{ if gt (len .DashboardURL) 0 }}Dashboard: {{ .DashboardURL }}
{{ end }}{{ if gt (len .PanelURL) 0 }}Panel: {{ .PanelURL }}
{{ end }}{{ end }}{{ end }}

{{ define "default.title" }}{{ template "__subject" . }}{{ end }}

{{ define "default.message" }}{{ if gt (len .Alerts.Firing) 0 }}**Firing**
{{ template "__text_alert_list" .Alerts.Firing }}{{ if gt (len .Alerts.Resolved) 0 }}

{{ end }}{{ end }}{{ if gt (len .Alerts.Resolved) 0 }}**Resolved**
{{ template "__text_alert_list" .Alerts.Resolved }}{{ end }}{{ end }}
```

`__subject` prints the status, then the group label values (`alertname` first, the rest sorted by name), then, when there are more common labels than group labels, the values of the extra common labels in parentheses ([template.go L252-298](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/template/template.go#L252-L298)). Private `__name__`-style labels are removed from the common labels first ([template_data.go L200-207, L366](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/template_data.go#L200-L366)). When the common labels add nothing, the title ends in a space.

Example, one firing alert. Rule "High CPU" with UID `ddx4k2abc` in folder "Infra", org 1, root URL `https://grafana.example.com/`, one instance with `instance=host1`, rule label `severity=critical`, annotation `summary`, default policy, default templates, no images:

```json
{
  "username": "Grafana",
  "content": "**Firing**\n\nValue: A=93.5, C=1\nLabels:\n - alertname = High CPU\n - grafana_folder = Infra\n - instance = host1\n - severity = critical\nAnnotations:\n - summary = CPU above 90%\nSource: https://grafana.example.com/alerting/grafana/ddx4k2abc/view?orgId=1\nSilence: https://grafana.example.com/alerting/silence/new?alertmanager=grafana&matcher=__alert_rule_uid__%3Dddx4k2abc&matcher=instance%3Dhost1&matcher=severity%3Dcritical&orgId=1\n",
  "embeds": [
    {
      "title": "[FIRING:1] High CPU Infra (host1 critical)",
      "type": "rich",
      "url": "https://grafana.example.com/alerting/list",
      "color": 14037554,
      "footer": {
        "text": "Grafana v13.2.4",
        "icon_url": "https://grafana.com/static/assets/img/fav32.png"
      }
    }
  ]
}
```

The same instance once it resolves:

```json
{
  "username": "Grafana",
  "content": "**Resolved**\n\nValue: A=41.2, C=0\nLabels:\n - alertname = High CPU\n - grafana_folder = Infra\n - instance = host1\n - severity = critical\nAnnotations:\n - summary = CPU above 90%\nSource: https://grafana.example.com/alerting/grafana/ddx4k2abc/view?orgId=1\nSilence: https://grafana.example.com/alerting/silence/new?alertmanager=grafana&matcher=__alert_rule_uid__%3Dddx4k2abc&matcher=instance%3Dhost1&matcher=severity%3Dcritical&orgId=1\n",
  "embeds": [
    {
      "title": "[RESOLVED] High CPU Infra (host1 critical)",
      "type": "rich",
      "url": "https://grafana.example.com/alerting/list",
      "color": 3581519,
      "footer": {
        "text": "Grafana v13.2.4",
        "icon_url": "https://grafana.com/static/assets/img/fav32.png"
      }
    }
  ]
}
```

With two instances, host1 resolved and host2 still firing, and resolve messages on, `content` is the `**Firing**` block for host2, then `\n\n\n**Resolved**\n`, then the block for host1, and the title is `[FIRING:1, RESOLVED:1] High CPU Infra (critical)`. The `Source:` line comes from the generator URL and the `Silence:` line from the silence URL, both described in section 4. `Dashboard:` and `Panel:` lines appear when the rule links a panel ([default_template.go L27-37](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/default_template.go#L27-L37)).

### 2. Grouping

One post is one flush of one alert group to one integration, and it carries every alert in the group at that moment, both the firing ones and those resolved since the last successful notification ([dispatch.go L482-523, L555-592](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/dispatch/dispatch.go#L482-L592)). A group is the alerts that matched the same notification policy and share values for its group-by labels ([group-alert-notifications.md L37-42](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/fundamentals/notifications/group-alert-notifications.md#L37-L42)). Discord puts the whole group in one message, so the 2000-rune cut on `content` applies to the group, not to one alert ([discord.go L123-133](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L123-L133)).

Defaults:

- group_by is `["grafana_folder", "alertname"]` in the configuration Grafana ships ([setting_unified_alerting.go L32-42](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/pkg/setting/setting_unified_alerting.go#L32-L42)). The docs call this grouping by alert rule ([group-alert-notifications.md L32, L44-46](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/fundamentals/notifications/group-alert-notifications.md#L32-L46)). Every instance of one rule in one folder lands in one group and one post.
- When the contact point is picked on the rule itself, which the docs call simplified routing ([access-roles/index.md L111](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/set-up/configure-rbac/access-roles/index.md#L111)), Grafana generates policies whose group_by always includes `grafana_folder` and `alertname`. Rule-level settings can add labels and override the timings ([autogen_alertmanager.go L99-172](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/pkg/services/ngalert/notifier/autogen_alertmanager.go#L99-L172), [notifications.go L58-59](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/pkg/services/ngalert/models/notifications.go#L58-L59)).
- group_wait 30s, group_interval 5m, repeat_interval 4h. The shipped configuration sets none of them, so Alertmanager's root defaults apply ([route.go L30-39](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/dispatch/route.go#L30-L39)). The docs give the same values ([group-alert-notifications.md L82, L110, L152](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/fundamentals/notifications/group-alert-notifications.md#L82-L152), [create-notification-policy.md L53-55](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/configure-notifications/create-notification-policy.md#L53-L55)).
- Grouping by the special label `...` gives each alert instance its own group ([group-alert-notifications.md L54-56](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/fundamentals/notifications/group-alert-notifications.md#L54-L56)).
- Repeat interval is coerced to a multiple of group interval and limited in practice to 5 days by `notification_log_retention` ([group-alert-notifications.md L162-164](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/fundamentals/notifications/group-alert-notifications.md#L162-L164)).

### 3. Firing and resolved

A notification's `.Status` is `firing` if at least one alert in it fires, else `resolved` ([template.go L360](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/template/template.go#L360), [model/alert.go L148-153](https://github.com/prometheus/common/blob/v0.70.0/model/alert.go#L148-L153), [reference.md L36](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/configure-notifications/template-notifications/reference.md#L36)).

At every group_interval tick the dedup stage compares the group with the last successful notification logged for this group and integration, and sends when one of these holds ([dispatch.go L482-523](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/dispatch/dispatch.go#L482-L523), [notify.go L668-764](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/notify/notify.go#L668-L764), [nflogpb/set.go L18-47](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/nflog/nflogpb/set.go#L18-L47)):

1. Nothing was sent for the group before and something fires. An alert that fires and resolves inside group_wait produces no post ([notify.go L671-673](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/notify/notify.go#L671-L673), [group-alert-notifications.md L88](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/fundamentals/notifications/group-alert-notifications.md#L88)).
2. A firing alert was absent from the last notification ([notify.go L675-677](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/notify/notify.go#L675-L677)).
3. Nothing fires any more and the last notification had firing alerts ([notify.go L679-688](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/notify/notify.go#L679-L688)).
4. Resolve messages are on and an alert resolved since the last notification while others still fire ([notify.go L690-692](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/notify/notify.go#L690-L692)).
5. Otherwise, repeat_interval has passed since the last notification ([notify.go L694-695](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/notify/notify.go#L694-L695)).

Partial resolution does send. Under rule 4, when one alert of a group resolves and others keep firing, the next tick posts a notification with status `firing` that lists both kinds, titled `[FIRING:n, RESOLVED:m] ...` by default ([default_template.go L21](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/default_template.go#L21)). After a successful send the resolved alerts leave the group ([dispatch.go L583-591](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/dispatch/dispatch.go#L583-L591), [group-alert-notifications.md L146-148](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/fundamentals/notifications/group-alert-notifications.md#L146-L148)).

When the last alert resolves, the post has status `resolved`, title `[RESOLVED] ...`, and lists the alerts resolved since the previous post. The group is then empty and is deleted. A later re-fire forms a new group under the same group key and waits group_wait again ([group-alert-notifications.md L148](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/fundamentals/notifications/group-alert-notifications.md#L148)).

"Disable resolved message" is a per-integration notification setting ([manage-contact-points/_index.md L111](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/configure-notifications/manage-contact-points/_index.md#L111)), stored as `disableResolveMessage` ([integration.go L14](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/models/integration.go#L14), [contact_points.go L36-45](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/pkg/services/ngalert/api/tooling/definitions/contact_points.go#L36-L45)). Discord's `SendResolved()` returns its negation ([discord.go L240-242](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L240-L242)). With it on, rule 4 never triggers ([notify.go L690](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/notify/notify.go#L690)). For a fully resolved group the retry stage reports success without sending any request, so the notification log records it as sent ([notify.go L809-819](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/notify/notify.go#L809-L819)), and it drops resolved alerts from any post that still has firing alerts ([notify.go L820-824](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/notify/notify.go#L820-L824)). The caller then sees firing posts only, and never learns of a resolution.

When nothing changes, Grafana posts the group again after repeat_interval, 4 hours by default ([group-alert-notifications.md L150-160](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/fundamentals/notifications/group-alert-notifications.md#L150-L160)). Grafana rebuilds the body from current data. The title repeats the last firing title, while `Value:` lines and dashboard time ranges can differ ([template_data.go L240-248, L279-302](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/template_data.go#L240-L302)).

Default titles for the example rule:

| Case | Title |
| --- | --- |
| First post, one instance | `[FIRING:1] High CPU Infra (host1 critical)` |
| Two instances firing | `[FIRING:2] High CPU Infra (critical)` |
| One firing, one newly resolved | `[FIRING:1, RESOLVED:1] High CPU Infra (critical)` |
| All resolved | `[RESOLVED] High CPU Infra (critical)` |
| Repeat with no change | the last firing title again |
| Contact point Test button | `[FIRING:1] TestAlert Grafana `, with a trailing space |

The title changes between firing and resolved, so it is not a stable key.

Two state cases move alerts between groups. An alert sent for a rule in the NoData or Error state has `alertname` set to `DatasourceNoData` or `DatasourceError` and the rule title moved to a `rulename` label ([compat.go L26-29, L106-113, L134-169](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/pkg/services/ngalert/state/compat.go#L26-L169)). Under the default group_by it forms a different group, with a different group key and fingerprints, from the rule's ordinary alerts. Its resolution is sent with the same NoData or Error labels ([compat.go L98-104](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/pkg/services/ngalert/state/compat.go#L98-L104)).

Delivery is not exactly once. In high-availability setups Grafana prefers a duplicate post to a missed one ([configure-high-availability/_index.md L272-280](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/set-up/configure-high-availability/_index.md#L272-L280)). After a crash, alerts that were firing can be posted again ([performance-limitations/index.md L84-87](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/set-up/performance-limitations/index.md#L84-L87)).

### 4. Identifiers in the template data

The data is Alertmanager's template data extended by Grafana ([notify/util.go L183-193](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/notify/util.go#L183-L193), [template.go L357-422](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/template/template.go#L357-L422), [template_data.go L115-157, L353-394](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/template_data.go#L115-L394)). Discord renders title, message, avatar URL and webhook URL against exactly this data ([discord.go L119-211](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L119-L211)), so every field below is reachable from the Discord templates.

Notification level:

- `TmplText` sets `.GroupKey` for every v1 notifier, Discord included ([template_data.go L381-385](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/template_data.go#L381-L385)), and the docs describe it as "the key used to identify this alert group" ([reference.md L44](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/configure-notifications/template-notifications/reference.md#L44)). Its format is `<route key>:<group labels>` ([dispatch.go L474-476](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/dispatch/dispatch.go#L474-L476)). The route key is each policy's matchers from the root down, joined by `/` ([route.go L172-182](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/dispatch/route.go#L172-L182), [labels/matcher.go L78-83, L193-206](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/pkg/labels/matcher.go#L78-L206)). Group labels print as `{name="value", ...}` sorted by name ([labelset_string.go L22-43](https://github.com/prometheus/common/blob/v0.70.0/model/labelset_string.go#L22-L43)).
  - Under the default policy: `{}:{alertname="High CPU", grafana_folder="Infra"}`. The docs example shows `{}:{}` for a group with no group labels ([webhook-notifier.md L194](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/configure-notifications/manage-contact-points/integrations/webhook-notifier.md#L194)).
  - Under simplified routing to a contact point named Tickets: `{}/{__grafana_autogenerated__="true"}/{__grafana_receiver__="Tickets"}:{alertname="High CPU", grafana_folder="Infra"}`. Rule-level overrides add a `/{__grafana_route_settings_hash__="..."}` level ([autogen_alertmanager.go L99-172](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/pkg/services/ngalert/notifier/autogen_alertmanager.go#L99-L172), [alert_rule.go L167-173](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/pkg/services/ngalert/models/alert_rule.go#L167-L173)).
  - It stays the same while the alerts route through the same policies with the same group label values, firing through resolved, and again when an emptied group re-forms. It changes when a matcher on the policy path changes, when group_by changes, or when a group label value changes. Renaming the rule changes `alertname`; renaming or moving its folder changes `grafana_folder`.
  - It is unique only within one organisation's Alertmanager. Grafana runs one per org ([ngalert/notifier/alertmanager.go L164-166](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/pkg/services/ngalert/notifier/alertmanager.go#L164-L166)), and the same key can occur in two orgs or two Grafana instances. Alertmanager's own comment says the route key "does not uniquely identify the route" ([route.go L172](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/dispatch/route.go#L172)); two sibling policies with identical matchers give the same key.
  - The contact point's Test button sends one alert, labelled `alertname=TestAlert` and `instance=Grafana`, under the key `<integration type>-<fingerprint>-<unix seconds>`, for example `discord-<16 hex digits>-1792226400`, which is new on every click ([test_receivers.go L58-67](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/notify/test_receivers.go#L58-L67), [receivers.go L71-90](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/notify/receivers.go#L71-L90), [factory.go L160](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/notify/factory.go#L160)).
- `.GroupLabels` holds the group-by labels and their values, unfiltered ([template_data.go L365](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/template_data.go#L365)).
- `.CommonLabels` and `.CommonAnnotations` hold what every alert in this notification shares, with private `__name__`-style keys removed ([template_data.go L366-367](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/template_data.go#L366-L367)). They change as alerts join and leave the group.
- `.Status`, `.ExternalURL` and `.AppVersion`. `.Receiver` is the contact point name passed through `regexp.QuoteMeta`, so `Tickets (prod)` reads `Tickets \(prod\)` ([template.go L359](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/template/template.go#L359)).
- `.TruncatedAlerts` and `.Vars` are filled only by the webhook notifier ([webhook.go L70, L96-99](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/webhook.go#L70-L99)).

Per alert, under `.Alerts`, `.Alerts.Firing` and `.Alerts.Resolved`:

- `.Fingerprint` is 16 hex digits, a 64-bit hash of the alert's full label set ([template.go L378](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/template/template.go#L378), [signature.go L54-73](https://github.com/prometheus/common/blob/v0.70.0/model/signature.go#L54-L73), [fingerprinting.go L40-42](https://github.com/prometheus/common/blob/v0.70.0/model/fingerprinting.go#L40-L42)). The docs say "alarms with the same labels will have the same fingerprint" ([table-for-json-alert-object.md L14](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/shared/alerts/table-for-json-alert-object.md#L14)). The hash also covers labels hidden from `.Labels`, namely `__alert_rule_uid__` on every alert and, under simplified routing, `__grafana_autogenerated__`, `__grafana_receiver__` and, with overrides, `__grafana_route_settings_hash__` ([state.go L780-795](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/pkg/services/ngalert/state/state.go#L780-L795), [notifications.go L235-243](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/pkg/services/ngalert/models/notifications.go#L235-L243)). Grafana drops the folder UID label before the alert reaches the Alertmanager ([grafana_alertmanager.go L966](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/notify/grafana_alertmanager.go#L966)). The fingerprint is therefore the same in the firing and resolved posts of one instance. It changes when the rule is renamed, its folder changes, any label value changes, or, under simplified routing, the rule's contact point or notification settings change.
- `.Labels` holds every label except private `__name__`-style ones ([template_data.go L213](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/template_data.go#L213)), including `alertname` and `grafana_folder` ([state.go L780-789](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/pkg/services/ngalert/state/state.go#L780-L789)).
- `.RuleUID` is the rule's UID, read from `__alert_rule_uid__` ([template_data.go L126, L219](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/template_data.go#L126-L219)). It is in the code but absent from the docs' alert table ([reference.md L71-94](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/configure-notifications/template-notifications/reference.md#L71-L94)).
- `.GeneratorURL` is `<root URL>/alerting/grafana/<rule UID>/view?orgId=<org>` ([compat.go L88-95](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/pkg/services/ngalert/state/compat.go#L88-L95), [template_data.go L231-237](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/template_data.go#L231-L237)).
- `.SilenceURL` is `/alerting/silence/new` with a matcher on the rule UID and on each remaining non-private label ([template_data.go L316-344](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/template_data.go#L316-L344)).
- `.Status`, `.StartsAt`, `.EndsAt`, `.Annotations`, `.Values`, `.ValueString`, `.OrgID`, and `.DashboardURL` and `.PanelURL` when the rule links a panel ([template_data.go L115-133, L209-276](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/template_data.go#L115-L276)). `.EndsAt` is the zero time while the alert fires ([dispatch.go L566-575](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/dispatch/dispatch.go#L566-L575)).
- `alertname` is reachable as `.CommonLabels.alertname`, `.GroupLabels.alertname`, or per alert as `.Labels.alertname`.

The default Discord output carries the group label values in the title. Per alert, the message carries labels, annotations, a `Source:` line and a `Silence:` line. `Source:` is the generator URL, so it contains the rule UID and org ID ([default_template.go L27-45](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/default_template.go#L27-L45)). Neither default template prints `.GroupKey`, `.Fingerprint`, `.RuleUID` as such, or `.StartsAt`.

### 5. URL, headers, responses and retries

The only check on the Discord URL is that it is non-empty ([config.go L30-33](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/config.go#L30-L33)). Nothing checks the host and nothing appends `?wait=true` or any other query parameter. Outside doc comments, neither the pinned grafana/alerting tree nor Grafana's `pkg/services/ngalert` mentions `discord.com`. Before sending, Grafana renders the URL as a template with the notification data and falls back to the raw setting on a template error ([discord.go L207-211](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L207-L211)). The request is a POST ([discord.go L306-310](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L306-L310)).

Headers:

- `Content-Type: application/json`, or `multipart/form-data; boundary=...` when images are attached ([discord.go L311-315, L352](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L311-L352), [client.go L133-137](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/http/client.go#L133-L137)).
- `User-Agent: Grafana` ([client.go L44-48, L138](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/http/client.go#L44-L138)). Grafana passes no client options that would change it ([grafana_alertmanager.go L811-823](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/notify/grafana_alertmanager.go#L811-L823)).
- No `Authorization`. Discord sets no user, password or extra headers ([discord.go L306-310](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L306-L310)), and its schema has no header or auth fields ([config.go L43-92](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/config.go#L43-L92)). The v1 integration builder reads an `http_config.oauth2` block from any v1 integration's settings ([factory.go L131-149](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/notify/factory.go#L131-L149), [receivers.go L235-255](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/notify/receivers.go#L235-L255)), but only the webhook schema offers it ([webhook/v1/config.go L361](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/config.go#L361)). Whether provisioning accepts it on a Discord integration is **unverified**.

The client has a 30-second timeout and no keep-alive ([tls.go L14-38](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/http/tls.go#L14-L38)). It sets no redirect policy of its own. On a redirect to another host it strips credential headers ([client.go L148-154](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/http/client.go#L148-L154), [redirect.go L8-18](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/http/redirect.go#L8-L18)).

Any 2xx counts as delivered and the response body is ignored. Discord's validator runs only on 2xx responses and accepts every one ([client.go L186-200](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/http/client.go#L186-L200), [discord.go L223-233](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L223-L233)). Any other status is an error that carries up to 1024 bytes of the response body ([client.go L26, L202-207](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/http/client.go#L26-L207)).

Discord marks every send error as not retryable ([discord.go L234-236](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/discord.go#L234-L236)), so the retry stage stops after one attempt ([notify.go L877-879](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/notify/notify.go#L877-L879)), although it would otherwise back off without a time limit ([notify.go L829-830](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/notify/notify.go#L829-L830)). The error stops the integration's stages before the notification log is written ([notify.go L471-484](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/notify/notify.go#L471-L484), [grafana_alertmanager.go L1029-1046](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/notify/grafana_alertmanager.go#L1029-L1046)), and the group keeps its resolved alerts ([dispatch.go L392-406, L583-591](https://github.com/grafana/prometheus-alertmanager/blob/18275ca76b0ca61040d85db17cf5f41c5b2d78e2/dispatch/dispatch.go#L392-L591)). At the next group_interval tick the dedup stage sees the old log entry and sends again, rebuilt from the group as it is then. With defaults a failed post is re-attempted every 5 minutes for as long as the group differs from the last logged post.

### 6. Alternatives

#### Webhook contact point

The default payload is the notification data from section 4 plus `version`, `groupKey`, `truncatedAlerts`, `orgId`, `title`, `state` and `message` ([webhook.go L46-57, L120-134](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/webhook.go#L46-L134), [template_data.go L115-157](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/template_data.go#L115-L157)). `state` is `alerting` when any alert fires, else `ok` ([webhook.go L88-91](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/webhook.go#L88-L91)). `title` and `message` are the contact point's title and message templates, defaulting to `default.title` and `default.message` ([config.go L103-110](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/config.go#L103-L110)). The payload also carries `appVersion` ([template_data.go L147, L379](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/template_data.go#L147-L379)), which the docs example lacks. Each alert has `status`, `labels`, `annotations`, `startsAt`, `endsAt`, `generatorURL`, `fingerprint`, `silenceURL`, `dashboardURL`, `panelURL`, `ruleUID`, `values` and `valueString`, plus `imageURL`, `orgId` and `enrichments` when set ([template_data.go L115-133](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/template_data.go#L115-L133), [webhook.go L78-86](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/webhook.go#L78-L86)). The docs describe the same body ([webhook-notifier.md L128-232](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/configure-notifications/manage-contact-points/integrations/webhook-notifier.md#L128-L232), [table-for-json-alert-object.md](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/shared/alerts/table-for-json-alert-object.md)).

Example for the firing alert of section 1. The `valueString` and `fingerprint` values are placeholders.

```json
{
  "receiver": "Tickets",
  "status": "firing",
  "alerts": [
    {
      "status": "firing",
      "labels": {
        "alertname": "High CPU",
        "grafana_folder": "Infra",
        "instance": "host1",
        "severity": "critical"
      },
      "annotations": {
        "summary": "CPU above 90%"
      },
      "startsAt": "2026-10-17T09:00:00Z",
      "endsAt": "0001-01-01T00:00:00Z",
      "generatorURL": "https://grafana.example.com/alerting/grafana/ddx4k2abc/view?orgId=1",
      "fingerprint": "3f1c0b6a9e2d4c57",
      "silenceURL": "https://grafana.example.com/alerting/silence/new?alertmanager=grafana&matcher=__alert_rule_uid__%3Dddx4k2abc&matcher=instance%3Dhost1&matcher=severity%3Dcritical&orgId=1",
      "dashboardURL": "",
      "panelURL": "",
      "ruleUID": "ddx4k2abc",
      "values": {
        "A": 93.5,
        "C": 1
      },
      "valueString": "<last evaluation string>",
      "orgId": 1
    }
  ],
  "groupLabels": {
    "alertname": "High CPU",
    "grafana_folder": "Infra"
  },
  "commonLabels": {
    "alertname": "High CPU",
    "grafana_folder": "Infra",
    "instance": "host1",
    "severity": "critical"
  },
  "commonAnnotations": {
    "summary": "CPU above 90%"
  },
  "externalURL": "https://grafana.example.com/",
  "appVersion": "13.2.4",
  "version": "1",
  "groupKey": "{}:{alertname=\"High CPU\", grafana_folder=\"Infra\"}",
  "truncatedAlerts": 0,
  "orgId": 1,
  "title": "[FIRING:1] High CPU Infra (host1 critical)",
  "state": "alerting",
  "message": "**Firing**\n\nValue: A=93.5, C=1\nLabels:\n - alertname = High CPU\n - grafana_folder = Infra\n - instance = host1\n - severity = critical\nAnnotations:\n - summary = CPU above 90%\nSource: https://grafana.example.com/alerting/grafana/ddx4k2abc/view?orgId=1\nSilence: https://grafana.example.com/alerting/silence/new?alertmanager=grafana&matcher=__alert_rule_uid__%3Dddx4k2abc&matcher=instance%3Dhost1&matcher=severity%3Dcritical&orgId=1\n"
}
```

The custom payload setting `payload.template` replaces the whole body with the template's output, and `payload.vars` adds key/value pairs at `.Vars` ([config.go L20-28, L294-316](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/config.go#L20-L316), [webhook.go L96-106](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/webhook.go#L96-L106)). Grafana then ignores the Title and Message settings ([webhook-notifier.md L234-271](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/configure-notifications/manage-contact-points/integrations/webhook-notifier.md#L234-L271)). A template error fails the send ([webhook.go L103-105](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/webhook.go#L103-L105)). A built-in `webhook.default.payload` template reproduces the default shape ([default_template.go L114-132](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/templates/default_template.go#L114-L132)), and `coll.Dict`, `data.ToJSON` and `tmpl.Exec` are available for building JSON ([reference.md L244-390](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/configure-notifications/template-notifications/reference.md#L244-L390)). The feature arrived in Grafana 12.0.0 as "Webhook Improvements - Templateable Payloads" ([CHANGELOG.md L2577, L2602](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/CHANGELOG.md#L2577-L2602)); the schema comments it "New in 12.0" ([config.go L294](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/config.go#L294)). The docs mark it not yet generally available in Grafana Cloud ([webhook-notifier.md L243](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/configure-notifications/manage-contact-points/integrations/webhook-notifier.md#L243)).

Auth and transport options:

- HTTP basic auth through `username` and `password` ([config.go L88-89](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/config.go#L88-L89), [client.go L140-142](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/http/client.go#L140-L142)).
- An `Authorization` header built from `authorization_scheme`, default `Bearer`, and `authorization_credentials`, new in 9.1 per the schema comments ([config.go L90-94, L249-264](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/config.go#L90-L264), [webhook.go L142-144](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/webhook.go#L142-L144)). Basic auth and the header are mutually exclusive ([config.go L95-97](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/config.go#L95-L97), [webhook-notifier.md L75-79](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/configure-notifications/manage-contact-points/integrations/webhook-notifier.md#L75-L79)).
- Extra headers, new in 12.0 per the schema comment ([config.go L265-271](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/config.go#L265-L271)). The config refuses `Authorization`, `User-Agent`, `Host`, cookies, forwarding headers and others ([config.go L133-140, L146-173](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/config.go#L133-L173)); `Content-Type` may be overridden ([webhook-notifier.md L70](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/configure-notifications/manage-contact-points/integrations/webhook-notifier.md#L70)).
- HMAC-SHA256 signing. The hex signature goes in `X-Grafana-Alerting-Signature` by default and covers the body, or `<unix timestamp>:<body>` when a timestamp header is named, in which case the timestamp is sent in that header ([hmac.go L17-22, L66-92](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/http/hmac.go#L17-L92), [webhook-notifier.md L81-110](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/configure-notifications/manage-contact-points/integrations/webhook-notifier.md#L81-L110)). Added in 12.0.0 ([CHANGELOG.md L2582](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/CHANGELOG.md#L2582)).
- TLS client settings, and OAuth2 client credentials through `http_config`, "New in 12.1" per the schema comment ([config.go L318-361](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/config.go#L318-L361), [client.go L63-75, L171-174](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/http/client.go#L63-L174)).
- Method POST or PUT, and `maxAlerts` truncation ([config.go L79-86](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/config.go#L79-L86), [webhook.go L67, L176-182](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/webhook.go#L67-L182)).
- Grafana renders the URL as a template, and `grafana://noop` skips the send ([webhook.go L72-76, L153-156](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/webhook.go#L72-L156), [config.go L18](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/config.go#L18)).
- The URL is not a secure field. It is stored in plain text and shown when the contact point is read back ([webhook-notifier.md L53-59](https://github.com/grafana/grafana/blob/af4a33e3786594ad72f72d97e6744010330c2977/docs/sources/alerting/configure-notifications/manage-contact-points/integrations/webhook-notifier.md#L53-L59), [config.go L213-220](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/config.go#L213-L220)). The Discord URL is a secure field ([discord/v1/config.go L63-72](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/discord/v1/config.go#L63-L72)).
- Responses and retries work as for Discord. Any 2xx succeeds, and send errors are not retried within a tick ([webhook.go L169-171](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/webhook.go#L169-L171), [client.go L186-207](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/http/client.go#L186-L207)). `disableResolveMessage` behaves the same ([webhook.go L184-186](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/webhook/v1/webhook.go#L184-L186)).

#### Slack contact point

The `url` setting, labelled "Webhook URL", takes any URL that parses and is a secure field ([slack/v1/config.go L41-54, L163-174](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/slack/v1/config.go#L41-L174)). With no token set, Grafana treats it as an incoming webhook. With a token set, it sends `Authorization: Bearer <token>` to whatever URL is configured ([slack.go L91-93, L405-409](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/slack/v1/slack.go#L91-L409)). The body is JSON with `username`, which defaults to `Grafana`, optional `channel`, `icon_emoji` and `icon_url`, and one attachment. The attachment holds `title` from the title template, `title_link` set to the alerts' common generator URL or else `/alerting/list`, `text` from the message template, `fallback`, `color`, `footer`, `footer_icon`, `ts`, and optional `pretext`, `mrkdwn_in` and `image_url` ([slack.go L122-147, L283-385](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/slack/v1/slack.go#L122-L385), [slack/v1/config.go L66-78](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/slack/v1/config.go#L66-L78)). It sends `Content-Type: application/json; charset=utf-8` and `User-Agent: Grafana` ([slack.go L399-400](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/slack/v1/slack.go#L399-L400)). Success needs a status outside 1xx, 3xx and 5xx, then either a JSON body with `"ok": true` or, for any other content type, a body of exactly `ok` ([slack.go L610-692](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/slack/v1/slack.go#L610-L692)). An empty 204 is a failure. Its templates get the same data as Discord's, `.GroupKey` included ([slack.go L284-285](https://github.com/grafana/alerting/blob/7f02604ca93b1d6db805848d6a9b4d3c82540507/receivers/slack/v1/slack.go#L284-L285)).

## healthchecks.io

Read [healthchecks/healthchecks](https://github.com/healthchecks/healthchecks) at tag `v4.4`, commit `49924faadf0f24a846abc3acf3014b396fdc1f29`, released 2026-08-31. It was the latest release on 2026-10-17, the date of this report. Every `L` range below is a line range in that tag. I probed the hosted site with unauthenticated GET requests on 2026-10-17, and read our own code at cavforo-suite commit `833a87e`.

### 1. The Discord integration

**How it is added.** The Integrations page shows the Discord tile only when `DISCORD_CLIENT_ID` is set [hc/front/views.py L1246](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/front/views.py#L1246). The add view sends the operator to `https://discordapp.com/api/oauth2/authorize` with `scope=webhook.incoming`, `response_type=code`, a random `state`, and `redirect_uri` set to `SITE_ROOT/integrations/add_discord/` [hc/integrations/discord/views.py L24-L42](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/discord/views.py#L24-L42). The page has a single "Connect Discord" button and no URL field [add_discord.html L11-L26](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/discord/templates/add_discord.html#L11-L26).

**What it stores.** On the callback, healthchecks checks `state`, then POSTs the code, client ID and client secret to `https://discordapp.com/api/oauth2/token`. It refuses a response without `access_token`, warns on Discord error code 30007, "maximum number of webhooks reached", and stores the whole response body text as `Channel.value` [discord/views.py L45-L90](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/discord/views.py#L45-L90). The URL it later posts to is `value["webhook"]["url"]`, with a leading `https://discordapp.com/` rewritten to `https://discord.com/` [hc/api/models.py L1266-L1276](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L1266-L1276). The test fixture models that response as `{"access_token": ..., "webhook": {"url": ..., "id": ...}}` [test_add_complete.py L17-L39](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/discord/tests/test_add_complete.py#L17-L39). That Discord puts a `discord.com/api/webhooks/...` URL in that field is Discord's behavior, outside the sources in scope, and **unverified**.

**Can an operator point it elsewhere?** Not through the product. The URL comes only from Discord's token response. `Channel.is_editable` covers email, webhook, sms, signal, whatsapp, ntfy, group and gotify, so a Discord integration has no Edit button [models.py L1054-L1064](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L1054-L1064), [channels.html L82-L86](https://github.com/healthchecks/healthchecks/blob/v4.4/templates/front/channels.html#L82-L86). The Management API exposes channels only as a GET list [hc/api/urls.py L67](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/urls.py#L67), [hc/api/views.py L471-L477](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/views.py#L471-L477). The one route around this is on a self-hosted instance. The Django admin registers `Channel` with only `code` read-only, so a superuser can edit `value` by hand [hc/api/admin.py L193-L213](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/admin.py#L193-L213), and the transport does not check the host it posts to [discord/transport.py L19-L23](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/discord/transport.py#L19-L23). That is a reading of the code, not something I ran.

**What it posts.** `Discord` subclasses `Slackalike` and POSTs `Slackalike.payload(flip)` as JSON to `discord_webhook_url + "/slack"` [discord/transport.py L11-L23](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/discord/transport.py#L11-L23). The test asserts the target `https://example.org/slack` and a Slack `attachments` array [discord/tests/test_notify.py L39-L55](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/discord/tests/test_notify.py#L39-L55). The body is the same one Slack and Mattermost get. The only Discord-specific change is that the transport escapes asterisks in a cron schedule with a backslash [discord/transport.py L25-L27](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/discord/transport.py#L25-L27). What Discord's `/slack` endpoint does with a Slack body is Discord's behavior and **unverified** here.

`Slackalike.payload` builds [hc/integrations/slack/transport.py L31-L86](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/slack/transport.py#L31-L86):

- `username` is `SITE_NAME`, and `icon_url` is `SITE_LOGO_URL` or the absolute URL of `static/img/logo.png` [hc_extras.py L66-L76](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/front/templatetags/hc_extras.py#L66-L76).
- One attachment. `color` is `good` for up and `danger` for down. `fallback` is `The check "<name>" is DOWN.` with straight quotes. `title` is `“<name>” is DOWN.` with U+201C and U+201D quotes. `<name>` is the check's name, or its UUID when the name is empty [models.py L243-L247](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L243-L247).
- `title_link` is the cloaked check URL, `SITE_ROOT/cloaked/<unique_key>/` [models.py L275-L276](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L275-L276), [hc/front/urls.py L58](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/front/urls.py#L58).
- `text` is `Reason: <reason>.` when the flip has a reason. For an up flip with a known downtime it is `The downtime lasted <duration>.` Otherwise it is `null`. The two reasons are "success signal did not arrive on time, grace time passed" and "received a failure signal" [models.py L1410-L1415](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L1410-L1415).
- `fields`, in this order, each `{"title", "value", "short": true}`: Description, which is not short and only present if set; Project, if the project has a name; Tags, each wrapped in backticks; Period for simple checks, or Schedule and Time Zone for cron and OnCalendar checks; Total Pings; Last Ping, such as `Success, 10 minutes ago`; and Last Ping Body in a fenced block, cut to 1000 characters and left out if the body contains three backticks.

The HTTP client adds `Content-Type: application/json` when given `json=`, and `User-Agent: healthchecks.io` unless one is set [hc/lib/curl.py L157-L165](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/lib/curl.py#L157-L165).

Example, a simple check going down on timeout, on a site whose `SITE_NAME` is `Healthchecks.io`:

```
POST https://discord.com/api/webhooks/<webhook id>/<webhook token>/slack
Content-Type: application/json
User-Agent: healthchecks.io
```

```json
{
  "username": "Healthchecks.io",
  "icon_url": "https://healthchecks.io/static/img/logo.png",
  "attachments": [
    {
      "color": "danger",
      "fallback": "The check \"nightly-backup\" is DOWN.",
      "mrkdwn_in": ["fields"],
      "title": "“nightly-backup” is DOWN.",
      "title_link": "https://healthchecks.io/cloaked/<sha1 unique_key>/",
      "text": "Reason: success signal did not arrive on time, grace time passed.",
      "fields": [
        {"title": "Project", "value": "7Cav Ops", "short": true},
        {"title": "Tags", "value": "`prod` `backup`", "short": true},
        {"title": "Period", "value": "1 day", "short": true},
        {"title": "Total Pings", "value": "412", "short": true},
        {"title": "Last Ping", "value": "Success, 1 day, 1 hour ago", "short": true}
      ]
    }
  ]
}
```

The matching up notification for the same check:

```json
{
  "username": "Healthchecks.io",
  "icon_url": "https://healthchecks.io/static/img/logo.png",
  "attachments": [
    {
      "color": "good",
      "fallback": "The check \"nightly-backup\" is UP.",
      "mrkdwn_in": ["fields"],
      "title": "“nightly-backup” is UP.",
      "title_link": "https://healthchecks.io/cloaked/<sha1 unique_key>/",
      "text": "The downtime lasted 3 hours, 12 minutes.",
      "fields": [
        {"title": "Project", "value": "7Cav Ops", "short": true},
        {"title": "Tags", "value": "`prod` `backup`", "short": true},
        {"title": "Period", "value": "1 day", "short": true},
        {"title": "Total Pings", "value": "413", "short": true},
        {"title": "Last Ping", "value": "Success, 5 seconds ago", "short": true}
      ]
    }
  ]
}
```

The downtime sentence comes from `format_duration_for_sentence` [hc/lib/date.py L84-L100](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/lib/date.py#L84-L100). The ping kind labels come from `Ping.get_kind_display` [models.py L790-L806](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L790-L806).

**Against our hook today.** `HookPost` reads only top-level `content` and `embeds`, and returns a bad request when neither yields text, per `src/addons/Cav7/TicketWebhook/HookPost.php` L79-L121. This body has neither, so the hook would answer 400. The `/slack` suffix would also land on our path. How XenForo routes `ticket-webhooks/{id}/{token}//slack` against the format `:int<hook_id>/:str<token>/` in `src/addons/Cav7/TicketWebhook/_data/routes.xml` is **unverified**.

### 2. Integrations that take an operator-supplied URL

These integrations take a URL the operator types. Slack, Mattermost, Rocket.Chat, Google Chat, Teams, PagerTree, Splunk On-Call and Spike use `AddUrlForm` [hc/front/forms.py L125-L127](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/front/forms.py#L125-L127). Its `LaxURLField` accepts `http` and `https`, up to 1000 characters, and hosts without a TLD [hc/front/forms.py L22-L29](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/front/forms.py#L22-L29), [hc/front/validators.py L14-L34](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/front/validators.py#L14-L34). Webhook, ntfy, Gotify and Zulip put the same field in their own forms, and Apprise takes a free string. None of them sends a JSON body with a top-level `content` or `embeds` key. I checked this by reading each transport and grepping `hc/integrations` for both keys. The only top-level `content` is Zulip's, and that is a form-encoded field.

- **Webhook.** Any URL, with a body the operator writes. See question 3.
- **Slack, URL entered by hand.** The form stores the URL as typed [slack/views.py L24-L46](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/slack/views.py#L24-L46). The transport POSTs the `Slackalike` body shown in question 1 to that URL with no suffix [slack/transport.py L122-L126](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/slack/transport.py#L122-L126), [models.py L1255-L1264](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L1255-L1264). When `SLACK_CLIENT_ID` is set, the Integrations page links the OAuth "Add to Slack" flow instead [hc/front/views.py L1260-L1261](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/front/views.py#L1260-L1261), [channels.html L350-L361](https://github.com/healthchecks/healthchecks/blob/v4.4/templates/front/channels.html#L350-L361). The hand-entry route `projects/<uuid>/add_slack/` stays registered and needs only `SLACK_ENABLED` [slack/urls.py L10](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/slack/urls.py#L10). Whether hosted healthchecks.io serves that form to a logged-in user is **unverified**.
- **Mattermost.** The form stores the URL as typed. The transport POSTs the same `Slackalike` body to it [mattermost/transport.py L10-L16](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/mattermost/transport.py#L10-L16), [mattermost/views.py L21-L39](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/mattermost/views.py#L21-L39).
- **Rocket.Chat.** The form stores the URL as typed. The body is `{"alias": SITE_NAME, "avatar": <logo URL>, "text": "[<name>](<cloaked URL>) is DOWN (<reason>).", "attachments": [{"color": "#d9534f" or "#5cb85c", "fields": [...]}]}`. On up, a downtime line follows the text. The fields match Slack's, except that Last Ping Body is a size and a link rather than the body [rocketchat/transport.py L18-L65](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/rocketchat/transport.py#L18-L65), [rocketchat_message.html L1-L3](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/rocketchat/templates/rocketchat_message.html#L1-L3).
- **Google Chat.** The body is a `cardsV2` card: a text paragraph, decorated-text fields and a button to the cloaked URL [googlechat/transport.py L18-L77](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/googlechat/transport.py#L18-L77).
- **Microsoft Teams, Workflows.** The body is `{"type": "message", "attachments": [{"contentType": "application/vnd.microsoft.card.adaptive", "content": <AdaptiveCard>}]}`, with a heading, a FactSet and an Open URL action to the cloaked URL [msteamsw/transport.py L18-L114](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/msteamsw/transport.py#L18-L114).
- **PagerTree.** The body is `{"incident_key": <unique_key>, "event_type": "trigger" or "resolve", "title", "description", "client": SITE_NAME, "client_url": SITE_ROOT, "tags"}` [pagertree/transport.py L9-L32](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/pagertree/transport.py#L9-L32).
- **Splunk On-Call.** The body is `{"entity_id": <unique_key>, "message_type": "CRITICAL" or "RECOVERY", "entity_display_name": <name>, "state_message", "monitoring_tool": SITE_NAME}` [victorops/transport.py L20-L38](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/victorops/transport.py#L20-L38).
- **Spike.** The body is `{"check_id": <unique_key>, "title", "message", "status": "up" or "down"}` [spike/transport.py L9-L29](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/spike/transport.py#L9-L29).
- **ntfy.** The operator gives a server URL, a topic and an optional token. The transport POSTs `{"topic", "priority", "title", "message", "markdown": true, "tags", "actions"}` to the server URL itself, with the token as `Authorization: Bearer <token>` [ntfy/transport.py L18-L55](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/ntfy/transport.py#L18-L55), [ntfy/forms.py L36-L43](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/ntfy/forms.py#L36-L43).
- **Gotify.** The operator gives a server URL and a token. The transport POSTs `{"title", "message", "extras", "priority"}` to `<url>/message?token=<token>` [gotify/transport.py L18-L44](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/gotify/transport.py#L18-L44).
- **Zulip.** The operator gives a site URL, bot email and API key. The transport sends a form-encoded POST of `type`, `to`, `topic` and `content` to `<site>/api/v1/messages` with HTTP basic auth [zulip/transport.py L28-L53](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/zulip/transport.py#L28-L53).
- **Apprise.** The operator gives any Apprise URL string, and the Apprise library sends the notification. The integration is off unless `APPRISE_ENABLED` is set and the package is installed [apprise/transport.py L8-L35](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/apprise/transport.py#L8-L35), [hc/settings.py L356](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/settings.py#L356). What Apprise sends for a given scheme is Apprise's behavior and **unverified**.

### 3. The generic Webhook integration

**Separate down and up configuration.** The form has its own method, URL, body and headers for each of down and up, and the method is one of GET, POST and PUT [hc/integrations/webhook/forms.py L55-L67](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/forms.py#L55-L67). The channel stores them as `method_down`, `url_down`, `body_down`, `headers_down` and the `_up` counterparts [models.py L1202-L1220](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L1202-L1220). At least one URL is required [forms.py L69-L77](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/forms.py#L69-L77). A status with an empty URL is a no-op, and healthchecks writes no Notification row for it [webhook/transport.py L61-L65](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/transport.py#L61-L65), [models.py L1151-L1153](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L1151-L1153). Webhook integrations are editable after creation [models.py L1054-L1064](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L1054-L1064).

**Limits.** The URL and the body template are capped at 1000 characters each, and each header name and value at 1000 [forms.py L48-L67](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/forms.py#L48-L67). The transport sends a body only for POST and PUT [webhook/transport.py L83-L86](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/transport.py#L83-L86). The body goes out as raw bytes, so healthchecks adds no `Content-Type` [webhook/transport.py L93](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/transport.py#L93), [curl.py L157-L165](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/lib/curl.py#L157-L165). The operator sets one as a header [webhook/tests/test_notify.py L265-L279](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/tests/test_notify.py#L265-L279). Our controller decodes the raw input whatever the content type, per `src/addons/Cav7/TicketWebhook/Pub/Controller/Hook.php` L80.

**Placeholders.** These are the placeholders in the code [webhook/transport.py L28-L52](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/transport.py#L28-L52). The in-app reference lists the same set [webhook_form.html L195-L262](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/templates/webhook_form.html#L195-L262).

- `$CODE` is the check UUID.
- `$STATUS` is `flip.new_status`, so `down` or `up`.
- `$NOW` is `flip.created` without microseconds, in ISO 8601 with `+00:00`. The in-app text calls it "Current UTC time". For a timeout, `sendalerts` sets `flip.created` to the moment the grace period ran out [sendalerts.py L158-L175](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/management/commands/sendalerts.py#L158-L175). So `$NOW` is the transition time, not the send time.
- `$NAME` is the check name as typed. `$NAME_JSON` is `json.dumps(name)`, a quoted JSON string.
- `$SLUG` is the check slug, empty when unset.
- `$TAGS` is the space-separated tags. `$TAG1`, `$TAG2` and so on are the individual tags.
- `$JSON` is `json.dumps(check.to_dict())`. It has the same fields as the API's single-check response, including `uuid`, `ping_url`, `update_url`, `pause_url`, `resume_url`, `channels`, and a `status` computed at send time [models.py L439-L491](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L439-L491).
- `$BODY` is the last ping's body, and `$BODY_JSON` is the same as a JSON string. Both work only in the body field [test_notify.py L415-L439](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/tests/test_notify.py#L415-L439).
- `$EXITSTATUS` is the last ping's exit status, or `-1` when there is none.

Substitution is a single pass, so a `$` inside a value is never expanded again [hc/lib/string.py L10-L47](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/lib/string.py#L10-L47).

**Escaping.** In the URL, the transport percent-encodes `$NOW`, `$NAME`, `$NAME_JSON`, `$TAGS`, `$TAGn` and `$JSON` with `urllib.parse.quote`. `$CODE`, `$STATUS` and `$SLUG` go in as they are [webhook/transport.py L24-L37](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/transport.py#L24-L37), [L76](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/transport.py#L76). In the body, the transport escapes nothing. `$NAME` goes in raw [test_notify.py L488-L501](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/tests/test_notify.py#L488-L501), and so does `$BODY` [L518-L537](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/tests/test_notify.py#L518-L537). The JSON-safe forms are `$NAME_JSON`, `$BODY_JSON` and `$JSON` [L503-L516](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/tests/test_notify.py#L503-L516), [L539-L552](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/tests/test_notify.py#L539-L552). `$CODE`, `$STATUS` and `$NOW` contain only JSON-safe characters. A slug matches `^[a-z0-9-_]*$` on the API [hc/api/views.py L74](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/views.py#L74) and is a Django `SlugField` in the web form [hc/front/forms.py L34](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/front/forms.py#L34). Tags are free text split on spaces [hc/front/forms.py L38-L46](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/front/forms.py#L38-L46), so a tag containing `"` or `\` placed in a JSON string through `$TAGS` or `$TAGn` produces invalid JSON. In header values, characters outside Latin-1 become XML character references [webhook/transport.py L55-L57](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/transport.py#L55-L57), [L88-L91](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/transport.py#L88-L91).

**Custom headers and `Authorization: Bearer`.** The headers field takes `Name: value` lines. A name must be Latin-1 and non-empty, a value must be non-empty, and there is no list of forbidden names [forms.py L19-L52](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/forms.py#L19-L52). So `Authorization: Bearer <token>` is accepted as is. The `User-Agent` can be overridden too [test_notify.py L298](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/tests/test_notify.py#L298). Header values pass through placeholder substitution, but a token in our `[A-Za-z0-9_-]` alphabet contains no `$`. The channel stores the headers as plain JSON in `Channel.value` [forms.py L79-L80](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/forms.py#L79-L80). The Integrations page prints the method, URL, body and headers in a details dialog to project members with write access [channels.html L494-L560](https://github.com/healthchecks/healthchecks/blob/v4.4/templates/front/channels.html#L494-L560).

**Example.** This down configuration makes a Webhook integration emit a body our hook parses today:

```
POST https://board/ticket-webhooks/1/
Content-Type: application/json
Authorization: Bearer <token>

{"content": "Check $CODE is $STATUS since $NOW", "embeds": [{"title": $NAME_JSON, "fields": [{"name": "Status", "value": "$STATUS"}, {"name": "Check UUID", "value": "$CODE"}, {"name": "Slug", "value": "$SLUG"}]}]}
```

For a check named `nightly-backup` with UUID `d1665499-e827-441f-bf13-8f15e8f4c0b9` and slug `nightly-backup`, the bytes on the wire are:

```json
{"content": "Check d1665499-e827-441f-bf13-8f15e8f4c0b9 is down since 2026-10-17T03:00:00+00:00", "embeds": [{"title": "nightly-backup", "fields": [{"name": "Status", "value": "down"}, {"name": "Check UUID", "value": "d1665499-e827-441f-bf13-8f15e8f4c0b9"}, {"name": "Slug", "value": "nightly-backup"}]}]}
```

The up side has its own URL, body and headers, and can carry the same template.

### 4. States and events

Stored check statuses are `up`, `down`, `new` and `paused` [models.py L38](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L38). The code computes `grace` and `started` for display and never stores or notifies them [models.py L355-L380](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L355-L380).

Healthchecks creates a Flip, the record that drives notifications, in three places:

- On a success or fail ping, only when the status changes. Reason `fail` goes with a fail [models.py L517-L539](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L517-L539). A fail ping comes from `/fail`, a nonzero exit status, a failure keyword match, or the "fail by default" filter [hc/api/views.py L213-L228](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/views.py#L213-L228).
- In `sendalerts`, when a check's `alert_after` passes. This is reason `timeout` [sendalerts.py L123-L177](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/management/commands/sendalerts.py#L123-L177). It covers a missed schedule and a run that sent `/start` and did not finish within the grace time [models.py L334-L337](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L334-L337), [L359-L361](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L359-L361).
- When an operator pauses or resumes a check, or changes its schedule. The code creates these flips already marked processed, so they never notify [hc/front/views.py L636-L660](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/front/views.py#L636-L660), [L820-L860](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/front/views.py#L820-L860), [hc/api/views.py L557](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/views.py#L557), [L582](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/views.py#L582).

`/start` alone sets `last_start` and creates no flip. `log` and ignored pings fall through both branches and leave the status alone [models.py L517-L539](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L517-L539).

`Flip.select_channels` sends nothing for `new` to `up` or `paused` to `up`. It raises for any new status other than `up` or `down`, and skips disabled channels and channels that are a no-op for the status [models.py L1390-L1408](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L1390-L1408). So two kinds of event notify. One is a move to `down` from `up`, `new` or `paused`, with reason `timeout` or `fail`. The other is `down` to `up`. A fail ping while already down and a success ping while already up create no flip. Each channel assigned to the check gets one notification per notified transition.

**Repeats.** No integration repeats while a check stays down. Reminders are emails to account users, hourly or daily, set by `Profile.nag_period` [hc/accounts/models.py L77-L82](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/accounts/models.py#L77-L82), [L208-L291](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/accounts/models.py#L208-L291), [sendreports.py L69-L90](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/management/commands/sendreports.py#L69-L90). The docs list incident management escalation, Pushover Emergency priority and those emails as the ways to get repeats [configuring_notifications.md L46-L60](https://github.com/healthchecks/healthchecks/blob/v4.4/templates/docs/configuring_notifications.md#L46-L60). A check with more than 200 flips in 24 hours triggers an email to the team, and integration notifications are not suppressed [sendflappingnotices.py L15-L50](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/accounts/management/commands/sendflappingnotices.py#L15-L50).

**Test button.** "Test!" sends a `down` for an unsaved dummy check named `TEST`. If down is a no-op for the channel, it sends an `up` instead [hc/front/views.py L1300-L1334](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/front/views.py#L1300-L1334). The dummy gets a fresh random UUID from the field default [models.py L196](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L196). Each test is a lone event with an identity that never recurs.

**Delivery order.** `sendalerts` marks a flip processed before sending it and has no later redelivery [sendalerts.py L91-L121](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/management/commands/sendalerts.py#L91-L121). With `--num-workers` above its default of 1, `sendalerts` sends flips from a thread pool [sendalerts.py L67-L73](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/management/commands/sendalerts.py#L67-L73), [L184-L195](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/management/commands/sendalerts.py#L184-L195). So a down and an up for the same check, close together, can be in flight at once. That is an inference from the code. The hosted worker count is **unverified**. Transports read the check when they send, so name, tags and the `$JSON` status reflect send time.

For comparison, healthchecks' own GitHub integration opens a new issue on every down and does nothing on up [github/transport.py L13-L15](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/github/transport.py#L13-L15), [L43-L56](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/github/transport.py#L43-L56).

### 5. Identity

- **UUID, `Check.code`.** Unique and not editable [models.py L196](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L196). The docs call it immutable and the ping credential, and say "Each UUID is a secret" [slug_urls.md L8-L9](https://github.com/healthchecks/healthchecks/blob/v4.4/templates/docs/slug_urls.md#L8-L9), [L27-L37](https://github.com/healthchecks/healthchecks/blob/v4.4/templates/docs/slug_urls.md#L27-L37). It reaches Webhook payloads as `$CODE`, and as `uuid` and `ping_url` inside `$JSON` [models.py L473-L483](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L473-L483). It appears in Slack-style titles only when the check has no name.
- **`unique_key`.** The SHA-1 hex of the first 16 hex characters of the UUID [models.py L431-L434](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L431-L434). It is derived from the UUID, so it is as stable. It reaches the Slack, Mattermost and Discord body as part of `title_link`, which is `SITE_ROOT/cloaked/<unique_key>/`. The same cloaked URL is in the Rocket.Chat text, the Google Chat and Teams buttons and the ntfy action. It is the bare `incident_key`, `entity_id` and `check_id` for PagerTree, Splunk On-Call and Spike. It is not a Webhook placeholder, and `$JSON` carries `uuid` rather than `unique_key` because the transport calls `to_dict()` with `readonly=False` [models.py L471-L483](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L471-L483). The read-only API looks checks up by it [hc/api/urls.py L51](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/urls.py#L51), [L66](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/urls.py#L66).
- **Slug.** Optional and editable. The docs say slugs are not forced unique, and duplicates make slug pings answer 409 [slug_urls.md L69-L71](https://github.com/healthchecks/healthchecks/blob/v4.4/templates/docs/slug_urls.md#L69-L71), [L90-L92](https://github.com/healthchecks/healthchecks/blob/v4.4/templates/docs/slug_urls.md#L90-L92). It reaches payloads only as `$SLUG` and `slug` inside `$JSON`.
- **Name.** Optional, editable, up to 100 characters [models.py L193](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L193). It is `$NAME` and `$NAME_JSON`, and the Slack-style `title` and `fallback`.
- **Tags and project name.** Tags are `$TAGS` and `$TAGn`, and a Slack-style field. The project name is a Slack-style field. No payload read for this report carries a project UUID, a channel UUID, a notification UUID or a flip ID.

### 6. Response handling

- **Delivered.** A final status of 200, 201, 202 or 204. Anything else is a failure [hc/api/transports.py L161-L162](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/transports.py#L161-L162). The Webhook form states the same rule [webhook_form.html L25-L33](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/templates/webhook_form.html#L25-L33). Both of our success answers count: 204, and 200 with `?wait=true`. Among the integrations in this report, only Slack, for `invalid_token`, and Zulip, for its error message, read the response body.
- **Timeout.** 30 seconds per attempt [transports.py L159](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/transports.py#L159).
- **Retries.** Up to three attempts in all, back to back with no delay, inside the same send [transports.py L179-L196](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/transports.py#L179-L196). Tests show three calls on a connection error and on a 500 [webhook/tests/test_notify.py L62-L105](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/tests/test_notify.py#L62-L105). Webhook test notifications make one attempt [webhook/transport.py L77-L81](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/webhook/transport.py#L77-L81). After the last attempt the notification is never retried.
- **Permanent failures.** A permanent failure skips the retries and disables the channel. Slack treats 404, and 400 with body `invalid_token`, as permanent [slack/transport.py L100-L120](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/slack/transport.py#L100-L120). Discord treats 404 as permanent [discord/transport.py L12-L17](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/discord/transport.py#L12-L17), [discord/tests/test_notify.py L99-L108](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/discord/tests/test_notify.py#L99-L108), and so does Splunk On-Call [victorops/transport.py L12-L18](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/victorops/transport.py#L12-L18). The other URL integrations in question 2, Webhook, Mattermost and Rocket.Chat among them, have no permanent case, so healthchecks never disables them on its own. Our hook answers 404 for an inactive hook or a wrong token. One such answer would disable a Slack integration pointed at our hook, while a Webhook integration would retry three times and stay enabled.
- **Redirects and private addresses.** The client follows up to three redirects [curl.py L142-L143](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/lib/curl.py#L142-L143). It refuses private IP targets unless `INTEGRATIONS_ALLOW_PRIVATE_IPS` is on [curl.py L128-L136](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/lib/curl.py#L128-L136).
- **What the operator sees.** Each send records `Notification.error`, and sets `Channel.last_error`, `last_notify` and `disabled` [models.py L1151-L1186](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/api/models.py#L1151-L1186). The Integrations page shows "Failed, <time ago>" with the error as a tooltip, or "Disabled" [channels.html L53-L73](https://github.com/healthchecks/healthchecks/blob/v4.4/templates/front/channels.html#L53-L73). The check's event log shows "Error: <message>" under the notification [event_summary.html L61-L64](https://github.com/healthchecks/healthchecks/blob/v4.4/templates/front/event_summary.html#L61-L64). The Integrations menu item gets a warning icon while any integration in the project has a `last_error` [hc/accounts/models.py L509-L517](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/accounts/models.py#L509-L517), [base.html L125-L131](https://github.com/healthchecks/healthchecks/blob/v4.4/templates/base.html#L125-L131). The next successful send clears `last_error`. None of the email functions in [hc/lib/emails.py](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/lib/emails.py) is about a failed delivery.

### 7. Hosted versus self-hosted

- The README calls healthchecks.io the hosted service of this project [README.md L22-L23](https://github.com/healthchecks/healthchecks/blob/v4.4/README.md#L22-L23). The commit the hosted site runs is not published, so it is **unverified**.
- **Discord.** A self-hosted instance needs `DISCORD_CLIENT_ID`, plus `DISCORD_CLIENT_SECRET` or `DISCORD_CLIENT_SECRET_FILE`, and a Discord application whose redirect URI is `SITE_ROOT/integrations/add_discord/` [self_hosted_configuration.md L244-L276](https://github.com/healthchecks/healthchecks/blob/v4.4/templates/docs/self_hosted_configuration.md#L244-L276), [README.md L408-L419](https://github.com/healthchecks/healthchecks/blob/v4.4/README.md#L408-L419). Without the client ID, the tile is hidden and the views answer 404 [hc/front/decorators.py L13-L24](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/front/decorators.py#L13-L24). On hosted, `GET https://healthchecks.io/integrations/add_discord/` answered 302 to the login page on 2026-10-17. The setting check runs before the login check [discord/views.py L45-L46](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/discord/views.py#L45-L46), so the client ID is set there.
- **Slack.** Works without a client ID through the hand-entered URL. With `SLACK_CLIENT_ID` and `SLACK_CLIENT_SECRET`, the Integrations page switches to the OAuth button. The notification body is the same either way [README.md L380-L406](https://github.com/healthchecks/healthchecks/blob/v4.4/README.md#L380-L406), [self_hosted_configuration.md L1066-L1074](https://github.com/healthchecks/healthchecks/blob/v4.4/templates/docs/self_hosted_configuration.md#L1066-L1074). On hosted, `GET /integrations/add_slack/` answered 200. That view requires `SLACK_CLIENT_ID` [slack/views.py L49-L53](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/slack/views.py#L49-L53), so hosted shows the button flow.
- **Defaults.** Webhooks, Slack, Mattermost, Rocket.Chat, Teams, PagerTree, Spike, Splunk On-Call and Zulip are on by default, each behind an `*_ENABLED` setting. Apprise and Shell are off by default [hc/settings.py L356-L446](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/settings.py#L356-L446). On hosted, the Mattermost and Rocket.Chat help pages answered 200, and those views require their `*_ENABLED` settings [mattermost/views.py L16-L18](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/mattermost/views.py#L16-L18), [rocketchat/views.py L16-L18](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/integrations/rocketchat/views.py#L16-L18). The [hosted landing page](https://healthchecks.io/) shows icons for webhook, discord, slack, mattermost, rocketchat, msteams, googlechat, ntfy, gotify, pagertree, spike, victorops, zulip and others, and none for Apprise or Shell.
- **Private addresses.** `INTEGRATIONS_ALLOW_PRIVATE_IPS` defaults to off and applies to every integration except Apprise [self_hosted_configuration.md L443-L468](https://github.com/healthchecks/healthchecks/blob/v4.4/templates/docs/self_hosted_configuration.md#L443-L468). The hosted value is **unverified**.
- **Instance settings in the payload.** `SITE_NAME` and `SITE_ROOT` vary by instance and appear in `username`, the cloaked links, and `client_url` [hc/settings.py L266-L270](https://github.com/healthchecks/healthchecks/blob/v4.4/hc/settings.py#L266-L270). The same check would show a different `title_link` host on a self-hosted instance.
- **Self-hosted only.** A superuser can edit a channel's stored `value` in the Django admin, as noted in question 1. `sendalerts --num-workers` defaults to 1.

## NF/Tickets

Read from NF/Tickets 2.12.2, version_id 2120270, on the local dev stack on 2026-10-17. Paths are relative to `app/src/` and line numbers are against 2.12.2. I queried no database, so every default below is what the install code ships. The production board may have changed any of them in the ACP.

### Replying

`NF\Tickets\Service\Ticket\Replier` takes the ticket in its constructor and stamps the current visitor on the new message as its author. It has no public setter for a member author, only `setGuestUser()`. `addons/NF/Tickets/Service/Ticket/Replier.php:59-65`, `:104-124`. A reply as the opener therefore means building the service inside `\XF::asVisitor($opener, ...)`. The vendor's email-to-ticket processor does the same for a sender it matched to a member. `addons/NF/Tickets/EmailTickets/Processor.php:160-174`, `addons/NF/Tickets/EmailTickets/Processor22.php:237-249`. The constructor throws a `LogicException` when the ticket's category no longer exists. `addons/NF/Tickets/Service/Ticket/Replier.php:174-184`.

A caller sets the text with `setMessage($text, $format = true)`. It runs through NF's message preparer, which applies the `nfTicketsMaxMessageLength` limit, 10000 characters by default, and XF's validity checks. `addons/NF/Tickets/Service/Ticket/Replier.php:191-194`, `addons/NF/Tickets/Service/Message/Preparer.php:190-229`, `:451-466`, `addons/NF/Tickets/_data/options.xml:62-66`. The other setters are `setAttachmentHash`, `setInternal` for a staff-only note, `setAnonymize`, `setUserAgent`, `selfAssign`, `setSkipWorkflows` and `logIp`. `addons/NF/Tickets/Service/Ticket/Replier.php:129-220`. Then come `validate($errors)`, `save()` and `sendNotifications()`. `save()` calls `validate()` itself and throws a `LogicException` on any error, which is why the email processor never calls `validate()`. `XF/Service/ValidateAndSavableTrait.php:31-43`. `checkForSpam()` is a separate call. The public and API controllers make it and the email processor does not. `addons/NF/Tickets/Service/Ticket/Replier.php:222-229`.

`save()` opens a transaction, locks the ticket row with `FOR UPDATE`, sets the message position, saves the message, attaches files, writes the IP log, commits, and then queues the ticket's workflows. `addons/NF/Tickets/Service/Ticket/Replier.php:289-319`, `addons/NF/Tickets/Service/Message/Preparer.php:643-675`. It throws a `LogicException` if the message date is earlier than the ticket's last message date. `addons/NF/Tickets/Service/Ticket/Replier.php:324-331`. The ticket changes through the message's own save. `Message::_postSave()` calls `Ticket::messageAdded()` and saves the ticket, and skips both for an internal message. `addons/NF/Tickets/Entity/Message.php:814-839`.

The service runs no ticket permission method. Its validation covers a guest's username and the message entity's own errors. It also refuses a public reply that quotes an internal message, a rule that `nfTicketsRejectInternalQuotesInPublicReplies` turns on by default. `addons/NF/Tickets/Service/Ticket/Replier.php:242-283`, `addons/NF/Tickets/_data/options.xml:68-71`. Image and media BB code follow the visitor's global `ticket.image` and `ticket.media` permissions. `addons/NF/Tickets/Entity/Message.php:604-611`. Checking `Ticket::canView()` and `Ticket::canReply()` is the caller's job. The public controller does both, then spam, validate, a flood check, save, notifications, auto-watch and mark-read. `addons/NF/Tickets/Pub/Controller/Ticket.php:876-925`, `:1202-1238`. The API controller checks `canReply()` and spam only when the API is checking permissions. `addons/NF/Tickets/Api/Controller/TicketMessages.php:35-68`. The email processor checks neither and requires only that the sender is trusted. `addons/NF/Tickets/EmailTickets/Processor.php:160-176`.

`canReply()` refuses a locked ticket unless the visitor holds `lockUnlockTicket`, refuses when the category's `allow_opening` is off, and otherwise needs the category permission `postMessage`. It does not look at the status. `addons/NF/Tickets/Entity/Ticket.php:743-757`. `canView()` for the ticket's own user needs the category's `view` and `viewSelf` permissions. A moderated or shadow-banned ticket stays visible to its owner. `addons/NF/Tickets/Entity/Ticket.php:216-261`.

So the ticket's creator can reply as a non-staff user under `\XF::asVisitor`. Nothing in the service or in `canReply()` tests for staff or ownership. Whether user 598 holds `postMessage`, `view` and `viewSelf` in a given category is permission data in the database, which I did not read.

A reply by the creator can change the status. `Ticket::messageAdded()` reads the current status's `status_on_staff_action` or `status_on_user_action` and moves the ticket there when that value is set, differs from the current status, and names a status that exists and is active. `addons/NF/Tickets/Entity/Ticket.php:1176-1198`. Staff here means the author's XF `is_moderator` or `is_admin` flag, and ticket permissions play no part. `addons/NF/Tickets/Entity/Message.php:462-465`. The first message is exempt, and so is a ticket with the `skip_status_on_action` option set. With the shipped statuses, any reply moves a Pending or Resolved ticket to Open and leaves an Open ticket alone, as the next section shows. No shipped status means "waiting for staff". The public reply form sets `skip_status_on_action` and writes `status_id` on the ticket before the save when a user who may manage tickets picks a status with the reply. `addons/NF/Tickets/Pub/Controller/Ticket.php:968-982`.

`Replier::setIsAutomated()` is `logIp(false)` plus `setPerformValidations(false)`, the same shape as the Creator's. `addons/NF/Tickets/Service/Ticket/Replier.php:163-167`, `addons/NF/Tickets/Service/Ticket/Creator.php:300-304`. With validations off, `setMessage()` skips the length and validity checks, `finalSetup()` skips stamping `message_date`, and `_validate()` skips the guest-name check. `addons/NF/Tickets/Service/Ticket/Replier.php:191-194`, `:231-237`, `:254`. `setMessage()` reads the flag when it is called, so `setIsAutomated()` has to come first to affect it. The date skip does not cause the Creator's sorting problem. The message's `message_date` column defaults to `\XF::$time`, and the ticket's last message date comes from `messageAdded()`, not from `finalSetup()`. `addons/NF/Tickets/Entity/Message.php:981`, `addons/NF/Tickets/Entity/Ticket.php:1167-1174`, compare `addons/NF/Tickets/Service/Ticket/Creator.php:430-442`. The vendor's own automated reply, in the email processor, uses `logIp(false)` alone.

### The status model

A status is an `NF\Tickets\Entity\Status` row in `xf_nf_tickets_status`, and a ticket points at one through `status_id`. Its columns are `status_on_staff_action`, `status_on_user_action`, `include_in_counts`, `include_in_defaults`, `is_close`, `alert_on_transition`, `display_order`, `active` and `css_class`. Its title is the phrase `nf_tickets_status.<id>`. `addons/NF/Tickets/Entity/Status.php:39-47`, `:125-158`, `addons/NF/Tickets/Entity/Ticket.php:2021`. The ticket also carries `ticket_state`, with values open, pending, locked, assigned and resolved. That column is from the 1.x add-on. An upgrade step copied it into `status_id`, and no 2.x code outside `Legacy/` reads it. `addons/NF/Tickets/Entity/Ticket.php:2022-2025`, `addons/NF/Tickets/Setup.php:425-437`.

An install ships three statuses. `addons/NF/Tickets/Install/Data/MySql.php:755-761`, `:840-842`.

| ID | Title | On staff reply | On user reply | `is_close` | `include_in_counts` | `include_in_defaults` | `alert_on_transition` |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Open | none | none | 0 | 1 | 1 | 1 |
| 2 | Pending | 1, Open | 1, Open | 0 | 0 | 1 | 0 |
| 3 | Resolved | 1, Open | 1, Open | 1 | 0 | 0 | 1 |

The insert is `INSERT IGNORE`, so a board keeps its own rows across upgrades.

Four options name statuses, each with a shipped default. `nfTicketsDefaultStatus`, default 1, is the status the Creator gives a new ticket. `addons/NF/Tickets/_data/options.xml:32-36`, `addons/NF/Tickets/Service/Ticket/Creator.php:106`. `nftOpenStatus`, default 1, is the entity default and the status an undeleted ticket returns to. `nftResolvedStatus`, default 3, is what the self-resolve action sets. `nftDeletedStatus`, default 3, is what a soft delete sets unless the ticket is already resolved. `addons/NF/Tickets/_data/options.xml:89-93`, `:107-111`, `:139-143`, `addons/NF/Tickets/Entity/Ticket.php:1545-1558`, `:1660-1676`. Those three are system statuses and cannot be deleted. `addons/NF/Tickets/Entity/Status.php:54-61`.

The vendor phrases `is_close` as "Tickets with this status are considered 'closed'". `addons/NF/Tickets/_data/phrases.xml:342`. Little code reads it. `canResolve()` returns false on a closed ticket. The job that writes ban, approval and rejection entries into a user's tickets touches only tickets in active statuses that are not closed. `addons/NF/Tickets/Entity/Ticket.php:642-645`, `addons/NF/Tickets/Job/UpdateTicketsOnUserStatusChangeJob.php:12-21`. Closed does not block replies. Locking is a separate flag, `ticket_locked`, and that one blocks replies from anyone without `lockUnlockTicket`. `addons/NF/Tickets/Entity/Ticket.php:2026`, `:743-748`. The other flags and the seeded queue views are what take a Resolved ticket out of sight. `include_in_counts` drives the moderator-bar count and the category's open count. `include_in_defaults` drives the category's default list. `addons/NF/Tickets/Repository/Ticket.php:268-284`, `addons/NF/Tickets/Entity/Category.php:413-452`. The seeded views "open", "assigned to me" and "assigned to others" filter on status IDs 1 and 2, and "open" is the default view. `addons/NF/Tickets/Install/Data/MySql.php:789-809`, `addons/NF/Tickets/_data/options.xml:84-88`. `include_in_defaults` carries a 2.12.1 phrase version, so 2.11.1 probably lacks it. `addons/NF/Tickets/_data/phrases.xml:322`.

The vendor changes status in these places.

- `Service\Ticket\Editor::setStatus($statusId, $alert = false)`, then `validate()` and `save()`. The service checks no permission. It validates the status exists and is active only when the visitor passes `canUpdateStatus()`. `addons/NF/Tickets/Service/Ticket/Editor.php:100-108`, `:227-235`. `setIsAutomated()` on the Editor turns validations and the alert off. `addons/NF/Tickets/Service/Ticket/Editor.php:70-74`. The public edit form, the API and the approval-queue actions use it. `addons/NF/Tickets/Pub/Controller/Ticket.php:1488-1496`, `:2285-2298`, `addons/NF/Tickets/Api/Controller/Ticket.php:119-125`.
- Writing `status_id` on the entity and saving. The self-resolve action does this. `addons/NF/Tickets/Pub/Controller/Ticket.php:1359-1389`.
- `Ticket::messageAdded()` on a reply, described under Replying.
- The workflow action `SetStatus`, which writes `status_id` and saves with workflows skipped. `addons/NF/Tickets/Workflow/Action/SetStatus.php:19-24`, `addons/NF/Tickets/Service/Workflow/Processor.php:268-276`.
- `fastUpdate('status_id', ...)` on soft delete and undelete. `addons/NF/Tickets/Entity/Ticket.php:1552-1557`, `:1670-1675`.

Every status change on an existing ticket writes a `status_changed` row to the ticket's activity log. `addons/NF/Tickets/Entity/Ticket.php:1444-1474`. No 2.x code writes `last_status_change_date` except a best-effort fill in `rebuildLastMessageInfo()`. `addons/NF/Tickets/Entity/Ticket.php:1134-1137`.

`canUpdateStatus()` is the category permission `manageAnyTicket`, a moderator permission. `addons/NF/Tickets/Entity/Ticket.php:602-605`, `addons/NF/Tickets/_data/permissions.xml:38`. A non-staff creator can close their own ticket only through the resolve action. `canResolve()` allows it when the status is not already closed and the visitor either holds `manageAnyTicket` or is the ticket's user with the user-level permission `resolveSelf`. `addons/NF/Tickets/Entity/Ticket.php:642-645`, `addons/NF/Tickets/_data/permissions.xml:48`. The action sets `nftResolvedStatus`, writes straight to the entity and sends no alert. `addons/NF/Tickets/Pub/Controller/Ticket.php:1374-1377`. The moderator permission `resolve`, phrased "Close tickets by anyone", is defined, but no 2.x PHP checks it. `addons/NF/Tickets/_data/permissions.xml:47`. No install step grants any ticket permission, so which groups hold `resolveSelf` or `postMessage` is the board's own configuration. `addons/NF/Tickets/Setup.php:49-130`. A non-staff creator cannot pick a status in the UI and has no reopen control. Lock and unlock need `lockUnlockTicket`. `addons/NF/Tickets/Entity/Ticket.php:592-595`, `addons/NF/Tickets/Pub/Controller/Ticket.php:1919-1946`.

On options, NF/Tickets has no "reopen on reply" switch and no "closed tickets accept replies" switch. Each status's `status_on_user_action` and `status_on_staff_action` play the reopen role, and nothing refuses a reply by status. `addons/NF/Tickets/_data/options.xml`, all 29 options. NF/Tickets ships no auto-close cron. Its cron list has a workflow runner every 15 minutes, and `Cron\Escalation::run()` and `Cron\RuleActions::run()` have empty bodies. `addons/NF/Tickets/_data/cron.xml:3-9`, `addons/NF/Tickets/Cron/Escalation.php`, `addons/NF/Tickets/Cron/RuleActions.php`. Auto-closing is something an admin builds as a workflow with a `SetStatus` action. The install seeds no workflow. A workflow runs on activity only, once per ticket, or while matching. The default is once per ticket, recorded in `xf_nf_tickets_workflow_trigger_log`, so a once-per-ticket close does not fire again on a reopened ticket. `addons/NF/Tickets/Entity/Workflow.php:37-39`, `:207-213`, `addons/NF/Tickets/Service/Workflow/Processor.php:165-174`, `:298-320`. Execution modes date from 2.11.0. `addons/NF/Tickets/_data/phrases.xml:703-709`.

### Reopening

There is no reopen action, service or permission. A search for "reopen" finds only an unimplemented statistic name. `addons/NF/Tickets/Pub/Controller/Insights.php:20`. A closed ticket takes a reply from its creator whenever `canReply()` passes, and `canReply()` ignores status. `addons/NF/Tickets/Entity/Ticket.php:743-757`. The status then follows the closed status's `status_on_user_action`. With the shipped rows, Resolved goes to Open. `addons/NF/Tickets/Entity/Ticket.php:1176-1198`, `addons/NF/Tickets/Install/Data/MySql.php:761`. A locked ticket refuses the reply unless the replier holds `lockUnlockTicket`, but the Replier service itself does not check the lock. A caller that skips `canReply()` can reply to a locked ticket. Setting `status_id` through the Editor, or directly on the entity, also reopens.

### Locating an existing ticket

Custom ticket fields. An admin defines fields in `xf_nf_tickets_ticket_field` and assigns them to categories through `xf_nf_tickets_category_field`. A ticket accepts only the fields in its category's `field_cache`. `addons/NF/Tickets/Install/Data/MySql.php:62-68`, `:315-342`, `addons/NF/Tickets/Entity/Ticket.php:2063-2071`. Values live in two places. The ticket's `custom_fields` column holds a JSON copy. `xf_nf_tickets_ticket_field_value` holds one row per field, keyed by `ticket_id` and `field_id`, with a second index on `field_id`. Its `field_value` column is an unindexed `mediumtext`. XF's `CustomFieldsHolder` behavior keeps the rows in step on every save and serializes array values. `addons/NF/Tickets/Install/Data/MySql.php:293`, `:347-354`, `XF/Behavior/CustomFieldsHolder.php:79-153`. A query by value is possible. The vendor's admin ticket searcher joins `CustomFields|<field_id>` and filters `field_value` by `=` or `LIKE`, and `CustomFields` is a relation on the ticket keyed by `field_id`. `addons/NF/Tickets/Searcher/Ticket.php:131-166`, `addons/NF/Tickets/Entity/Ticket.php:2172-2177`. The Creator's `setCustomFields()` and the Editor's take only fields the visitor may edit in the current mode. A ticket's own user gets mode `user`, and XF's `user_editable` of yes, once or never decides. `addons/NF/Tickets/Service/Ticket/Creator.php:381-395`, `addons/NF/Tickets/Service/Ticket/Editor.php:115-144`, `addons/NF/Tickets/Entity/Ticket.php:836-850`, `XF/CustomField/Definition.php:195-210`. A field can expire. A daily cron overwrites values older than the field's expiry with an "expired" phrase, and the default expiry is never. `addons/NF/Tickets/Entity/TicketField.php:110-118`, `addons/NF/Tickets/Repository/Ticket.php:576-620`, `addons/NF/Tickets/_data/cron.xml:7`. The field columns `usable_user_group_ids`, `viewable_user_group_ids` and `viewable_owner_user_group_ids` exist, but no NF/Tickets PHP reads them. `addons/NF/Tickets/Entity/TicketField.php:95-109`.

Tags. NF/Tickets keeps its own tag tables. Tickets do not use XF's core tagging, and no content type field registers the `Tag\Ticket` handler. `xf_nf_tickets_tag` holds `tag`, unique, and `tag_url`, unique. `xf_nf_tickets_tag_content` holds `ticket_id` and `tag_id`, unique per pair, with an index on `tag_id`. The ticket keeps a JSON cache in its `tags` column. `addons/NF/Tickets/Install/Data/MySql.php:571-595`, `addons/NF/Tickets/Entity/Ticket.php:2049`, `addons/NF/Tickets/_data/content_type_fields.xml`. A tag is lowercased and whitespace-collapsed, with leading and trailing punctuation trimmed, and must be 2 to 100 characters. A ticket holds at most 20 tags. `addons/NF/Tickets/Repository/TicketTag.php:20-38`, `addons/NF/Tickets/Service/Ticket/TagChanger.php:14`, `:130-144`. `TagChanger` has `setTags`, `addTags`, `removeTags` and `adjustTags`, checks no permission, and logs the change to the activity log. `addons/NF/Tickets/Service/Ticket/TagChanger.php:43-90`. The Creator's `setTags()` checks no permission either. The Editor applies tags only when the visitor passes `canEditTags()`, which is the moderator permission `tagTicket`. `addons/NF/Tickets/Service/Ticket/Creator.php:373-376`, `:533-538`, `addons/NF/Tickets/Service/Ticket/Editor.php:271-274`, `addons/NF/Tickets/Entity/Ticket.php:435-444`, `addons/NF/Tickets/_data/permissions.xml:49`. Ticket tags date from 2.11.0 Beta 7. `addons/NF/Tickets/_data/phrases.xml:878`.

Prefixes. `prefix_id` is an integer that points at an admin-defined prefix. `_preSave` resets it to 0 when the prefix is not valid for the category. The API list can filter on it. `addons/NF/Tickets/Entity/Ticket.php:2047`, `:1292-1298`, `addons/NF/Tickets/Api/ControllerPlugin/Ticket.php:19-23`.

Title. `title` is a required `varchar(150)`, and XF's censor runs on it at write time. Nothing indexes it. `addons/NF/Tickets/Entity/Ticket.php:1998-2002`, `addons/NF/Tickets/Install/Data/MySql.php:251`.

Ticket reference. `ticket_ref` gets an 8-character random uppercase string on insert, but only when it is empty, so a value set on the entity before the save survives. The column has a non-unique index and is `varchar(25)` in the install schema, while the entity allows 100. Apart from a `{id}` placeholder in canned responses, nothing in 2.x reads it. `addons/NF/Tickets/Entity/Ticket.php:1302-1308`, `:1997`, `addons/NF/Tickets/Repository/Ticket.php:29-37`, `addons/NF/Tickets/Install/Data/MySql.php:250`, `:298`, `addons/NF/Tickets/Repository/Response.php:100`.

Other columns. `xf_nf_tickets_ticket` has an index on `user_id` and `last_message_date`, so finding the opener's tickets newest first uses an index. `addons/NF/Tickets/Install/Data/MySql.php:304`. Each message has a free `user_agent` column, `varchar(255)` and unindexed, which `Creator::setUserAgent()` and `Replier::setUserAgent()` write. `addons/NF/Tickets/Install/Data/MySql.php:176`, `addons/NF/Tickets/Service/Ticket/Creator.php:314-317`. XF search indexes a ticket's title and first message, with category, owner, status and assignee as metadata. `addons/NF/Tickets/Search/Data/Ticket.php:26-61`.

### Merger

`Service\Ticket\Merger::merge($sources)` folds one or more source tickets into a target. It moves the sources' messages, activity log, watches, spam log, tags, participants, guest participants and read markers onto the target, rebuilds the target's counters, message positions and tag cache, can send the sources' owners an alert, then hard-deletes each source. `addons/NF/Tickets/Service/Ticket/Merger.php:59-127`, `:131-246`. It leaves no redirect, so a source's URL stops working. It does not move custom field values, which go with the deleted source, and it leaves the target's status unchanged. `addons/NF/Tickets/Entity/Ticket.php:1839`. Its only caller is the inline moderation action, gated by `canMerge()`, which is `manageAnyTicket`. `addons/NF/Tickets/InlineMod/Ticket/Merge.php:46-70`, `addons/NF/Tickets/Entity/Ticket.php:467-470`.

### Notifications

On a reply, `Replier::sendNotifications()` runs the message notifier with action `reply`, but only for a visible message. It sends inline for up to 3 seconds and queues the rest. It also emails a guest owner, which does not apply to a member-owned ticket. `addons/NF/Tickets/Service/Ticket/Replier.php:345-372`. The message notifier loads the quote, mention, queue-watch, category-watch and ticket-watch notifiers. Ticket-watch runs only when the new message is the ticket's last. `addons/NF/Tickets/Service/Message/Notifier.php:71-87`. Category watchers hear about a reply only if their watch is set to notify on messages. A watch set to new tickets only hears nothing. Queue watchers follow the same rule. `addons/NF/Tickets/Notifier/Message/CategoryWatch.php:39-46`, `addons/NF/Tickets/Notifier/Message/QueueWatch.php:26-33`. Ticket watchers always get an alert and get an email if they subscribed. `addons/NF/Tickets/Notifier/Message/TicketWatch.php:25-58`. The message's author is never notified. `addons/NF/Tickets/Notifier/Message/AbstractWatch.php:81-96`. A reply does not email the category's or the priority's notify addresses. Only the Creator does that. `addons/NF/Tickets/Service/Ticket/Creator.php:609-640`. No code notifies the assignee as assignee. An assignee hears a reply only through a watch. The ticket's own user watches only if a controller auto-watched them, and the services never do. `addons/NF/Tickets/Repository/TicketWatch.php:27-61`, `addons/NF/Tickets/Pub/Controller/Ticket.php:1210-1230`. On XF 2.3, ticket and message saves also fire XF outbound webhooks, except for internal messages. `addons/NF/Tickets/Entity/Ticket.php:2078-2081`, `addons/NF/Tickets/Entity/Message.php:679-685`, `:1017-1020`.

On a status change, `Editor::save()` sends one alert, `nf_tickets_status`, to the ticket's user, and only when the caller passed `$alert = true`, the actor is not the ticket's user, and the ticket is visible. `addons/NF/Tickets/Service/Ticket/Editor.php:283-287`, `addons/NF/Tickets/Entity/Ticket.php:509-517`, `addons/NF/Tickets/Repository/Ticket.php:227-262`. The UI passes the status's `alert_on_transition` as that flag. `addons/NF/Tickets/Pub/Controller/Ticket.php:1494`. A status change made by a reply, by the resolve action or by a workflow sends no alert. Nothing alerts staff on a status change. Every path writes the activity log entry. Workflows can add `SendAlert` and `SendEmail` actions. `addons/NF/Tickets/Workflow/Action/`.

A reply bumps the ticket. `messageAdded()` sets `last_message_date`, `last_message_id` and the last poster, and raises `reply_count`. `addons/NF/Tickets/Entity/Ticket.php:1156-1174`. Ticket lists sort by `last_message_date` by default, and the queue sort option defaults to the same. `addons/NF/Tickets/_data/options.xml:49-56`, `:122-127`, `addons/NF/Tickets/Finder/Ticket.php:307-315`. A ticket is unread for a user whose read date is older than `last_message_date`, so a reply makes it unread for everyone but the replier, and the public and API controllers mark it read for the replier. `addons/NF/Tickets/Entity/Ticket.php:801-818`, `addons/NF/Tickets/Api/Controller/TicketMessages.php:100-104`. The category's last-ticket fields move to the replied ticket. When the reply changes the status, the category's open and default-list counts and the moderator-bar count change with it. `addons/NF/Tickets/Entity/Category.php:413-452`, `addons/NF/Tickets/Entity/Ticket.php:1885-1897`. A reply does not update `last_modified_date`. I found no 2.x writer of it outside `rebuildLastMessageInfo()`, yet the watched-tickets list sorts by it. `addons/NF/Tickets/Entity/Ticket.php:1123-1131`, `addons/NF/Tickets/Repository/Ticket.php:97`.

### The REST API

The API has controllers for both. `Api\Controller\TicketMessages::actionPost` takes `ticket_id`, `message` and `attachment_key`. It requires a registered user, checks `canView()`, checks `canReply()` and spam only when the API is checking permissions, then calls `setMessage`, `validate`, `save` and `sendNotifications`, and marks the ticket read. `addons/NF/Tickets/Api/Controller/TicketMessages.php:25-105`. `Api\Controller\Ticket::actionPost` updates `title`, `prefix_id`, `priority`, `status_id`, `ticket_locked` and a subset of `custom_fields` through the Editor. It checks `canEdit()` first and then each field's own permission, with `canUpdateStatus()` for the status, and it calls `setStatus()` without the alert flag. `addons/NF/Tickets/Api/Controller/Ticket.php:40-141`. `Api\Controller\Tickets` lists tickets filtered by category, status and prefix, and creates them through the Creator. `addons/NF/Tickets/Api/Controller/Tickets.php:24-119`.

The 2.12.2 `routes.xml` registers four API routes: `ticket-categories`, `ticket-categories/-`, `ticket-messages/-` with a message ID, and `tickets`. None maps to `Api\Controller\Ticket` or `Api\Controller\TicketMessages`. `addons/NF/Tickets/_data/routes.xml:20-23`. XF core pairs each such controller with a `-` route, as in `posts/-` and `threads/-`. `addons/XF/_data/routes.xml:135-146`. On my reading, then, the shipped add-on gives the reply and update endpoints no URL. `api_scopes.xml` is empty, so the `nf_ticket:read` and `nf_ticket:write` scopes those controllers assert pass only for a key that allows all scopes. `addons/NF/Tickets/_data/api_scopes.xml`, `XF/Entity/ApiKey.php:51-59`, `XF/Api/Controller/AbstractController.php:108-123`. I did not check the live route or scope tables, which are in the database. The routing may differ in 2.11.1.


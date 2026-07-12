# ADR-0001: Notify clerks with a direct alert, not an in-post @mention

- **Status:** Accepted
- **Date:** 2026-07-11
- **Issues:** design decision during triage/grilling of #53

## Context

The forum ticket behind #53 asked the S6 bot to `@`-mention every Processing
Clerk in a reply on a stale enlistment thread. Grilling that design against the
prod mirror surfaced two problems with tagging inside the post:

1. The reminder posts into the enlistee's own application thread, which the
   applicant reads. Tagging the clerk set (nine holders today) clutters a new
   recruit's thread, and any staff-facing "pick this up, it's overdue" wording
   ends up visible to the applicant.
2. The bot writes its post by saving the `Post` entity directly — the pattern
   `SteamChecker` established — which bypasses XenForo's message Preparer. So
   mentions would not register or fire an alert without re-implementing mention
   handling by hand on that path.

XenForo has a first-class alert API (`UserAlertRepository::alert`) that inserts
a notification straight into a member's bell, independent of post mentions.
NF/Calendar already uses it in this install — custom alert actions on core
content types, e.g. `alert_user_event_move` — which proves the pattern works
without defining a new content type.

## Decision

The reminder notifies each current Processing Clerk with a **direct XenForo
alert** (content type `thread`, custom action `enlistment_reminder`, tied to the
add-on through `dependsOnAddOnId` so the alerts are cleaned up on uninstall),
and posts **one brief, neutral note** in the thread as an audit trail and a
recency bump. Clerks are not `@`-mentioned in the post body.

This splits the two audiences: the pointed wording lives in the alert, which
only clerks see, while the visible in-thread note stays applicant-safe. It also
sidesteps mention registration on the direct-save path, and it closes a
suppression path — a recruiter's reply or a thread pin cannot bury an
un-actioned application, because the signal is the alert, not a post competing
for attention in the thread.

## Consequences

- The add-on ships a custom alert action with its template
  (`public:alert_thread_enlistment_reminder`) and an alert opt-out entry, rather
  than leaning on the mention pipeline. Modest extra plumbing.
- Reversing to in-post mentions later would mean re-solving mention
  registration on the direct-save path. The alert route is the more durable one.

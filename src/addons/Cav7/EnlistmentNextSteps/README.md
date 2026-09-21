# 7Cav - Enlistment Next Steps

XenForo add-on for the [7th Cavalry](https://7cav.us) that tells a recruit what
happens next the moment their Enlistment application is accepted.

Without this add-on, a recruit who submits the Enlistment application
([OzzModz] Advanced Forms form 1) sees the form's own toast and lands on their
own application thread. Nothing tells them to expect a Welcome Letter, or to
come back for it. With it installed, the submission lands on a next-steps page
instead: a title, RRD's paragraph on what happens next, the enlistment process
image, and one "I Understand" button that takes them to that same thread. No
toast precedes it and no private message is sent.

Which forms show the page is an option. Every other Advanced Forms form, and
every submission that produced no thread, keeps the vendor's own reply. The
add-on changes nothing about the vendor's private message, email, thread,
prefix or watch behaviour, so the queue and the clerks see the same threads
they see today.

The terms this README uses (next-steps page, triggering form, vendor reply) are
defined in [CONTEXT.md](CONTEXT.md).

## Requirements

- XenForo 2.3.0+
- [OzzModz] Advanced Forms (`Snog/Forms`) 2.2.6 RC3+ (declared in
  `addon.json`; the floor is the version the code was read against, in the
  vendor's own `version_id` numbering)

## Installation

1. Copy `src/addons/Cav7/EnlistmentNextSteps` into your XenForo installation
   at the same path, or install the release zip through the admin panel. The
   release zip also places the enlistment process image at
   `styles/default/cav7/enlistment-next-steps/enlistment-process.png` under
   the web root; a copy from the repo needs the contents of `_files/` copied
   there by hand.
2. Install it: `php cmd.php xf-addon:install Cav7/EnlistmentNextSteps`.

The add-on refuses to install when Advanced Forms is absent or older than the
floor above.

Run the tests with `tools/run-tests.sh EnlistmentNextSteps`. They need only
`php`. Everything that needs a live XenForo is verified by hand on a dev stack
before a release, per [CONTRIBUTING.md](../../../../CONTRIBUTING.md).

## Configuration

One option, under **Setup > Options > Enlistment Next Steps**:

| Option | Description |
| --- | --- |
| Triggering form IDs | Comma-separated Advanced Forms form IDs whose accepted submission lands on the next-steps page (default 1, the Enlistment application) |

An empty option shows the page on no form, never on every form. An entry that
is not a form ID is ignored and the entries beside it still count, so one typo
cannot switch the page off for the Enlistment form. To give returning members
the page too, add the Re-Enlistment form's ID.

The wording is three phrases, editable under **Appearance > Phrases** without
a release:

| Phrase | Default |
| --- | --- |
| `cav7_ens_next_steps_title` | Application Received |
| `cav7_ens_next_steps_body` | RRD's paragraph, rendered as HTML, so a second paragraph or a link is a phrase edit |
| `cav7_ens_next_steps_button` | I Understand |

All triggering forms share the three phrases.

## What the page does, and does not do

- The page opens for anyone who can view the thread it belongs to, and refuses
  everyone else the way the thread itself would. A recruit can reload or
  bookmark it as long as they can still view their thread.
- The button links to the recruit's own thread. Nothing records the press.
- A triggering form whose submission produced no thread falls back to the
  vendor reply, since there is no thread for the page to lead to.

## License

[MIT](LICENSE) © 2026 7Cav

## Provenance

Created in this repository for issue #291. No upstream import.

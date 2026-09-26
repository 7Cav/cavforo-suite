# 7Cav - Forms User Question

XenForo add-on for the [7th Cavalry](https://7cav.us) that adds two question
types to [OzzModz] Advanced Forms: "Forum user" and "Forum users". Their
answers must name forum users.

Without it, a form that asks who a request is about uses a Text question. A
regex can force the "Doe.J" shape but can't check that the name belongs to
anyone, so a typo or an old name goes straight into the thread. The two
question types check every name against the forum's accounts before the form
submits.

The terms this README uses (forum user, forum user question, user picker,
filer) are defined in [CONTEXT.md](CONTEXT.md).

## Requirements

- XenForo 2.3.0+
- [OzzModz] Advanced Forms (`Snog/Forms`) 2.2.6 RC3+ (declared in
  `addon.json`; the floor is the version the code was read against, in the
  vendor's own `version_id` numbering)

## Installation

1. Copy `src/addons/Cav7/FormsUserQuestion` into your XenForo installation at
   the same path, or install the release zip through the admin panel.
2. Install it: `php cmd.php xf-addon:install Cav7/FormsUserQuestion`.

The add-on refuses to install when Advanced Forms is absent or older than the
floor above.

Run the tests with `tools/run-tests.sh FormsUserQuestion`. They need only
`php`. Everything that needs a live XenForo is verified by hand on a dev stack
before a release, per [CONTRIBUTING.md](../../../../CONTRIBUTING.md).

## The two question types

| Type | Takes |
| --- | --- |
| Forum user | One forum user |
| Forum users | Several forum users, names separated by commas |

Both sit next to Text in the add-question chooser. To move an existing Text
question over, open it and use **Change type**. Its past answers stay in the
form log as they were. A regex or length limit it kept from its Text days no
longer applies.

Copying a form, or exporting it and importing it on another board, keeps both
question types.

## Setting up a question

In the question editor, both types have a default answer and a placeholder,
beside the settings every question has. They have no regex, length limits or
expected answers. A default answer of `{username}` fills the box with the
filer's own name, so a filer doesn't have to enter themselves on their own
request. **Read-only** works as it does for Text. It greys the box only when
the question has a default answer, and the form doesn't check it on submit, so
a filer who edits the page in the browser can still send another name.

A "Forum user" answer can fill a slot such as `{A2}` in the form's thread
title, as a Text answer did, so a title keeps working after its question is
converted. A "Forum users" question can't go in a title, like Advanced Forms'
other multi-answer types, and saving the form refuses a title that uses one.
The change-type screen doesn't run that check. A title whose question you
convert to "Forum users" lists every name until the form's next save refuses
it.

Don't choose a "Forum user" question as the form's confirmation-email
question, even though Advanced Forms lists it there. The answer is a username,
not an email address, so no confirmation email goes out.

## Filling in the form

The filer answers in a user picker. After the filer types two characters, it
suggests up to 10 forum users whose username starts with them. Unlike
XenForo's own name lookups, it also suggests forum users who haven't been
active for months. It leaves out banned and unconfirmed accounts, but a filer
can still type one in full. Guests and registrants who haven't confirmed their
account get suggestions too.

When the form is submitted, the add-on looks up each name among the forum's
accounts:

- Letter case doesn't matter. `doe.j` finds the account `Doe.J`.
- A banned or unconfirmed account counts like any other.
- A name that belongs to no forum user refuses the answer. So does a "Forum
  user" answer that names two different forum users. The filer sees the
  question's error message and the form doesn't submit. An optional question
  has no error message of its own, so it shows a generic one that asks the
  filer to check the names. It doesn't say which name failed.
- An optional question left empty submits. A required question must name at
  least one forum user.

## What a submission writes

The thread post links each forum user named to their profile, and so do a
reply to an existing thread and a conversation. The thread title, the answer
stored in the form log and the notification email give plain usernames.

The add-on writes every name the way the account spells it, so `doe.j` becomes
`Doe.J`. A forum user named twice appears once, and the names keep the order
the filer gave them.

The usernames in the title, the form log and the email are a snapshot taken at
submission, and a later rename doesn't change them. The link in the post points
at the account, so it still reaches the right profile after a rename.

Naming a forum user doesn't alert them.

## Disabling or uninstalling

Convert every forum user question back to Text before you disable or
uninstall the add-on. Advanced Forms has no fallback for a question type it
doesn't know. Those questions stop appearing on their forms, and a required
one then blocks every submission of its form, since the filer has no box to
answer it in. A required question that an earlier answer reveals blocks only
the submissions that give that answer.

## License

[MIT](LICENSE) © 2026 7Cav

## Provenance

Created in this repository for issue #310. No upstream import.

# 7Cav - Force Avatar By Role

A XenForo 2.2+ add-on that replaces a member's avatar with a rank image based on
their user group membership. It maps the user's primary and secondary group IDs
to a rank avatar and supports modifier variants for mourning, recruiter, and
retired states. If no group matches, it falls back to a default avatar.

## How it works

The avatar override runs through a `templater_setup` code event listener
(`Infrastructure/Xenforo/Template/TemplateOverrides.php`). The listener replaces
XenForo's `avatar` template function so that wherever an avatar renders, the
markup is rewritten to point at the rank image for the member's groups. Group to
avatar mapping lives in `Application/Config/AvatarConfig.php`, and the mourning
window is configured in `Application/Config/MourningConfig.php`.

`Setup.php` creates a `cav7_opt_out_mourning` user field and, on install, copies
the rank images from `Resources/images/` into
`styles/default/xenforo/avatars/`. Uninstall removes both. The images must ship
with the add-on, so they live under `Resources/images/`.

## Requirements

- XenForo 2.2+
- PHP 8.0+

## Installation

1. Copy `src/addons/Cav7/AvatarByRole` into your XenForo installation at the same path.
2. Install the addon: `php cmd.php xf-addon:install Cav7/AvatarByRole`.
3. The installer copies the rank images into `styles/default/xenforo/avatars/`.

## Configuration

`Application/Config/AvatarConfig.php` maps each user group ID to a rank label and
avatar path:

```php
new AvatarGroup(102, 'PVT', '/styles/default/xenforo/avatars/PVT_Avatar.png'),
new AvatarGroup(103, 'PFC', '/styles/default/xenforo/avatars/PFC_Avatar.png'),
```

Add a rank by adding another `AvatarGroup` entry with the matching group ID. The
group IDs and rank taxonomy are hard-coded here; this is a candidate for the
future `Cav7/Core` roster lookup (noted in issue #9, not extracted here).

## Notes

- **Blocking avatar uploads.** Because every avatar is forced to the rank image
  at render time, an avatar a member uploads never shows. To stop members from
  storing dead files and staring at an upload control that does nothing, the
  add-on extends `XF\Entity\User` and returns `false` from `canUploadAvatar()`.
  XenForo gates the avatar editor, the `account/avatar` action, and the API
  avatar endpoint on that one method, so the editor is hidden and uploads are
  refused everywhere. The extension is registered in `_data/class_extensions.xml`.
  An earlier version of the add-on instead shipped three extensions (overrides of
  `XF\Service\User\Avatar`, `XF\Entity\User`, and `XF\Pub\Controller\Account`)
  declared through a non-standard `extensions` key that XenForo ignores, so they
  never ran. Two of them targeted classes XenForo 2.3 renamed or merged away
  (`Avatar` became `AvatarService`; the per-action avatar handlers folded into a
  single `AccountController::actionAvatar()`), so they were dropped in favour of
  the single `canUploadAvatar()` override, which covers the same ground. See
  issue #20.
- **Version.** The source repository's last release tag was `v1.0.3`; the bump to
  1.0.4 was committed there but never tagged. The monorepo released it as
  `AvatarByRole-v1.0.4`.

## License

MIT

## Provenance

Imported from https://github.com/7Cav/avatar-xenforo-plugin at commit a91bde2527929a2ecbe2492c576896c2fdd5715d.

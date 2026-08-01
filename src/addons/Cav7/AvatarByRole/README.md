# 7Cav - Force Avatar By Role

A XenForo 2.2+ add-on that replaces a member's avatar with a rank image based on
their user group membership. It maps the user's primary and secondary group IDs
to a rank avatar and supports modifier variants for mourning, recruiter, and
retired states. If no group matches, it falls back to a default avatar.

The add-on replaces XenForo's `avatar` template function, so every place an
avatar renders shows the rank image and an avatar a member uploads never shows.
Because of that, uploads are refused outright: the avatar editor is hidden, and
the `account/avatar` action and the API avatar endpoint both decline.

## Requirements

- XenForo 2.2+
- PHP 8.0+

## Installation

1. Copy `src/addons/Cav7/AvatarByRole` into your XenForo installation at the same path.
2. Install the addon: `php cmd.php xf-addon:install Cav7/AvatarByRole`.
3. The installer copies the rank images into `styles/default/xenforo/avatars/`.

Installing also creates a `cav7_opt_out_mourning` user field. Uninstalling
removes the field and the copied images.

## Configuration

`Application/Config/AvatarConfig.php` maps each user group ID to a rank label and
avatar path:

```php
new AvatarGroup(102, 'PVT', '/styles/default/xenforo/avatars/PVT_Avatar.png'),
new AvatarGroup(103, 'PFC', '/styles/default/xenforo/avatars/PFC_Avatar.png'),
```

Add a rank by adding another `AvatarGroup` entry with the matching group ID. The
group IDs and rank taxonomy are hard-coded here. The mourning window is
configured in `Application/Config/MourningConfig.php`.

## License

MIT

## Provenance

Imported from https://github.com/7Cav/avatar-xenforo-plugin at commit a91bde2527929a2ecbe2492c576896c2fdd5715d.

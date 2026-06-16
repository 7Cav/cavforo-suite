# 7Cav Avatar Role Addon for XenForo 2.2+

A XenForo 2 add-on that dynamically overrides user avatars based on group membership, including support for special modifiers such as `reservist`, `deceased`, and `retired`. Fully modular and extensible, built for maintainability and testability in large-scale communities.

---

## 🚀 Features

- Override avatars based on primary and secondary user group IDs
- Fallback to default avatar if no match
- Config-driven and extensible via PHP config class
- Clean architecture following DDD and SOLID principles
- Installer copies required images into style directory

---

## 📁 Directory Structure

```text
AvatarByRole/
├── Application/
│   ├── Config/               # Group <-> avatar config (DTO-based)
│   └── Mapper/               # Group ID → avatar path logic
│
├── Infrastructure/
│   └── XenForo/
│       └── TemplateOverrides.php  # Hook into XF templater
│
├── Resources/
│   └── images/               # Avatar PNGs copied on install
│
├── Setup/
│   └── Setup.php             # Copies images to XF styles dir
│
├── _data/                    # Dev-mode code_event_listeners.xml
├── addon.json               # XF2 metadata
```

---

## 🛠 Installation

### Step 1: Downloading Xenforo
First you need a Xenforo Licence and you need to download the package from the official xenforo website.
Make sure you download all elements. By default, the xenforo website only gives you an upgrade package.
So make sure the checkbox is turned off.

After you have downloaded the zip file you need to create a new directory in your folder structure called "xenforo".
Drag the contents of the "upload" directory from the zip file in your new "xenforo" folder.

This folder is added in your .gitignore, so it will not be committed, and you can safely work on your addons.

### Step 2: Starting the Containers

Start the docker container by running "docker-compose up -d" in your command line.
Wait until it is running. It might take a while when executing it the first time.

### Step 3: Configuring Xenforo

Navigate to "localhost" in your browser. It starts on port 80 by default, so you don't need to provide a port.
Begin the installation. For the database enter these values:

```
MySQL server: xenforo-db
MySQL Port: 3306
MySQL username: xenforo
MySQL password: password
MySQL database name: xenforo
```

Start the installation. This can take a while...

### Step 4: Enabling development mode.

For this next step and for generally having a good development experience(and use the _output folder) you need development mode enabled.
This allows you to use all the development features of xenforo which you will most likely need.

Edit the file: xenforo/src/config.php and add the following line:

```
$config['development']['enabled'] = true;
```

You can <b>optionally</b> add this too. This will set your addon as the default option in development menus.
```
$config['development']['defaultAddOn'] = 'YourGroup/YourAddon';
```

### Step 5: Install the addon - Option 1 - Dev mode (recommended for development)
#### Option 1: Dev mode (recommended for development)

```bash
docker exec xenforo php cmd.php xf-dev:import
docker exec xenforo php cmd.php xf:addon:install Cav7/AvatarByRole
```

#### Option 2: Standard

```bash
docker exec xenforo php cmd.php xf:rebuild-cache
```

✅ Images in `Resources/images` are automatically copied to:

```
styles/default/xenforo/avatars/
```

---

## ⚙️ Configuration

Defined in `Application/Config/AvatarConfig.php`
It is necessary that all avatar groups are linked to the correct user group id.
```php
// -1 => indiciates that the group isn't set yet
return [
    new AvatarGroup(102, 'PVT', '/styles/default/xenforo/avatars/PVT_Avatar.png'),
    new AvatarGroup(103, 'PFC', '/styles/default/xenforo/avatars/PFC_Avatar.png'),
    new AvatarGroup(111, 'CPL', '/styles/default/xenforo/avatars/CPL_Avatar.png'),
];
```

Want to add a new rank? Just add:

```php
new AvatarGroup(7, 'NEW_RANK', '/styles/default/xenforo/avatars/New_rank_Avatar.png'),
```

---

## 🧪 Development Tips

- Clear cache:
  ```bash
  docker exec xenforo php cmd.php xf:rebuild-cache
  ```

---

## 📦 Future Ideas

- Avatar caching service (e.g. for external CDNs)
- Group priority chains

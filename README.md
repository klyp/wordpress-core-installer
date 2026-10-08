# klyp/wordpress-core-installer

A Composer plugin that installs WordPress core into a directory of your choice instead of `vendor/`. It's used by [`klyp/wordpress`](https://github.com/klyp/wordpress), and works with any package of type `wordpress-core`.

## Usage

You normally don't require this plugin directly, because `klyp/wordpress` already depends on it. You only need to allow it and choose the install directory:

```jsonc
{
  "require": {
    "klyp/wordpress": "^7.1"
  },
  "config": {
    "allow-plugins": {
      "klyp/wordpress-core-installer": true
    }
  },
  "extra": {
    "wordpress-install-dir": "wp"
  }
}
```

`composer install` then puts WordPress in `wp/`.

### Choosing the directory

`extra.wordpress-install-dir` in your project's `composer.json` can be:

| Value | Result |
| --- | --- |
| not set | `wordpress/` |
| `"wp"` | `wp/` |
| `"public/wp"` | `public/wp/` |
| `{ "klyp/wordpress": "wp" }` | a map from package name to directory, for projects with more than one `wordpress-core` package. Packages that aren't listed go to `wordpress/`. |

Only the root project decides the directory. Settings in a dependency's own `composer.json` are ignored.

### Safety checks

Composer empties the install directory whenever WordPress is installed, updated or removed. To make sure that can never take other files with it, the plugin refuses to install, with a clear error, when the directory is:

- the project root (`.`, `./`)
- outside the project (`../wp`, `wp/../..`, and on Windows `...` or `.. `)
- an absolute path (`/var/www/wp`, `C:\wp`)
- a URL, stream wrapper or drive-relative path (`file:///var/www/wp`, `phar://…`, `C:wp`)
- the vendor directory, or a directory that contains it (e.g. `lib` when `vendor-dir` is `lib/vendor`), including when `vendor-dir` is set as an absolute path
- empty
- the same as, inside, or containing another `wordpress-core` package's directory
- an existing directory that already has files in it but no WordPress install, e.g. `public` instead of `public/wp`. An empty directory, or one that already holds WordPress, is fine. That includes a damaged install that's missing files, recognised by `wp-load.php` and `wp-settings.php`.

Replacing one `wordpress-core` package with another in the same directory (for example, switching a project to `klyp/wordpress`) is fine. The old package is removed before the new one is installed.

Use a dedicated subdirectory such as `wp`. Keep your own code (themes, plugins, uploads, `wp-config.php`) outside it, because it's replaced on every core update.

If you change `wordpress-install-dir` on an existing project, delete the old directory yourself. Composer only knows the new one, so the old core files stay on disk, and they stay reachable over the web if they're under the web root.

### Switching from another WordPress core installer

Only one Composer plugin should handle the `wordpress-core` type. When you switch a project to this plugin:
1. Remove any other WordPress core installer from `require` and `config.allow-plugins`.
2. Allow `klyp/wordpress-core-installer`.
3. Run `composer update` with `-W` for the core package and the installers.

Your `wordpress-install-dir` stays the same.

## Requirements

- Composer 2. Composer 2.2 LTS works for PHP 5.6–7.2 projects.
- PHP 5.6 or newer

## Development

```sh
tests/e2e.sh
```

The tests build throwaway projects with fixture `wordpress-core` packages. They check where core gets installed, that removing it deletes only its own directory, and that every unsafe directory is refused. CI runs them on PHP 5.6 to 8.5 ([.github/workflows/test.yml](.github/workflows/test.yml)).

The scripts require `bash`, `jq` and `composer`. Set `KEEP_WORK=1` to keep the throwaway projects for debugging.

## Releasing

Tag `master` with a version (`1.0.0`, `1.0.1`, …) and push the tag. Packagist picks up new tags through its GitHub integration.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

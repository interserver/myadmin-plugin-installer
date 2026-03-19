# MyAdmin Plugin Installer

[![Tests](https://github.com/detain/myadmin-plugin-installer/actions/workflows/tests.yml/badge.svg)](https://github.com/detain/myadmin-plugin-installer/actions/workflows/tests.yml)
[![Latest Stable Version](https://poser.pugx.org/detain/myadmin-plugin-installer/version)](https://packagist.org/packages/detain/myadmin-plugin-installer)
[![Total Downloads](https://poser.pugx.org/detain/myadmin-plugin-installer/downloads)](https://packagist.org/packages/detain/myadmin-plugin-installer)
[![License](https://poser.pugx.org/detain/myadmin-plugin-installer/license)](https://packagist.org/packages/detain/myadmin-plugin-installer)

A Composer plugin that provides custom installer logic for the MyAdmin hosting control panel ecosystem. It routes packages to the correct installation directories based on their type and exposes a set of Composer commands for project management tasks.

## Supported Package Types

| Type               | Description                            |
|--------------------|----------------------------------------|
| `myadmin-template` | Frontend templates (installed to `data/templates/`) |
| `myadmin-module`   | Service modules                        |
| `myadmin-plugin`   | Feature plugins                        |
| `myadmin-menu`     | Menu extensions                        |

## Composer Commands

| Command                    | Description                                      |
|----------------------------|--------------------------------------------------|
| `myadmin`                  | Base MyAdmin command                              |
| `myadmin:parse`            | Parse PHP DocBlocks for API documentation          |
| `myadmin:create-user`      | Create a new MyAdmin user                         |
| `myadmin:update-plugins`   | Discover and cache available plugins              |
| `myadmin:set-permissions`  | Set writable permissions on configured directories |

## Installation

```sh
composer require detain/myadmin-plugin-installer
```

## Requirements

- PHP 8.2 or later
- Composer 2.x

## Running Tests

```sh
composer install
vendor/bin/phpunit
```

## License

This package is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

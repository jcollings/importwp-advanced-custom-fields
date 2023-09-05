# Import WP Advanced Custom Fields Importer

Version: 2.5.2  
Requires Import WP: 2.5.0  
Requires Import WP PRO: 2.5.0

![Advanced Custom Fields Importer](./assets/iwp-addon-acf.png)

## Description

Import WP Advanced Custom Fields Importer Addon allows you to import into Advanced Custom Fields generated fields.

## Installation

The Advanced Custom Fields Importer Addon can currently only be installed by downloading from [github.com](https://github.com/jcollings/importwp-advanced-custom-fields) via the Releases tab of the repository.

1. Download the latest version via the [Releases page on github](https://github.com/jcollings/importwp-advanced-custom-fields/releases).
1. Upload ‘importwp-advanced-custom-fields’ to the ‘/wp-content/plugins/’ directory
1. Activate the plugin through the ‘Plugins’ menu in WordPress

## Frequently Asked Questions

### How do i import into a link field

A link field is made up of the following keys (title, url, target).

Enter a single key value pair

```
url=http://www.example.com
```

Multiple key value pairs are seperated by |

```
title=Link Title|url=http://www.example.com
```

### How do i import into a google maps field

Google maps fields are made up of the fillowing keys (address, lat, lng, zoom)

Enter a single key value pair

```
address=United Kingdom
```

Multiple key value pairs are seperated by |

```
address=United Kingdom|lat=54.219462|lng=-13.4176232|zoom=5
```

## Screenshots

## Changelog

### 2.5.2

- FIX - Update delimiter filter to use field name instead of field id.

### 2.5.1

- FIX - skip fields with empty names e.g. tabs.
- FIX - Attachment \_return value should be settings.\_return
- ADD - Allow importer to pre populate fields from csv using default exported field names.

### 2.5.0

- FIX - Update exporter to work with Import WP 2.7.0

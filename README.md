Open Discovery Space Updater
==========

## What is this?

This is an updater. A program that stores new content and keep it up to date.

## Requirements

* Drupal 7.22
* Drush
* MySQL
* PHP 5.4+
* (Obviously) Tons of XMLs in ODS-AP

## How does it work?

Well, it takes each file of a folder (`<drupal_site>/harvest/`) and runs some xpath queries to get specific content from each XML.
Once it has parsed each XML, Updater maps and clean the data just parsed, then it creates and saves the content as new node.

## Readable names

Repositories and languages, in most of the cases, must be replaced by a readable name for Drupal (or users). You must add, as necessary, new translations of unreadable names at `languages.ini` and `repositories.ini`.

## How to run it?

1. Place at your *Drupal folder*.
2. Make sure you have all harvested XMLs in `harvest/` folder.
3. Run `drush php-script /path/to/updater/updater.php`.
4. Go and have a coffee, launch and do some productive things, it will take a lot of time depending on the harvested XMLs.
5. Check your Drupal site for new content.

## How to debug it?

1. Edit the file `updater.php` and change `UPDATER_DEBUG_ENABLE` to `true`.
2. Save it.
3. Run the script.

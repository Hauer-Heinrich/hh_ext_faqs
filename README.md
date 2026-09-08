# hh_ext_faqs – FAQ extension for TYPO3 v13

Accessible FAQ lists (native `<details>/<summary>` accordion) with an optional
client-side live search and upgrade wizards to migrate from EXT:jpfaq (manual steps necessary!).

## Features

- **FAQ: List** plugin
  - FAQ entries selected directly (order of selection is kept) **or** loaded
    from one or more storage folders (`pages` + `recursive` fields of the
    content element)
  - Optional category filter (sys_category, OR/AND), preselected by the editor
  - Sorting: manual, alphabetical by question, creation date
  - Optional schema.org **FAQPage** JSON-LD output
- **FAQ: Search** plugin
  - Pure client-side live search (no page reload, no AJAX)
  - Starts filtering at 3 typed characters, diacritic-insensitive,
    all search terms must match (AND)
  - Searches **question and answer** text – "mietrad" also matches
    "Mieträder" inside a collapsed answer
  - Matches are highlighted with `<mark class="highlight">`;
    a plugin checkbox controls whether matching entries are expanded
    automatically while the search is active (default: on, previous
    open/closed state is restored when the search is cleared;
    highlights cannot span across inline tags, e.g. a term split by
    `<strong>` inside a word is still found but not highlighted)
  - Non-matching questions are hidden; if a list has no matches, its whole
    content element container **including the header** is hidden
  - Editor selects which FAQ lists are searched via a side-by-side select
    that only offers "FAQ: List" elements **on the same page**
    (empty = all lists on the page)
  - Accessible: `<search>` landmark, associated `<label>`, `aria-describedby`
    hint, `role="status"`/`aria-live` result announcements
- FAQ records: question, answer (RTE), media (images/videos), sys_category,
  translations, hidden/starttime/endtime
- Upgrade wizards for **EXT:jpfaq** (records + plugins)

## Installation

1. `composer require hauerheinrich/hh-ext-faqs` (or copy to `packages/` and install)
2. **Enable the frontend rendering per site / root tree (opt-in!):**
   - Site-set based sites: add the **FAQ** set (`hauerheinrich/hh-ext-faqs`) as dependency
     in the site configuration (`dependencies: [hauerheinrich/hh-ext-faqs]` in
     `config.yaml` or via the Sites module), **or**
   - classic sys_template based trees: add the static include
     **FAQ (hh_ext_faqs)** in the root template of the tree
     ("Include static (from extensions)").
3. Create a folder, add FAQ records, place the **FAQ: List** plugin.
4. Optionally place the **FAQ: Search** plugin above the list(s) and select
   the lists it should filter.

### Multi-domain / opt-in rendering

`configurePlugin()` with `PLUGIN_TYPE_CONTENT_ELEMENT` normally registers
global rendering TypoScript for **all** sites. This extension deliberately
removes that global definition again (see `ext_localconf.php`) and ships the
rendering in `Configuration/TypoScript/setup.typoscript` instead, loaded only
via the site set **or** the static include. Result:

- Sites/trees **with** the set or static include render the FAQ elements.
- Sites/trees **without** it render nothing for these content elements.

The backend (TCA) is always global in TYPO3. To also hide the content
elements from editors in trees that should not use them, add this to the
page TSconfig of the affected root page:

```
TCEFORM.tt_content.CType.removeItems := addToList(hhextfaqs_list, hhextfaqs_search)
```

Note for migrations: trees containing migrated jpfaq plugins need the set or
static include as well, otherwise the migrated elements stay invisible in
the frontend.

# Extending the FAQ plugins

Both plugins store their settings in regular `tt_content` columns instead of
FlexForms. This means you can extend them from your own extension using
standard TYPO3 APIs only — no XCLASSing, no FlexForm manipulation.

Extending a plugin consists of up to three steps:

1. Add your own `tt_content` column and place it in the plugin form (TCA)
2. Render the value by overriding a Fluid template/partial
3. Optional: prepare complex data (e.g. FAL references) via a PSR-14 event

## Prerequisites: declare the dependency

TCA override files (`Configuration/TCA/Overrides/*.php`) are executed in
extension loading order. Your extension **must** be loaded *after*
`hh_ext_faqs`, otherwise the plugin types do not exist yet when your code
runs and calls like `addToAllTCAtypes()` will silently do nothing.

Declare the dependency in your `composer.json`:

```json
{
    "require": {
        "hauerheinrich/hh-ext-faqs": "^1.0"
    }
}
```

or, in classic mode, in your `ext_emconf.php`:

```php
'constraints' => [
    'depends' => [
        'hh_ext_faqs' => '1.0.0-0.0.0',
    ],
],
```

## Step 1: Add your own field

Use your own vendor prefix for the column name (`tx_yourext_*`). The
`tx_hhextfaqs_*` namespace is reserved for this extension.

`Configuration/TCA/Overrides/tt_content.php` of your extension:

```php
<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTCAcolumns('tt_content', [
    'tx_yourext_bg_image' => [
        'exclude' => true,
        'label' => 'LLL:EXT:your_ext/Resources/Private/Language/locallang_db.xlf:tt_content.tx_yourext_bg_image',
        'config' => [
            'type' => 'file',
            'allowed' => 'common-image-types',
            'maxitems' => 1,
        ],
    ],
]);

// Position the field inside the search plugin form:
ExtensionManagementUtility::addToAllTCAtypes(
    'tt_content',
    'tx_yourext_bg_image',
    'hhextfaqs_search',
    'after:tx_hhextfaqs_auto_expand'
);
```

Add the column to your `ext_tables.sql` (optional on TYPO3 v13, where the
schema is derived from TCA automatically):

```sql
CREATE TABLE tt_content (
    tx_yourext_bg_image int(11) unsigned DEFAULT '0' NOT NULL
);
```

Then run *Admin Tools → Maintenance → Analyze Database Structure* and flush
all caches.

## Step 2: Render the value (template override)

All plugin templates receive the full `tt_content` row of the content
element as the Fluid variable `{data}`. Scalar values (text, selects,
checkboxes) therefore need **no PHP at all** — override the template or
partial and access your column directly:

```typoscript
plugin.tx_hhextfaqs_search {
    view {
        templateRootPaths.100 = EXT:your_ext/Resources/Private/Extensions/HhExtFaqs/Templates/
        partialRootPaths.100 = EXT:your_ext/Resources/Private/Extensions/HhExtFaqs/Partials/
    }
}
```

```html
<f:if condition="{data.tx_yourext_some_toggle}">
    ...
</f:if>
```

Prefer overriding a single partial over copying the whole template — this
keeps your override compatible with future updates of this extension.

## Step 3: Prepare complex data with the PSR-14 event

Values that need processing before rendering — FAL references, relations,
computed values — can be added through an event that is dispatched right
before the view is rendered:

| Event | Dispatched in |
|---|---|
| `HauerHeinrich\HhExtFaqs\Event\ModifySearchVariablesEvent` | `SearchController::searchAction()` |
| `HauerHeinrich\HhExtFaqs\Event\ModifyListVariablesEvent` | `ListController::listAction()` |

Both events provide the same API:

| Method | Description |
|---|---|
| `getVariables(): array` | All variables that will be assigned to the view |
| `addVariable(string $key, mixed $value): void` | Add or replace a single view variable |
| `getContentElementData(): array` | The full `tt_content` row of the current content element (read-only) |

### Example: resolve a FAL image and pass it to the template

`Classes/EventListener/AddBackgroundImageToSearch.php` in your extension:

```php
<?php

declare(strict_types=1);

namespace YourVendor\YourExt\EventListener;

use HauerHeinrich\HhExtFaqs\Event\ModifySearchVariablesEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Resource\FileCollector;

#[AsEventListener(identifier: 'your-ext/faq-search-bg-image')]
final class AddBackgroundImageToSearch
{
    public function __invoke(ModifySearchVariablesEvent $event): void
    {
        $fileCollector = GeneralUtility::makeInstance(FileCollector::class);
        $fileCollector->addFilesFromRelation(
            'tt_content',
            'tx_yourext_bg_image',
            $event->getContentElementData()
        );

        $event->addVariable('bgImage', $fileCollector->getFiles()[0] ?? null);
    }
}
```

The `#[AsEventListener]` attribute registers the listener automatically
(TYPO3 v12.3+). Alternatively, register it in your `Configuration/Services.yaml`:

```yaml
services:
  YourVendor\YourExt\EventListener\AddBackgroundImageToSearch:
    tags:
      - name: event.listener
        identifier: 'your-ext/faq-search-bg-image'
```

In your overridden template/partial the variable is then available like any
other view variable:

```html
<f:if condition="{bgImage}">
    <div class="faq-search--header"
         style="background-image: url('{f:uri.image(image: bgImage, maxWidth: 1600)}');">
        ...
    </div>
</f:if>
```

After adding a listener, flush all caches once (dependency injection
information is cached).



## Migration from EXT:jpfaq

Run the wizards in the **Upgrade module** (or via CLI) **in this order**:

1. **Migrate jpfaq records to hh_ext_faqs** (`hhExtFaqs_jpfaqRecordMigration`)
   - jpfaq categories → `sys_category` below a new root category
     **"FAQ Categories"**
   - jpfaq questions → `tx_hhextfaqs_domain_model_faq` (pid, sorting, hidden,
     start/end time, translations are kept)
   - question/category relations → `sys_category_record_mm`
   - The uid mapping is stored in `sys_registry` (namespace `tx_hhextfaqs`)
     and is reused by the plugin wizard.
2. **Migrate jpfaq plugins to hh_ext_faqs** (`hhExtFaqs_jpfaqPluginMigration`)
   - `tt_content` elements with `CType=hhextfaqs_faq` (or the legacy
     `list_type=jpfaq_faq`) become `CType=hhextfaqs_list`
   - FlexForm `settings.startingpoint` → `pages` field
   - FlexForm `settings.flexform.selectCategory` → `settings.categories`
     (mapped to the new sys_category uids)

```bash
vendor/bin/typo3 upgrade:run hhExtFaqs_jpfaqRecordMigration
vendor/bin/typo3 upgrade:run hhExtFaqs_jpfaqPluginMigration
```

Both wizards are idempotent and repeatable; already migrated records are
skipped.

### Deliberately not migrated

- jpfaq comments (`questioncomment`, `categorycomment`) and the
  helpful/not-helpful counters – no equivalent in hh_ext_faqs
- `additional_content_answer` (IRRE content elements inside answers) –
  the referenced `tt_content` records stay untouched in the database;
  move their content into the answer RTE field manually if needed
- jpfaq frontend settings from the FlexForm (search box, comment forms, …) –
  the search is now a separate plugin

## Notes

- Hiding the whole container relies on the fluid_styled_content frame
  (`id="c<uid>"`). With `frame_class = none` the plugin output itself is
  hidden instead – the CE header is part of the plugin frame in that case,
  check your rendering if you use heavily customized frames.
- If several FAQ lists are on one page, enable **structured data** for one
  list only to avoid duplicate `FAQPage` markup. Note that Google shows FAQ
  rich results only for a limited set of sites since 2023 – the markup is
  still valid and useful for other consumers.

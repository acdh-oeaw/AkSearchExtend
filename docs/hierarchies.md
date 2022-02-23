# Displaying (simple) hierarchies

Corresponding Redmine issues: [parent->children](https://redmine.acdh.oeaw.ac.at/issues/19800), [child->parent](https://redmine.acdh.oeaw.ac.at/issues/19801).

## VuFind wiki requirements

* The container record must have `is_hierarchy_id`
* The child records must have `hierarchy_parent_id`
* Additionally, the child records must have `container_title` for the link to container to be displayed.
* Child records may also have `container_reference` which is displayed after the link and may include e.g. issue and page number.

See https://vufind.org/wiki/indexing:hierarchies_and_collections

## Parent->children linking

There are two places to fix:

* The "Contents/pieces" link displayed in various places (main table of the record view, search results)
  * Counting of child resources works fine because it's based on counting records with `hierarchy_parent_id` equal to current record's `is_hierarchy_id` solr fields.
  * Link leading to search results needs fixing as the `VuFind\View\Helper\Root\RecordLink::getChildRecordSearchUrl()` method it uses
    is hardcoded to use parent's `{RecordDriverClass}::getUniqueID()` and we need to fetch an AC id (MARC field `009`) here.
    There are two solutions possible:
    * Reimplement the `VuFind\View\Helper\Root\RecordLink::getChildRecordSearchUrl()` to use record driver's method returning the AC id
      (either by implementing our own `aksearchExt\SolrMarc::getUniqueID()` taking optional "id type" parameter or introducing `aksearchExt\SolrMarc::getAcID()`)
    * Implement our own `aksearchExt\SolrMarc::getUniqueID()` which returns various id types based on which function called it (this can be checked by call stack introspection)
    The first one is nicer but the second one minimizes the amount of classes we override. Also the call stack introspection in PHP is cheap
    (`debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)` takes below 3 us on my laptop) so the second solution is preferred.
* The "Contents/pieces" tab displayed in the record view
  * First we must enable it. The most elegant way of doing it is to copy `{vufinddir}/config/vufind/RecordTabs.ini` into `{localconfig}/vufind/RecordTabs.ini`,
    copy contents of the `[VuFind\RecordDriver\SolrMarc]` section into the `[aksearchExt\SolrMarc]` section and uncomment/add `tabs[ComponentParts] = ComponentParts` line.
    Now we have an empty "Contents/pieces" tab and have to adjust the `VuFind\RecordTab\ComponentParts` class.
  * `VuFind\RecordTab\ComponentParts::getResults()` suffers from the same problem as `VuFind\View\Helper\Root\RecordLink::getChildRecordSearchUrl()` (see above)
    which can be resolved in exactly the same way.

## Child->parent linking

* It's being used in many places (e.g. in search results or favourite records list) but it looks like we are only interested in one -
  the main table of the single record view.
* Finding the template source
  * Our template is based directly on `bootstrap3` and **not** on the `AkSearch`!
  * The single record view template (excluding tabs configured with the `{configdir}/vufind/RecordTabs.ini`)
    is defined in `{themedir}/templates/RecordDriver/DefaultRecord/core.phtml`
    but it just renders HTML comming from `VuFind\View\Helper\Root\RecordDataFormatter::getData($record, $defaults)`.
    * `VuFind\View\Helper\Root\RecordDataFormatter::getData($record, $defaults)` is actually driven by
      `VuFind\View\Helper\Root\RecordDataFormatter::getDefaults('core')` which gets the specification from the
      `AkSearch\View\Helper\Root\RecordDataFormatterFactory::getDefaultCoreSpecs()`
      which sets up the `Published in` field as being provided by the `{RecordDriver}::getConsolidatedParents()` method 
      and the `{themedir}/templates/RecordDriver/DefaultRecord/data-containerTitle.phtml` template
* Investigation from the previous point leaves us with two possible solutions:
  * overridding `AkSearch\View\Helper\Root\RecordDataFormatterFactory::getDefaultCoreSpecs()`
    so it follows VuFind implementation of the `Published in` field using the `{RecordDriver}::getContainerTitle()` method
  * extend our `aksearchExt\SolrMarc` with the `getConsolidatedParents()` method so it calls `VuFind\RecordDriver\DefaultRecord::getContainerTitle()`
    and formats results in the `AkSearch\RecordDriver\SolrMarc::getConsolidatedParents()` compatible way (array of arrays with at `id`, `title` and `volNo`  fields)
    so our record driver's API is AkSearch-compliant but uses basic VuFind implementation
  * like previous but we use VuFind version of the `{themedir}/templates/RecordDriver/DefaultRecord/data-containerTitle.phtml`
    and our `aksearchExt\SolrMarc::getConsolidatedParents()` returns just a string with the title taken from `$this->getContainerTitle()`
  The second solution is the best. It's easier to implement than the first one and the AkSearch version of the
  `{themedir}/templates/RecordDriver/DefaultRecord/data-containerTitle.phtml` template has the benefit of using parent's id instead of title-based search.


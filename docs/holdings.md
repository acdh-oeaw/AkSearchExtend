# Processing and displaying holdings

## Call stack

* The template (`templates/RecordTab/holdingsils.phtml`) calls `{recordDriver}::getRealTimeHoldings()`.  
  Both in VuFind and AkSearch this data is already grouped by location.  
  This data may also contain another artifacts (e.g. the AkSearch computes summary per location).
  * `aksearchExt\SolcMarc::getRealTimeHoldings()` calls `{holdLogic}::getHoldings()`  
    In vanila VuFind nothing's really going on here.  
    In our case that's where additional **LKR holdings retrieval** is handled ([#19566](https://redmine.acdh.oeaw.ac.at/issues/19566))
    as well as **electronic porfolios URLs** are set ([#19474](https://redmine.acdh.oeaw.ac.at/issues/19474)) (as the URL is only provided in MARC)
  * `VuFind\ILS\Logic\Holds::getHoldings()`:
    * calls `{ilsConnection}::getHolding()` which handles paging options and calls `{ilsDriver}::getHolding()`
        * `aksearchExt\Alma::getHolding()` fetches holdings list from the Alma REST API.  
          We had to override the original method so first, all fields from the response important to us were preserved (see the LKR)
          and second, holding summary data is generated.
    * calls `VuFind\ILS\Logic\Holds::generateHoldings()` on data returned by the `{ilsDriver}::getHolding()`.  
      `VuFind\ILS\Logic::generateHoldings()` groups holdings list by the `{config/vufind/config.ini}[Catalog].holdings_grouping`
    * calls `VuFind\ILS\Logic\Holds::processStorageRetrievalRequests()` and `VuFind\ILS\Logic::processILLRequests()`
      which set item properties connected to the order/cancel button handling
* The template (`templates/RecordTab/holdingsils.phtml`) and renders its output.

## Design considerations

* The Alma data model is *a record has holdings and each holding has items*.
* In our case there are a few complications:
  * Some holdings have items and some don't and we should display both kinds.
  * We need to also query for holdings and items from linked records (so-called *LKR* records).
  * Display should be grouped by library.
    * On the Alma API level can be achieved only for a single request and due to LKR 
      and holdings without items we are forced to make many Alma API requests.
    * LKR holdings can come from same libraries as "normal" holdings.

It affects implementation in two ways:

* We must be able to pass multiple MMS ids (both record's and LKR records ones)
  to the `{holdLogic}::getHoldings()` holdings search method.
* The `{ilsDriver}::getHolding()` must be able to take many MMS ids and correctly
  deal **both** with sorting, paging and handling holdings without items.

It makes it clear we have to override all of:

* `{recordDriver}::getRealTimeHoldings()` so it extracts all MMS ids (also LKR ones)
  and passes them to the `{holdLogic}::getHoldings()`
* `{holdLogic}::getHoldings()` it accepts multiple MMS ids and passes them to the 
  `{ilsDriver}::getHolding()`
* `{ilsDriver}::getHolding()` so it correctly dills with multiple MMS ids, sorting,
  paging and holdings without items.

Fortunately all of these classes can be overridden with the module config:

* `{recordDriver}`: `vufind.plugin_managers.recorddriver.aliases.VuFind\RecordDriver\SolrMarc`
  * using VuFind's `VuFind\RecordDriver\SolrDefaultFactory` factory to be set in 
    `vufind.plugin_managers.recorddriver.factories.{ourRecordDriverClass}`
  * defining delegator responsible for setting the `{holdLogic}` object on the record driver by setting
    `vufind.plugin_managers.recorddriver.delegators.{ourClass}` to `[{ourOwnDelegator}]`
    (this is required so we our own `{holdLogic}` class is used - read below)
* `{ilsDriver}`: `vufind.plugin_managers.ils_driver.aliases.VuFind\ILS\Driver\Alma`
  * using VuFind's `VuFind\ILS\Driver\AlmaFactory` factory to be set in 
    `vufind.plugin_managers.ils_driver.factories.{ourIlsDriveClass}`
* `{holdLogic}` is the most problematic.
  * The problem is the VuFind's `VuFind\ILS\Logic\LogicFactory` factory explicitly instanciates `\Vufind\ILS\Logic\Holds`.
    While we can provide an override for `\Vufind\ILS\Logic\Holds` mapping it in the Laminas service manager to our own class,
    it would cause `\Vufind\ILS\Logic\Holds` not to be loaded at all. And if the original `\Vufind\ILS\Logic\Holds`
    is never loaded, trying to derive our class from it would lead to "Class `\Vufind\ILS\Logic\Holds` not found".
  * To deal with that we must define a custom delegator factory for our `{recordDriver}` class (see above).
    It should extend `\VuFind\RecordDriver\IlsAwareDelegatorFactory` and override the `__invoke()` method
    by replacing the `\Vufind\ILS\Logic\Holds` constructor call with `{ourHoldLogicClass}` call.
  * We can reuse VuFind's `VuFind\ILS\Logic\LogicFactory` factory for our class (to be set in 
    `service_manager.factories.{ourHoldLogicClass}`)

## Desired display

* Electronic holdings displayed only on the first page, before normal holdings.
  * To make things simple we don't count them in paging.
    (as they are fetched from MARC record counting them in paging would require passing their count
    to the `aksearchExt\Alma::getHolding()`)
* Grouped either by `item_data.holding_id` or `item_data.library` in the Alma REST API response.
  * As we want to avoid overriding `VuFind\ILS\Logic\Holds` and we need to separately fetch holding summaries,
    the grouping has been moved to the `aksearchExt\Alma::getHolding()` which reads
    `{config/vufind/Alma.ini}[Catalog].holdings_grouping` and sets fixed-named `group` item property value accordingly.
    Then `VuFind\ILS\Logic\Holds::generateHoldings()` does the grouping according to
    `{config/vufind/config.ini}[Catalog].holdings_grouping` being fixed to `group`.
  * Can be done by setting `{config/vufind/Alma.ini}[Catalog].holdings_grouping` to `holding_id` or `library`.
  * LKR holdings should be treated as separate ones, even if they share same `holding_id` with the normal ones.
    Therefore `aksearchExt\SolcMarc::getRealTimeHoldings()` should gather the under dedicated properties 
    (`lkrHoldingsSummary` and `lkrItems` in the dataset it returns)
* From the template side - for every group in `aksearchExt\SolcMarc::getRealTimeHoldings()['holdings']`:
  * If the library of the group differs from previous group's library, display the library name.
    (take library either from `$group['items'][0]['library']` or when it's empty `$group['lkrItems'][0]['library']`)
  * Display all holding summaries for a given group. Each summary is a separate table with following rows:
    | summary field     | MARC returned by the Alma API |
    | ----------------- | ----------------------------- |
    | locationName      | `852_81_c`, `852_8#_c`        |
    | callNos           | `852_81_h`, `852_8#_h`        |
    | callnumberNotes   | `852_81_z`, `852_8#_z`        |
    | holdingsAvailable | `866_30_a`                    |
    | gaps              | `866_30_z`                    |
    | holdingsPrefix    | `866_30_9`, `866_#0_a`        |
    | holdingsNotes     | `866_#0_z`                    |
    
    * If a row has empty value, skip the whole row.
    * This data has to be fetched from MARC returned by a separate per-holding call to the Alma REST API (`bibs/{mms_id}/holdings/{holding_id}`).
    * It's done in `aksearchExt\Alma::getHoldingSummary()` being called by ``aksearchExt\Alma::getHolding()`.
      While it would better fit `VuFind\ILS\Logic\Holds::generateHoldings()` which performs
      the items data aggregation, overriding it would require creation of many boiler plate override classes and brings a risk of a need 
      for copy-pasting VuFind code into them.
  * Display all non-lkr items within a given group as a table with following columns.
    | column | property in `{recordDriver}:: getRealTimeHoldings()` return value | property in Alma REST API response | where the mapping takes place | MARC [1] |
    | --- | --- | --- | --- | --- |
    | signature   | `callnumber`              | `holding_data. call_number`          | `aksearchExt\Alma::getHolding()` | `852_8#_h`     |
    | signature2  | `alternative_call_number` | `item_data.alternative_call_number` | `aksearchExt\Alma::getHolding()` | `ITM_##_n`     |
    | description | `description`             | `item_data.description`              | `aksearchExt\Alma::getHolding()` | `ITM_##_z`     |
    | location    | `location`                | `item_data.location`                 | `aksearchExt\Alma::getHolding()` | `ITM_##_2`     |
    | remarks     | `public_note`             | `item_data.public_note`              | `aksearchExt\Alma::getHolding()` | `ITM_##_z`     |
    | status      | `policy`                  | `item_data.policy`                   | `aksearchExt\Alma::getHolding()` | `ITM_##_f`     |
    | availbility | `availability`            | `item_data.base_status`              | `VuFind\ILS\Driver\Alma:: getAvailabilityFromItem()` [2] | `ITM_##_e`     |
    | order/cancel button | `storageRetrievalRequestLink`, `ILLRequestLink` | ???    | `VuFind\ILS\Logic\Holds:: processStorageRetrievalRequests()` and `VuFind\ILS\Logic:: processILLRequests()` | not applicable |
  
    [1] We shouldn't read this information from MARC, just it can be handy to be able to check if what we read from the Alma REST API matches the MARC value
        or to find the right field in the Alma REST API response by knowing its value from MARC.  
    [2] Called by `aksearchExt\Alma::getHolding()`
  * If the group has lkr items, display them in a separate table with the same columns as for non-lkr items.

## Test cases

* all fields render: 993505611904498 ([#19550](https://redmine.acdh.oeaw.ac.at/issues/14550#note-40))
* 990000517690504498:
  * paging (over 100 total items)
  * both itemized and not-itemized holdings ([#19898](https://redmine.acdh.oeaw.ac.at/issues/19898))
* 990002108420504498:
  * electronic portfolios:  ([#19474](https://redmine.acdh.oeaw.ac.at/issues/19474))
* 990000268490504498:
  * LKR ([#14550](https://redmine.acdh.oeaw.ac.at/issues/14550))
* 990000272520504498:
  * LKR mixing holdings within same library ([#14550](https://redmine.acdh.oeaw.ac.at/issues/14550))

## Known issues

* Paging and LKR.  
  Paging is supported on the ILS driver level but we retrieve LKRs with a separate ILS driver call.
  As a result paging is applied separately for normal and LKR resources and after that both sets of data are merged together
  resulting in "n-th page of normal resources and n-th page of LKRs".
* Non-itemized holdings.  
  There are holdings which don't have any items but their summary should still be displayed.
  And paging should still work.
  So there's no other way than to reimplement the `VuFind\ILS\Logic\Holds` class.

## Varia

* Compact bactrace dump:
  ```  
  function backtrace(int $limit = 10000) {
      foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit) as $n => $i) {
          echo "[$n]:".($i['file']??'').':'.($i['line']??'').':'.($i['class']??'').($i['type']??'').($i['function']??'').'()'."\n";
      }
  }
  ```

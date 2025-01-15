# Processing and displaying holdings

## Call stack

### General workflow:

* [1a] `templates/RecordTab/holdingsils.phtml` template calls `{recordDriver}::getRealTimeHoldings()`
  * [2a] `{recordDriver}::getRealTimeHoldings()` calls `{IlsHoldsLogic}::getHoldings()`
    * [3a] `{IlsHoldsLogic}::getHoldings()` calls `{IlsConnection}::getHolding()`
      * [4] `{IlsConnection}::getHolding()` calls `{IlsDriver}::getHolding()`
        * [5] `{IlsDriver}::getHolding()` reads items data from the ILS (Alma) API
    * [3b] `{IlsHoldsLogic}::getHoldings()` runs a lot of logic (items grouping, hold/recall links generation, data format sanitation)
  * [2b] `{recordDriver}::getRealTimeHoldings()` returns data
* [1b] `templates/RecordTab/holdingsils.phtml` renders holdings and items

### Detailed workflow

| Stage | Vanilla | aksearch-ext | remarks |
|-------|---------|--------------|---------|
| 1a    | `templates/RecordTab/holdingsils.phtml` calls `{recordDriver}::getRealTimeHoldings()` | Same as in vanilla. | |
| 2a    |         | `aksearchExt\SolrMarc::getRealTimeHoldings()` collects id data of the record and connected LKR records storing them in `array<aksearcExt\container\IlsHoldingId>` | |
| 2a    | `{recordDriver}::getRealTimeHoldings()` calls `VuFind\ILS\Logic\Holds::getHoldings(int $recordId)` | `aksearchExt\SolrMarc::getRealTimeHoldings()` calls `aksearchExt\ILSHoldLogic::getHoldings(array<aksearcExt\container\IlsHoldingId> $ids)` | |
| 3a    | `VuFind\ILS\Logic\Holds::getHoldings()` runs quite some logic around testing ILS driver capabilities, handling consortial holdings (something unknown to the Alma driver). | Dropped as we know exactly which driver we use and what it can do. | |
| 3a    | `VuFind\ILS\Logic\Holds::getHoldings()` calls `{IlsConnection}::getHolding()` | Same as in vanilla (just the call is made by `aksearchExt\ILSHoldLogic::getHoldings()`). | |
| 4     | `VuFind\ILS\Connection::getHolding()` prepares paging options to be passed to `{IlsDriver}::getHolding()` | `VuFind\ILS\Connection::getHolding()` prepares paging options to be passed to `aksearchExt\Alma::getHolding()` | |
| 4     | `VuFind\ILS\Connection::getHolding()` calls `VuFind\ILS\Driver\Alma::getHolding()` | `VuFind\ILS\Connection::getHolding()` calls `aksearchExt\Alma::getHolding(array<aksearcExt\container\IlsHoldingId>, null, $pagingOptions)` | |
| 5     | `VuFind\ILS\Driver\Alma::getHolding()` makes a request to the Alma's `{recordId}/holdings/ALL/items` endpoint (with the sorting hardcoded as `order_by=library,location,enum_a,enum_b&direction=desc` and paging as passed in the `$options` parameter) and for each reported item creates an associative array representing the item gathering this data in `$results['holdings']`.   | For each `aksearcExt\container\IlsHoldingId` passed to it the `aksearchExt\Alma::getHolding()` queries the Alma holdings API and collects the list of library and location codes for each holding. In the same loop over holdings collects all holding's items (this has to be done per holding as the Alma's `{recordId}/holdings/ALL/items` endpoint has serious issues). Then it sorts holdings by the library code, LKR status (LKR holdings go last) and location code (the order requested by BAS:IS). Finally `array<aksearchExt\container\HoldingData>` is created from the sorted holdings array and returned. | The Alma items API applies sorting **after** paging and limits the `limit` parameter to 100. |
| 5     | `VuFind\ILS\Driver\Alma::getHolding()` calls `VuFind\ILS\Driver\Alma::getStatusesForInventoryTypes()` which makes a request to the Alma's `{recordId}/bibs` using the `expand={Alma.ini[Holdings]inventoryTypes mapped with VuFind\ILS\Driver\Alma::getInventoryTypes()}` query parameter. The returned data contain MARC record including information on physical/digitial/electronic items in additional `AVA`/`AVE`/`AVD` MARC fields, respectively. For each reported item `VuFind\ILS\Driver\Alma::getStatusesForInventoryTypes()` creates a simple data array (with only subset of fields created for the items fetched from the `{recordId}/holdings/ALL/items` endpoint) and returns them in an array `[{recordId} => [$item1, $item2, ...]]`. Finally `VuFind\ILS\Driver\Alma::getHolding()` saves this array as `$results['electronic_holdings']` | This has been moved to `aksearchExt\SolrMarc::getRealTimeHoldings()` where same data are read from the local MARC without a need for another Alma REST API call. | |
| 5     | `VuFind\ILS\Driver\Alma::getHolding()` returns item data as an array `['total' => int, 'holdings' => array<array<itemProperty, value>>, 'electronic_holdings' => array<array<itemProperty, value>>]` | `aksearchExt\Alma::getHolding()` returns item data as an array `['total' => int, 'holdings' => array<aksearchExt\container\HoldingData>, 'electronic_holdings' => []]` | |
| 3b    | `VuFind\ILS\Connection::getHolding()` sanitizes the array returned by the `{IlsDriver}::getHolding()` assuring they contain all required keys. | Same as in vanilla. | |
| 3b    | Depending on `config.ini[Catalog]holds_mode` the `VuFind\ILS\Logic\Holds::getHoldings()` chooses if and how to generate the hold/recall item links and executes appropiate code (`VuFind\ILS\Logic\Holds::standardHoldings()`, `VuFind\ILS\Logic\Holds::driverHoldings()` or `VuFind\ILS\Logic\Holds::generateHoldings()`). These methods are also responsible for hiding items from banned locations (read from a config property we didn't track down) and grouping them by the `config.ini[Catalog]holdings_grouping` property (so the result's `holdings` property is `array<string $groupName, array<array<itemProperty, value>>>`). | `aksearchExt\ILSHoldLogic::getHoldings()` iterates trough all `aksearchExt\container\ItemData` objects contained in the data returned by `aksearchExt\Alma::getHolding()` (embedded in `['holdings'][i]->items` and `['holdings'][i]->lkrItems`) and sets the `aksearchExt\container\ItemData::link` property to object created with the `\VuFind\ILS\Logic\Holds::getRequestDetails()`. Information contained in these objects are used in the template to create hold request/release buttons. This is what `config.ini[Catalog]holds_mode = all` (the default setting) would do in the vanilla. Our version doesn't do the grouping because items are already grouped (by holding and split into non-LKR and LKR ones) and ordered properly by the `aksearchExt\Alma::getHolding()`. | |
| 3b    | `VuFind\ILS\Logic\Holds::getHoldings()` runs storage retrieval requests logic (`VuFind\ILS\Logic\Holds::processStorageRetrievalRequests()`, not supported by the Alma driver) and ILL requests logic (`VuFind\ILS\Logic\Holds::processILLRequests()`, not supported by the Alma driver). | Dropped as the Alma driver doesn't support them. | |
| 3b    | `VuFind\ILS\Logic\Holds::getHoldings()` sanitizes items data a little using ``VuFind\ILS\Logic\Holds::formatHoldings()`. | Dropped - in our approach holding/item data is sanitized already at the  `aksearchExt\container\HoldingData`/`aksearchExt\container\ItemData` objects construction time. | |
| 3b    | `VuFind\ILS\Logic\Holds::getHoldings()` returns item data as an array `['total' => int, 'holdings' => array<string $group, array<array<itemProperty, value>>>, 'electronic_holdings' => array<array<itemProperty, value>>]` | `aksearchExt\ILSHoldLogic::getHoldings()` returns item data as an array `['total' => int, 'holdings' => array<aksearchExt\container\HoldingData>, 'electronic_holdings' => []]` | |
| 2b    | | `aksearchExt\SolrMarc::getRealTimeHoldings()` extracts electronic items data from MARC `AVE` fields, creates corresponding `aksearchExt\container\HoldingData` objects and collects them in `$results['electronic_holdings']` (where `$results` is data returned by the `aksearchExt\ILSHoldLogic::getHoldings()`) | |
| 2b    | `{recordDriver}::getRealTimeHoldings()` directly passes `VuFind\ILS\Logic\Holds::getHoldings()` return value to the template.  | `aksearchExt\SolrMarc::getRealTimeHoldings()` returns items data (in the same structure as returned by the `aksearchExt\ILSHoldLogic::getHoldings()`) to the template | |
| 1b    | `templates/RecordTab/holdingsils.phtml` template renders holdings and items | Same as in vanilla (but of course we use an adjusted template). | |

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
    Therefore `aksearchExt\SolrMarc::getRealTimeHoldings()` should gather the under dedicated properties 
    (`lkrHoldingsSummary` and `lkrItems` in the dataset it returns)
* From the template side - for every group in `aksearchExt\SolrMarc::getRealTimeHoldings()['holdings']`:
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

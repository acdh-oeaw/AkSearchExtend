# Processing and displaying holdings

## Call stack

* The template (`templates/RecordTab/holdingsils.phtml`) calls `{recordDriver}::getRealTimeHoldings()`.  
  Both in VuFind and AkSearch this data is already grouped by location.  
  This data may also contain another artifacts (e.g. the AkSearch computes summary per location).
  * `aksearchExt\SolcMarc::getRealTimeHoldings()` calls `{holdLogic}::getHoldings()`  
    In vanila VuFind nothing's really going on here.  
    In our case that's where additional **LKR holdings retrieval** is handled ([#19566](https://redmine.acdh.oeaw.ac.at/issues/19566))
    as well as **electronic porfolios URLs** are set ([#19474](https://redmine.acdh.oeaw.ac.at/issues/19474)) (as the URL is only provided in MARC)
  * `VuFind\ILS\Logic::getHoldings()`:
    * calls `{ilsDriver}::getHolding()`
        * `aksearchExt\Alma::getHolding()` fetches holdings list from the Alma REST API.  
          We had to adjust it a little so all important to us fields from the response were preserved (see the LKR).
    * calls `VuFind\ILS\Logic::generateHoldings()` on data returned by the `{ilsDriver}::getHolding()`.  
      `VuFind\ILS\Logic::generateHoldings()` groups holdings list by the `{config/vufind/config.ini}[Catalog].holdings_grouping`
    * calls `VuFind\ILS\Logic::processStorageRetrievalRequests()` and `VuFind\ILS\Logic::processILLRequests()`
      which set item properties connected to the order/cancel button handling
* The template (`templates/RecordTab/holdingsils.phtml`) and renders its output.

## Desired display

* Grouped by `item_data.library` in the Alma REST API response.
  * Can be done by setting `{config/vufind/config.ini}[Catalog].holdings_grouping` to `library`.
* A table with following columns:
  | column | property in `{recordDriver}:: getRealTimeHoldings()` return value | property in Alma REST API response | where the mapping takes place | MARC [1] |
  | --- | --- | --- | --- | --- |
  | signature   | `callnumber`              | `holding_data. call_number`          | `aksearchExt\Alma::getHolding()` | `852_8#_h`     |
  | signature2  | `alternative_call_number` | `item_data. alternative_call_number` | `aksearchExt\Alma::getHolding()` | `ITM_##_n`     |
  | description | `description`             | `item_data.description`              | `aksearchExt\Alma::getHolding()` | `ITM_##_z`     |
  | location    | `location`                | `item_data.location`                 | `aksearchExt\Alma::getHolding()` | `ITM_##_2`     |
  | remarks     | `public_note`             | `item_data.public_note`              | `aksearchExt\Alma::getHolding()` | `ITM_##_z`     |
  | status      | `policy`                  | `item_data.policy`                   | `aksearchExt\Alma::getHolding()` | `ITM_##_f`     |
  | availbility | `availability`            | `item_data.base_status`              | `VuFind\ILS\Driver\Alma:: getAvailabilityFromItem()` [2] | `ITM_##_e`     |
  | order/cancel button | `storageRetrievalRequestLink`, `ILLRequestLink` | ???    | `VuFind\ILS\Logic\Holds:: processStorageRetrievalRequests()` and `VuFind\ILS\Logic:: processILLRequests()` | not applicable |
  
  [1] We shouldn't read this information from MARC, just it can be handy to be able to check if what we read from the Alma REST API matches the MARC value
      or to find the right field in the Alma REST API response by knowing its value from MARC.  
  [2] Called by `aksearchExt\Alma::getHolding()`

## Test cases

* all fields render: 993505611904498 ([#19550](https://redmine.acdh.oeaw.ac.at/issues/14550#note-40))
* paging: 990000589940504498
* electronic portfolios: 990002108420504498 ([#19474](https://redmine.acdh.oeaw.ac.at/issues/19474))
* many items of the same holding: 993526014304498
* LKR: 990000268490504498 ([#14550](https://redmine.acdh.oeaw.ac.at/issues/14550))

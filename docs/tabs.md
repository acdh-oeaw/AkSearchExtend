# Holding tabs

## Configuration

* `{configDir}/RecordTabs.ini` provides tabs configuration
  * Section names follow record driver class names, e.g. in our case `[aksearchExt\SolrMarc]`.  
    Config property `tabs[labelKey] = tabClassAlias` within the section defines the set of tabs being displayed.  
    Our config [looks as follows](https://github.com/acdh-oeaw/AkSearchWeb/blob/main/local/config/vufind/RecordTabs.ini)).
  * The mapping of `tabClassAlias` to the actual class name works in the ordinary way.
    Respective mappings should be defined in [our module config](https://github.com/acdh-oeaw/AkSearchExtend/blob/master/src/aksearchExt/Module.php)
    under the `vufind.plugin_managers.recordtab` section, e.g.
    ```
    (...)
      'recordtab' => [
        'factories' => [
          'aksearchExt\RecordTab\Exemplarspezifika' => 'VuFind\RecordTab\HoldingsILSFactory',
          'aksearchExt\RecordTab\ComponentParts' => 'VuFind\RecordTab\ComponentPartsFactory',
        ],
        'aliases' => [
          'exemplarspezifika' => 'aksearchExt\RecordTab\Exemplarspezifika',
          'componentparts' => 'aksearchExt\RecordTab\ComponentParts',
        ]
      ],
    (...)
    ```

## Caveats

* A tab is displayed only if `$tab->isActive()` call returns `true`.  
  If your brand new tab isn't displayed but there's no error being thrown, it's the most probable cause.
  In details the most probable reason are interactions with the record driver (`aksearchExt\SolrMarc` 
  in our case) which might use some black magic which doesn't work with your class.

## Choosing the right factory class

* If we override existing tab, the safest pick is to reuse original implementation's factory.
  Surprisingly this doesn't come from the `vufind.plugin_managers.recordtab` section of the VuFind module 
  config (`{VuFindDir}/module/VuFind/config/module.config.php}`) but **is hardoded in** 
  `{VuFindDir}/module/VuFind/src/VuFind/RecordTab/TabManager.php`.
* If we create a new tab, there are two approaches possible:
  * Find a tab doing similar thing (depending on access to same objects like record driver object or ILS driver object),
    check which factory it's using (see the previous point) and use the same.
  * Go trough factories provided in the `{VuFindDir}/module/VuFind/src/VuFind/RecordTab/*Factory.php`
    and choose the best fitting one.

# AkSearchExtend

An example of extending the [ACDH deployment of AkSearch](https://github.com/acdh-oeaw/AkSearchWeb) with your very own module being loaded using Composer.

## How does it work?

Remark - description below applies to the current AkSearch which is based on VuFind 6. Some stuff will change with Vufind >= 7 which migrated from Zend2 to Laminas.

### Making the code loadable by Zend2

VuFind/AkSearch are written using Zend2 framework and extending/adjusting them goes down to implementation of you own Zend2 module(s).

To get your code recognized and properly loaded by a Zend2 you must:

* Choose your module name (here it's `aksearchExt`).
* Implement a `{my module name}\Module` class implementing `getAutoloaderConfig()` and `getConfig()` methods.
    * The `getAutoloaderConfig()` should just return an empty array (as composer will deal with autoloading for us and we don't need Zend2 for that).
    * The `getConfig()` class should return a Zend2/VuFind module configuration (an empty one is perfectly valid for starters).
    * The minimal implementation of the `aksearchExt` module (making it Zend2-loadable but nothing more than that) would look as follows:
      ```php
      class aksearchExt\Module {
          public function getAutoloaderConfig() {
              return [];
          }
          public function getConfig() {
              return [];
          }
      }
      ```
* Create a `composer.json` for your module so you can publish it as a composer package.
  Don't forget to define autoloading properly.
  If you need an example, just look at the `composer.json` in this repo root (it publishes this module as a `acdh-oeaw/aksearch-ext` composer package).
* Publish your code as a composer package (put it into a publicly reachable git repo and then publish on https://packagist.org/).

### Setting up VuFind/AkSearch to actually load your code

This is done by adjusting the [AkSearchWeb](https://github.com/acdh-oeaw/AkSearchWeb) repository content.

* If you didn't to it so far, clone the repository.
* Add your composer package to the list of composer dependencies
  either by running `composer require your-organization/your-composer-package-name` in the repository's root directory 
  or adding your package name to the `require` section of the `composer.json` manually.
* Include your Zend2 module name in the list of modules being loaded by the VuFind/AkSearch.
    * Append your module's name (namespace) **at the end** of the `VUFIND_LOCAL_MODULES` environment variable defined in the last line of the `Dockerfile` in the repository root directory.
    * Append your module's name (namespace) **at the end** of the `VUFIND_LOCAL_MODULES` environment variable in the `docker-compose.yaml` in the repository root directory
      (so people deploying with just `docker-compose up` have it as well).
    * Adjust the `VUFIND_LOCAL_MODULES` environment variable documentation in the `README.md`.
* Try to build the image locally by running `docker build -t acdhcd/aksearch-web .` in the repository root directory 
  and then try to deploy with `docker-composer up`.
  Finally check if everything stil works by opening http://127.0.0.1/vufind in your browser.
* If everything's fine, commit changes and push the repository to the GitHub.

Congratulations! Now your module is deployed along with the [AkSearchWeb](https://github.com/acdh-oeaw/AkSearchWeb) 
and will be used (or not) depending on the `VUFIND_LOCAL_MODULES` runtime environment variable value (precisely if its name is included in the env var value).

### Overriding actual VuFind/AkSearch code

The most tricky part is to find out the right place to plug in your own adjustment.

The good thing is it's all done by adjusting what your module's `Module::getConfig()` method returns (denoted below as `$cfg`).

The difficult part is you can plug your code in hundreds of places. See e.g. all class mappings provided in [VuFind's main module config](https://biapps.arbeiterkammer.at/gitlab/open/aksearch/aksearch/blob/aksearch/module/VuFind/config/module.config.php) or overrides defined by [AkSearch's AkSearch module config](https://gitlab.com/acdh-oeaw/oeaw-resources/module-core/-/blob/master/config/module.config.php).

Module provided in this repository just overrides the `getThumbnail()` method of the AkSearch's `SolrMarc` record driver so it always returns an ARCHE logo URL.

This is achieved by overriding the `VuFind\RecordDriver\SolrMarc` class with this module's `aksearchExt\SolrMarc` class, where the `aksearchExt\SolrMarc` just extends `AkSearch\RecordDriver\SolrMarc` reimplementing only the `getThumbnail()` method. See the [Module.php](https://github.com/acdh-oeaw/AkSearchExtend/blob/master/src/aksearchExt/Module.php) and [SolrMarc.php](https://github.com/acdh-oeaw/AkSearchExtend/blob/master/src/aksearchExt/SolrMarc.php).

## Live development

To be able to live test your module:

* Include it into the AkSearchWeb deployment as described in the *How does it work?* section above.
* Run the aksearch-web container with:
    * Your module's code mounted under `/usr/local/vufind/vendor/{your-organization}/{your-composer-package-name}`.
    * `APPLICATION_ENV` set to `development` (which would turn off Zend2 classmap caching and save you a lot of headache).

## Deploying on sisyphos

To deploy your module on sisyphos instance you must add your module name to the `VUFIND_LOCAL_MODULES` environment variable defined in this instance's `docker-compose.yaml`
(using the Portainer GUI).

## Comparison with clone "AkSearch and adjust" development

Instead of finding a place in the VuFind/AkSearch code we would like to adjust and adjusting it on our very own copy (fork) of the VuFind/AkSearch code:

* Find a place in the VuFind/AkSearch code you would like to adjust.
    * If it's AkSearch code, compare it with a corresponding part in the VuFind (most AkSearch classes inherit from Vufind ones) and assess if there is a space for optimization.
      AkSearch tends to copy-paste VuFind methods it overrides instead of using the "call VuFind implementation and add only what is needed on top of that".
      If it's the case, we would prefer to use VuFind as a base and rewrite both AkSearch and our additions in the "call VuFind implementation and add only what is needed on top of that" way.
* Create a class in your module extending the corresponding VuFind/AkSearch class and implement adjustments you want to introduce
  using the "call the original VuFind/AkSearch method and made your adjustments on its output" (or on the input you pass to it or both).
* Copy-paste configuration mappings for the class you extended from the VuFind/AkSearch module config into your module config and change the mappings to the class you created.


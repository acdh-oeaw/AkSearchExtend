# AkSearchExtend

Extensions of the VuFind/AkSearch for the [ACDH deployment of AkSearch](https://github.com/acdh-oeaw/AkSearchWeb).

Can also serve as an example of extending VuFind with your own module being loaded using Composer (see intstructions below).

## What is being added by this module

* Tuned version of the SolrMarc class (`aksearchExt\SolrMarc`)
* Tuned version of the ILS driver class (`aksearchExt\Alma`)
* Additional record view tab (`aksearchExt\RecordTab\Exemplarspezifika`)
* and more (see the class overrides defined in the `Module.php` for a full picture)

The detailed description of particular features is included in the `docs` folder.

## How does it work?

Remark - description below applies to the current AkSearch which is based on VuFind 6. Some stuff will change with Vufind >= 7 which migrated from Zend2 to Laminas.

### Making the code loadable by Zend2/Laminas

VuFind/AkSearch are written using the Laminas framework and extending/adjusting them goes down to implementation of you own Laminas module(s).

To get your code recognized and properly loaded by the Laminas you must:

* Choose your module name (here it's `aksearchExt`).
* Implement a `{my module name}\Module` class implementing `getAutoloaderConfig()` and `getConfig()` methods.
    * The `getAutoloaderConfig()` should just return an empty array (as composer will deal with autoloading for us and we don't need Laminas for that).
    * The `getConfig()` class should return a Laminas/VuFind module configuration (an empty one is perfectly valid for starters).
    * The minimal implementation of the `aksearchExt` module (making it Laminas-loadable but nothing more than that) would look as follows:
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
* Include your Laminas module name in the list of modules being loaded by the VuFind/AkSearch.
    * Append your module's name (namespace) at the end of the `VUFIND_LOCAL_MODULES` environment variable defined in the last line of the `Dockerfile` in the repository root directory.
    * Append your module's name (namespace) at the end of the `VUFIND_LOCAL_MODULES` environment variable in the `docker-compose.yaml` in the repository root directory
      (so people deploying with just `docker-compose up` have it as well).
* Try to build the image locally by running `docker build -t acdhch/aksearch-web .` in the repository root directory 
  and then try to deploy with `docker-composer up`.
  Finally check if everything stil works by opening http://127.0.0.1/vufind in your browser.
* If everything's fine, commit changes and push the repository to the GitHub.

Congratulations! Now your module is deployed along with the [AkSearchWeb](https://github.com/acdh-oeaw/AkSearchWeb) 
and will be used (or not) depending on the `VUFIND_LOCAL_MODULES` runtime environment variable value (precisely if its name is included in the env var value).

### Overriding actual VuFind/AkSearch code

The most tricky part is to find out the right place to plug in your own adjustment.

The good thing is it's all done by adjusting what your module's `Module::getConfig()` method returns (denoted below as `$cfg`).

The difficult part is you can plug your code in hundreds of places. See e.g. all class mappings provided in [VuFind's main module config](https://biapps.arbeiterkammer.at/gitlab/open/aksearch/aksearch/blob/aksearch/module/VuFind/config/module.config.php) or overrides defined by [AkSearch's AkSearch module config](https://gitlab.com/acdh-oeaw/oeaw-resources/module-core/-/blob/master/config/module.config.php).
**Be aware** that despite providing a class alias you may also need to define factories and/or delegators for your class - see just mentioned examples and the `src/aksearchExt/Module.php` file in this repository.

## Live development

To be able to live test your module:

* Include it into the AkSearchWeb deployment as described in the *How does it work?* section above.
* Run the aksearch-web container with:
    * Your module's code mounted under `/usr/local/vufind/vendor/{your-organization}/{your-composer-package-name}`.
    * `APPLICATION_ENV` set to `development` (which would turn off Laminas classmap caching and save you a lot of headache).

## Usingn phpstan for static code analysis

The phpstan needs access to the AkSearch-core module as our code depends on it, so:

* Clone the AkSearch-core in the repository root
  ```
  git clone --depth 1 -b aksearch-10 https://biapps.arbeiterkammer.at/gitlab/open/aksearch/module-core.git aksearch
  ```
* Obtain phpstan and vufind
  ```
  composer update
  ```
* Run phpstan using provided config file which assures proper loading of AkSearch anf VuFind classes
  ```
  vendor/bin/phpstan analyze -c phpstan.neon -l 4 src/
  ```

# AkSearchExtend

An example of extending the [ACDH deployment of AkSearch](https://github.com/acdh-oeaw/AkSearchWeb) with your very own module being loaded using Composer.

## How does it work?

### Making the code loadable by VuFind/AkSearch

VuFind/AkSearch are written using Zend2 framework and extending/adjusting them goes down to implementation of you own Zend2 module(s).

To get your code being recognized and properly loaded by a Zend2 you must:

* Choose your module name (here it's `aksearchextend`).
* Create a `{my module name}\Module` class implementing `getAutoloaderConfig()` and `getConfig()` methods.
    * The `getAutoloaderConfig()` should just return an empty array (as composer will deal with autoloading for us and we don't need Zend2 for that).
    * The `getConfig()` class should return a Zend2/VuFind module configuration.
    * The minimal implementation for the `vufindextend` module (making it Zend-2 loadable but nothing more than that) would look as follows:
      ```php
      class aksearchextend\Module {
          public function getAutoloaderConfig() {
              return [];
          }
          public function getConfig() {
              return [];
          }
      }
      ```
* Create a `composer.json` for your module making it able to publish it as a composer package.
  Don't forget to define autoloading properly.
  If you need an example, just look at the `composer.json` in this repo root (it publishes this module as a `acdh-oeaw/aksearchextend` composer package).
* Publish your code as a composer package (put it into a publicly reachable git repo and then publish on https://packagist.org/).

### Setting up VuFind/AkSearch to actually load your code

* Make sure composer installs your module as one of dependencies.
  This can be achieved by adding your module's composer package name to the `require` section` in the `composer.json` of the [AkSearchWeb](https://github.com/acdh-oeaw/AkSearchWeb) repo.
  (running `composer require your-organization/your-composer-package-name` in this repository's directory  will also do the job)
* Make sure VuFind/AkSearch pass your module name to the Zend2 application constructor.
  This can be achieved by adding your module's name (namespace) at the end of the `VUFIND_LOCAL_MODULES` environment variable defined in the last line of the `Dockerfile` file of the [AkSearchWeb](https://github.com/acdh-oeaw/AkSearchWeb) repo.
    * You should also add your module's name (namespace) to the definition of the `VUFIND_LOCAL_MODULES` environment variable in the `docker-compose.yaml` of the [AkSearchWeb](https://github.com/acdh-oeaw/AkSearchWeb) repo so people running a sample deployment with just `docker-composer` can benefit from it as well.

After you made changes to the [AkSearchWeb](https://github.com/acdh-oeaw/AkSearchWeb) repo change them locally by trying to build and run the updated Docker image.
In the repository directory run:

```bash
docker build -t acdhcd/aksearch-web .
docker-compose up
```

and check in the browser (http://127.0.0.1/vufind) if everything works fine.

Once there are no errors you can commit your changes and push the AkSearchWeb repo. This will cause updated version of the Docker image to be automatically published.

### Overriding actual VuFind/AkSearch code

The most tricky part is to find out the right place to plug in your own adjustment.

The good thing is it's all done by adjusting what your module's `Module::getConfig()` method returns (denoted below as `$cfg`).

The difficult part is you can plug your code in hundreds of places. See e.g. all class mappings provided in [VuFind's main module config](https://biapps.arbeiterkammer.at/gitlab/open/aksearch/aksearch/blob/aksearch/module/VuFind/config/module.config.php) or overrides defined by [AkSearch's AkSearch module config](https://gitlab.com/acdh-oeaw/oeaw-resources/module-core/-/blob/master/config/module.config.php).

Module provided in this repository just overrides the `getThumbnail()` method of the AkSearch's `SolrMarc` record driver to always return an ARCHE logo.

This is achieved by overriding the `VuFind\RecordDriver\SolrMarc` class with this module's `aksearchextend\SolrMarc` class, where the `aksearchextend\SolrMarc` just extends `AkSearch\RecordDriver\SolrMarc` reimplementing the `getThumbnail()` method. See the [Module.php]() and [SolrMarc.php]().


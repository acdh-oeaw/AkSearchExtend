# Dealing with special characters in search

https://redmine.acdh.oeaw.ac.at/issues/24301

Sample records: 990002338900504498, 990000843630504498

## Problem

Although update Solr (9.5) is ok with the double quotes escaping performed by the VuFind, it returns different search results depending on conditions we do not really understand (the `qf` parameter is the main suspect - see the https://redmine.acdh.oeaw.ac.at/issues/24301).

## Solution

Christoph suggested to just skip problematic characters from the search phrase.

A good point to inject that is the `\VuFindSearch\Backend\Solr\QueryBuilder::build()` method.

To override it we need to override the `plugin_managers.search_backend.factories.Solr' by setting it to our own factory class extending the '\AkSearch\Search\Factory\SolrDefaultBackendFactory`
and overriding the `createQueryBuilder()` method so it instantiates our own `QueryBuilder` class.

Both classes are stored in the `src/aksearchExt/Search` directory.

So far we just skip the / with:

```php
    public function build(AbstractQuery $query, ?ParamBag $params = null)
    {
        $query = clone $query;
        $query->replaceTerm('/', '');
        return parent::build($query, $params);
    }
```

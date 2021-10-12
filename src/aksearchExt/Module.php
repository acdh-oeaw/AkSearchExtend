<?php
namespace aksearchExt;

class Module {
    public function getAutoloaderConfig() {
        return [];
    }

    public function getConfig() {
        return [
            'vufind' => [
                'plugin_managers' => [
                    'recorddriver' => [
                        'factories' => [
                            'aksearchExt\SolrMarc' => 'VuFind\RecordDriver\SolrDefaultFactory'
                        ],
                        'aliases' => [
                            'VuFind\RecordDriver\SolrMarc' => 'aksearchExt\SolrMarc'
                        ],
                        'delegators' => [
                            'aksearchExt\SolrMarc' => [
                                'AkSearch\RecordDriver\IlsAwareDelegatorFactory'
                            ]
                        ]
                    ],
                ],
            ],
        ];
    }
}


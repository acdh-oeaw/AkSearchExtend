<?php
namespace aksearchExt;

class SolrMarc extends \AkSearch\RecordDriver\SolrMarc {
    public function getThumbnail($size = 'small'){
        return 'https://arche.acdh.oeaw.ac.at/browser/themes/contrib/arche-theme/logo.svg';
	}
}


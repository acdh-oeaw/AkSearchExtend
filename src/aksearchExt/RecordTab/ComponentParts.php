<?php
/**
 * The MIT License
 *
 * Copyright 2022 Austrian Centre for Digital Humanities.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @category aksearchExt
 * @package  RecordTabs
 * @author   Norbert Czirjak
 * @link     https://vufind.org/wiki/development:plugins:record_tabs Wiki
 * @link     https://redmine.acdh.oeaw.ac.at/issues/20567
 * 
 */
namespace aksearchExt\RecordTab;

/**
 * ComponentParts TAB
 *
 * @category aksearchExt
 * @package  RecordTabs
 * @author   Norbert Czirjak
 * @license  https://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:record_tabs Wiki
 */
class ComponentParts extends \VuFind\RecordTab\AbstractBase
{
    /**
     * Similar records
     *
     * @var array
     */
    protected $results;

    /**
     * Maximum results to display
     *
     * @var int
     */
    protected $maxResults = 100;

    /**
     * Search service
     *
     * @var \VuFindSearch\Service
     */
    protected $searchService;

    /**
     * Constructor
     *
     * @param \VuFindSearch\Service $search Search service
     */
    public function __construct(\VuFindSearch\Service $search)
    {
        $this->searchService = $search;
    }

    /**
     * Get the on-screen description for this tab.
     *
     * @return string
     */
    public function getDescription()
    {
        return 'child_records';
    }

    /**
     * Is this tab active?
     *
     * @return bool
     */
    public function isActive()
    {
        $children = $this->getRecordDriver()->tryMethod('getChildRecordCount');
        return $children !== null && $children > 0;
    }

    /**
     * Get the maximum result count.
     *
     * @return int
     */
    public function getMaxResults()
    {
        return $this->maxResults;
    }

    /**
     * Get the contents for display.
     *
     * @return array
     */
    public function getResults()
    {
      
        $record = $this->getRecordDriver();
        $safeId = addcslashes($record->getUniqueId(), '"');
        
        
        $query = new \VuFindSearch\Query\Query(
            'hierarchy_parent_id:"' . $safeId . '"'
        );
        
        $params = new \VuFindSearch\ParamBag(
            [
                // Disable highlighting for efficiency; not needed here:
                'hl' => ['false'],
                // Sort appropriately:
                'sort' => 'hierarchy_sequence ASC,title ASC',
            ]
        );
        return $this->searchService->search(
            $record->getSourceIdentifier(),
            $query,
            0,
            // retrieve 1 more than max results, so we know when to
            // display a "more" link:
            $this->maxResults + 1,
            $params
        );
    }
}

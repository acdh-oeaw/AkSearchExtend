<?php
/**
 * AK: Extended record driver view helper
 *
 * PHP version 7
 *
 * Copyright (C) AK Bibliothek Wien 2019.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category AKsearch
 * @package  View_Helpers
 * @author   Michael Birkner <michael.birkner@akwien.at>
 * @license  https://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:view_helpers Wiki
 */

namespace aksearchExt\View\Helper\Root;

/**
 * AK: Extending record driver view helper
 *
 * @category AKsearch
 * @package  View_Helpers
 * @author   Michael Birkner <michael.birkner@akwien.at>
 * @license  https://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:view_helpers Wiki
 */
class Record extends \VuFind\View\Helper\Root\Record
{

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/20567
     * @return type
     */
    public function getContentsPiecesTabData() {
        return $this->driver->getContentsPiecesData();
    }
    
    
    /**
     * Get HTML to render a title.
     * 
     * AK: Added subtitle and title section to the main title, separated by colon
     *     from each other.
     *
     * @param   int     $maxLength Maximum length of non-highlighted title.
     *
     * @return  string  
     */
    public function getTitleHtml($maxLength = 180)
    {
       
        $highlightedTitle = $this->driver->tryMethod('getHighlightedTitle');
        
        // AK: Add title section to the main title, separated by colon from each
        //     other. Info: With getTitle, we already get the main title (245a) and
        //     the subtitle (245b), already separated by colon. With array_filter we
        //     remove possible empty values.
        $title = implode(
            ' : ',
            array_filter(
                [
                    trim($this->driver->tryMethod('getTitle')),
                    trim($this->driver->getTitleSection())
                ],
                array($this, 'filterCallback')
            )
        );

        if (!empty($highlightedTitle)) {
            $highlight = $this->getView()->plugin('highlight');
            $addEllipsis = $this->getView()->plugin('addEllipsis');
            return $highlight($addEllipsis($highlightedTitle, $title));
        }
        if (!empty($title)) {
            $escapeHtml = $this->getView()->plugin('escapeHtml');
            $truncate = $this->getView()->plugin('truncate');
            return $escapeHtml($truncate($title, $maxLength));
        }
        $transEsc = $this->getView()->plugin('transEsc');
        return $transEsc('Title not available');
    }

    /**
     * AK: Callback function for array_filter function in getTitleHtml method.
     * Default array_filter would not only filter out empty or null values, but also
     * the number "0" (as it evaluates to false). So if a title would just be "0" it
     * would not be displayed.
     *
     * @param   string $var The value of an array. In our case these are strings.
     * 
     * @return  boolean     False if $var is null or empty, true otherwise.
     */
    protected function filterCallback($var) {
        // Return false if $var is null or empty
        if ($var == null || trim($var) == '') {
            return false;
        }
        return true;
    }

    

}

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
 */
namespace aksearchExt\RecordTab;

/**
 * Exemplarspezifika TAB
 *
 * @category aksearchExt
 * @package  RecordTabs
 * @author   Norbert Czirjak
 * @license  https://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:record_tabs Wiki
 */
class Exemplarspezifika extends \VuFind\RecordTab\HoldingsILS {
    
    
    public function isActive(): bool {
        //if there us 991 or 992 then is should be true
        return $this->getRecordDriver()->tryMethod('is911992');         
     }
     
    /**
     * AK: Check if this record has parent records
     */
    public function hasParents(): bool {
        return $this->getRecordDriver()->tryMethod('hasParents');
    }

    /**
     * AK: Get parent records in consolidated format
     *
     * @return array|null An array of parent record information or null
     */
    public function getParents(): array | null {
        return $this->getRecordDriver()->tryMethod('getConsolidatedParents');
    }

    /**
     * AK: Check if this record has child records
     */
    public function hasChilds(): bool {
        return $this->getRecordDriver()->tryMethod('hasChilds');
    }

    /**
     * AK: Get summarized holdings.
     */
    public function getSummarizedHoldings(): mixed {
        return $this->getRecordDriver()->tryMethod('getSummarizedHoldings');
    }

    public function getDescription(): string {
        return 'Exemplarspezifika';
    }
}

<?php

/*
 * The MIT License
 *
 * Copyright 2022 zozlak.
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
 */

namespace aksearchExt\View\Helper\Root;

/**
 * Description of RecordDataFormatterFactory
 *
 * @author zozlak
 */
class RecordDataFormatterFactory extends \AkSearch\View\Helper\Root\RecordDataFormatterFactory {

    /**
     * Get default specifications for displaying data in core metadata.
     *
     * 
     * @return array
     */
    public function getDefaultCoreSpecs() {
        $spec = new \VuFind\View\Helper\Root\RecordDataFormatter\SpecBuilder();

        $spec->setTemplateLine(
            'Title supplement to the whole collection', 'getTitleAddition', 'data-titleAddition.phtml'
        );
        
        $spec->setTemplateLine(
            'Statement of responsibility for the whole collection', 'getStatementOfResponsibility', 'data-statementOfResponsibility.phtml'
        );
         
        // AK: Getting parent records with direct link to the parent records detail
        // page.
        $spec->setTemplateLine(
            'Published in', 'getConsolidatedParents', 'data-containerTitle.phtml'
        );

        // AK: Getting authors by role
        $spec->setMultiLine(
            'Authors', 'getContributorsByRole', $this->getAuthorFunction()
        );

        // AK: Removed format
        // TODO: Do we need the format here?
        /*
          $spec->setLine(
          'Format', 'getFormats', 'RecordHelper',
          ['helperMethod' => 'getFormatList']
          );
         */

        // AK: Use custom function for getting publication details
        $spec->setTemplateLine(
            'PublicationDetails', 'getPublicationDetailsAut', 'data-publisher.phtml'
        );

        // AK: Use custom function for getting year of publication. This does get
        // year of publication only if there is no date span available (see below).
        // This is to avoid duplicates.
        $spec->setLine('Year of Publication', 'getPublicationDatesWithoutDateSpan');

        // AK: Get the date span. This is e. g. important for journals or serial
        // publications (e. g. "published from 1960 to 2011"). If we have a date
        // span, no 'Year of Publication' (see above) will be displayed to avoid
        // duplicates.
        $spec->setLine('dateSpan', 'getDateSpan');

        $spec->setLine(
            'Edition', 'getEdition', null,
            ['prefix' => '<span property="bookEdition">', 'suffix' => '</span>']
        );

        // AK: Removed the default display of the language of the record as this
        // does not translate the language name. Now using more arguments in
        // "setLine" method for translating the language name(s) in the records
        // "core" view (= detail view of a record). See also pull request 413 at
        // VuFind GitHub and there especially the "Files changed" section to get an
        // example of the code used here:
        // https://github.com/vufind-org/vufind/pull/413
        $spec->setLine(
            'Language', 'getLanguages', null,
            ['translate' => true, 'translationTextDomain' => 'Languages::']
        );

        // AK: Sowidok - get persons
        $spec->setTemplateLine(
            'SowidokPersonActive', 'getSowidokActivePersons', 'data-sowidokActivePerson.phtml'
        );
        $spec->setTemplateLine(
            'SowidokPersonPassive', 'getSowidokPassivePersons', 'data-sowidokPassivePerson.phtml'
        );
        $spec->setTemplateLine(
            'SowidokPersonActivePassive', 'getSowidokActivePassivePersons', 'data-sowidokActivePassivePerson.phtml'
        );

        // AK: Get preceding titles
        $spec->setTemplateLine(
            'Precedings', 'getPrecedings', 'data-relations.phtml'
        );

        // AK: Get succeeding titles
        $spec->setTemplateLine(
            'Succeedings', 'getSucceedings', 'data-relations.phtml'
        );

        // AK: Get other editions
        $spec->setTemplateLine(
            'OtherEditions', 'getOtherEditions', 'data-relations.phtml'
        );

        // AK: Get other physical forms
        $spec->setTemplateLine(
            'OtherPhysForms', 'getOtherPhysForms', 'data-relations.phtml'
        );

        // AK: Get "issued with" information
        $spec->setTemplateLine(
            'IssuedWith', 'getIssuedWith', 'data-relations.phtml'
        );

        // AK: Get other relations
        $spec->setTemplateLine(
            'OtherRelations', 'getOtherRelations', 'data-relations.phtml'
        );

        $spec->setTemplateLine('Series', 'getSeries', 'data-series.phtml');

        // AK: Added array with key "stackCells" to "context" array. Using the new
        // option "stackCells" allows for saving some display space.
        $spec->setTemplateLine(
            'Subjects', 'getAllSubjectHeadings', 'data-allSubjectHeadings.phtml',
            ['context' => ['stackCells' => true]]
        );

        // ACHD: Get Basisklassifikation (marc 084)
        // https://redmine.acdh.oeaw.ac.at/issues/19501
        $spec->setLine('Classification', 'getClassification');

        // AK: Sowidok - get geographical note
        $spec->setTemplateLine('Region', 'getSowidokGeographical', 'data-sowidokGeographical.phtml');

        $spec->setTemplateLine(
            'child_records', 'getChildRecordCount', 'data-childRecords.phtml',
            ['allowZero' => false]
        );
        $spec->setTemplateLine('Online Access', true, 'data-onlineAccess.phtml');

        // AK: Get access notes
        $spec->setLine('access_note', 'getAccessNotes');

        $spec->setTemplateLine(
            'Related Items', 'getAllRecordLinks', 'data-allRecordLinks.phtml'
        );

        // AK: Added physical description (Marc21 field 300)
        $spec->setTemplateLine('Physical Description', 'getPhysicalDescriptions',
                               'data-bulletList.phtml');

        // AK: Added notes (Marc21 field 500)
        $spec->setTemplateLine('Notes', 'getGeneralNotes', 'data-bulletList.phtml');

        $spec->setTemplateLine('Tags', true, 'data-tags.phtml');
        
        return $spec->getArray();
    }
}

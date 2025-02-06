<?php
/**
 * AK: View helper for the bootstrap-datepicker.
 * See https://bootstrap-datepicker.readthedocs.io
 *
 * PHP version 7
 *
 * Copyright (C) AK Bibliothek Wien 2020.
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
namespace aksearchExt\View\Helper\aksearchExt;

/**
 * AK: View helper for the bootstrap-datepicker.
 *
 * @category AKsearch
 * @package  View_Helpers
 * @author   Michael Birkner <michael.birkner@akwien.at>
 * @license  https://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:view_helpers Wiki
 */
class Datepicker extends \Zend\View\Helper\AbstractHelper
{

    /**
     * AK: The [Site] section of config.ini
     *
     * @var array
     */
    protected $siteConfig;

    /**
     * AK: The active user language
     *
     * @var string
     */
    protected $activerUserLanguage;

    /**
     * AK: Constructor
     *
     * @param array  $siteConfig The [Site] section of config.ini
     * @param string $activerUserLanguage The active user language
     */
    public function __construct(
        array $siteConfig,
        string $activerUserLanguage = 'en'
    ) {
        $this->siteConfig = $siteConfig;
        $this->activerUserLanguage = $activerUserLanguage;
    }


    /**
     * AK: Get the active user language and format for the bootstrap-datepicker.
     *
     * @return string The language formated for the bootstrap-datepicker
     */
    public function getLanguage() {
        $language = $this->activerUserLanguage;
        if (strpos($language, '-') !== false) {
            $languageArr = explode('-', $language);
            $language = strtolower($languageArr[0]).'-'.strtoupper($languageArr[1]);
        }

        return $language;
    }

    /**
     * AK: Translate the PHP notation of the date format to the bootstrap-datepicker
     * date format.
     * 
     * @param string $phpFormat The PHP notation of the date format (e. g. Y-m-d). If
     * not set the value from config.ini->[Site]->displayDateFormat will be used. If
     * that is not set, the default is Y-m-d.
     *
     * @return string The date format in bootstrap-datepicker notation
     */
    public function getFormat($phpFormat = null)
    {
        $replacements = [
            'j' => 'd',
            'd' => 'dd',
            'D' => 'D',
            'l' => 'DD',
            'n' => 'm',
            'm' => 'mm',
            'M' => 'M',
            'F' => 'MM',
            'y' => 'yy',
            'Y' => 'yyyy'
        ];

        $datepickerFormat = strtr(
            ($phpFormat ?? $this->siteConfig['displayDateFormat'] ?? 'Y-m-d'),
            $replacements
        );

        return $datepickerFormat;
    }

}

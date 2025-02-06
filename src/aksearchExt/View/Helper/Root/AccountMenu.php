<?php
/**
 * AK: Account menu view helper
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
 * @category AcdhchTheme
 * @package  View_Helpers
 * @author   Michael Birkner <michael.birkner@akwien.at>
 * @license  https://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:view_helpers Wiki
 */
namespace aksearchExt\View\Helper\Root;

use ZfcRbac\Service\AuthorizationService as AuthorizationService;

/**
 * AK: Account menu view helper
 *
 * @category AKsearch
 * @package  View_Helpers
 * @author   Michael Birkner <michael.birkner@akwien.at>
 * @license  https://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:view_helpers Wiki
 */
class AccountMenu extends \Zend\View\Helper\AbstractHelper
{
    
    /**
     * AuthorizationService for displaying menu items in user account only to
     * specific users.
     *
     * @var AuthorizationService
     */
    protected $authService;

    /**
     * Configuration for [Account] in config.ini
     *
     * @var array
     */
    protected $accountConfig;

    /**
     * Constructor
     *
     * @param array $accountConfig Configuration for [Account] in config.ini
     */
    public function __construct(AuthorizationService $authService,
        array $accountConfig = []) {
        $this->authService = $authService;
        $this->accountConfig = $accountConfig;
    }

    /**
     * Check if a menu item in the user account menu should be hidden or not.
     *
     * @param string   $menuItem The name of the menu item. See the comments for the
     * config in config.ini or menu.phtml for all possible values.
     * 
     * @return boolean true if the menu item should be hidden, false otherwise
     */
    public function isHidden($menuItem = null) {
        // Check if $menuItem and config are set
        if (!$menuItem || !isset($this->accountConfig['hide_user_account_menu'])) {
            // Don't hide the menu item by returning "false"
            return false;
        }

        // Iterate over the config
        foreach($this->accountConfig['hide_user_account_menu'] as $menu) {
            // Split config value and check if there is a condition to use for the
            // authorization service
            $menuArr = explode('|', $menu);
            $condition = (count($menuArr)>1) ? trim($menuArr[0]) : null;
            $itemName = (count($menuArr)>1) ? trim($menuArr[1]) : trim($menuArr[0]);

            // If a menu item name matches, check for conditions and return the
            // correct boolean value to hide/show the menu item
            if ($itemName === trim($menuItem)) {
                if ($condition === null) {
                    // Just hide if there is no condition
                    return true;
                } else {
                    // If we have a condition, check it with the autorization service
                    // and return the appropriate boolean value
                    if ($this->authService->isGranted($condition)) {
                        return false;
                    } else {
                        return true;
                    }
                }
            }
        }

        // Default: don't hide the menu item by returning "false"
        return false;
    }
}
?>
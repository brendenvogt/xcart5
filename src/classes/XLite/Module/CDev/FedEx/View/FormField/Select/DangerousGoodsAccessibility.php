<?php
// vim: set ts=4 sw=4 sts=4 et:

/**
 * Copyright (c) 2011-present Qualiteam software Ltd. All rights reserved.
 * See https://www.x-cart.com/license-agreement.html for license details.
 */

namespace XLite\Module\CDev\FedEx\View\FormField\Select;

/**
 * Dangerous goods/accessibility selector for settings page
 */
class DangerousGoodsAccessibility extends \XLite\View\FormField\Select\Regular
{
    /**
     * Get default options for selector
     *
     * @return array
     */
    protected function getDefaultOptions()
    {
        return array(
            ''             => '',
            'ACCESSIBLE'   => 'Accessible dangerous goods',
            'INACCESSIBLE' => 'Inaccessible dangerous goods',
        );
    }
}

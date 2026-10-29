<?php

/**
 * @file plugins/generic/crossref/classes/DOIPubIdExportPlugin.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DOIPubIdExportPlugin
 *
 * @ingroup plugins
 *
 * @brief Basis class for DOI XML metadata export plugins.
 *
 * OMP does not ship this class, unlike OJS (APP\plugins\DOIPubIdExportPlugin).
 * If OMP adds the class, use it instead of this one.
 */

namespace APP\plugins\generic\crossref\classes;

use APP\template\TemplateManager;

abstract class DOIPubIdExportPlugin extends PubObjectsExportPlugin
{
    /**
     * Show a notice that DOIs are managed on the DOI pages.
     *
     * @copydoc ImportExportPlugin::display()
     */
    public function display($args, $request)
    {
        parent::display($args, $request);
        switch (array_shift($args)) {
            case 'index':
            case '':
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->display($this->getTemplateResource('index.tpl'));
                break;
        }
    }
}

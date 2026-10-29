<?php

/**
 * @file plugins/generic/crossref/classes/PubObjectsExportPlugin.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PubObjectsExportPlugin
 *
 * @ingroup plugins
 *
 * @brief Basis class for XML metadata export plugins.
 *
 * OMP does not ship this class, unlike OJS (APP\plugins\PubObjectsExportPlugin).
 * Only the methods used with the DOI registration framework are included, with the
 * same signatures as in OJS 3.5. If OMP adds the class, use it instead of this one.
 */

namespace APP\plugins\generic\crossref\classes;

use Exception;
use PKP\context\Context;
use PKP\db\DAORegistry;
use PKP\filter\FilterDAO;
use PKP\plugins\ImportExportPlugin;
use PKP\plugins\importexport\PKPImportExportDeployment;

abstract class PubObjectsExportPlugin extends ImportExportPlugin
{
    /**
     * Deposit XML document.
     * This must be implemented in the subclasses, if the action is supported.
     *
     * @param mixed $objects Array of or single published submission, chapter
     * @param Context $context
     * @param string $filename Export XML filename
     *
     * @return bool|array Whether the XML document has been registered, or an array of errors
     */
    abstract public function depositXML($objects, $context, $filename);

    /**
     * Get the export deployment class name.
     *
     * @return string
     */
    abstract public function getExportDeploymentClassName();

    /**
     * Get the XML for selected objects.
     *
     * @param mixed $objects Array of or single published submission, chapter
     * @param string $filter
     * @param Context $context
     * @param bool $noValidation If set to true no XML validation will be done
     * @param null|mixed $outputErrors
     *
     * @return string|bool XML document.
     */
    public function exportXML($objects, $filter, $context, $noValidation = null, &$outputErrors = null)
    {
        $filterDao = DAORegistry::getDAO('FilterDAO'); /** @var FilterDAO $filterDao */
        $exportFilters = $filterDao->getObjectsByGroup($filter);
        assert(count($exportFilters) == 1); // Assert only a single serialization filter
        $exportFilter = array_shift($exportFilters);
        $exportDeployment = $this->_instantiateExportDeployment($context);
        $exportFilter->setDeployment($exportDeployment);
        if ($noValidation) {
            $exportFilter->setNoValidation($noValidation);
        }
        libxml_use_internal_errors(true);
        $exportXml = $exportFilter->execute($objects, true);
        $xml = $exportXml->saveXml();
        $errors = array_filter(libxml_get_errors(), function ($a) {
            return $a->level == LIBXML_ERR_ERROR || $a->level == LIBXML_ERR_FATAL;
        });
        if (!empty($errors)) {
            if ($outputErrors === null) {
                $this->displayXMLValidationErrors($errors, $xml);
            } else {
                $outputErrors = $errors;
            }
        }
        return $xml;
    }

    /**
     * Check if plugin is in test mode.
     *
     * @param Context $context
     *
     * @return bool
     */
    public function isTestMode($context)
    {
        return ($this->getSetting($context->getId(), 'testMode') == 1);
    }

    /**
     * Instantiate the export deployment.
     *
     * @param Context $context
     *
     * @return PKPImportExportDeployment
     */
    public function _instantiateExportDeployment($context)
    {
        $exportDeploymentClassName = $this->getExportDeploymentClassName();
        return new $exportDeploymentClassName($context, $this);
    }

    /**
     * @copydoc ImportExportPlugin::executeCLI()
     */
    public function executeCLI($scriptName, &$args)
    {
        throw new Exception('Not implemented.');
    }

    /**
     * @copydoc ImportExportPlugin::usage()
     */
    public function usage($scriptName)
    {
    }

    /**
     * @copydoc ImportExportPlugin::supportsCLI()
     */
    public function supportsCLI(): bool
    {
        return false;
    }
}

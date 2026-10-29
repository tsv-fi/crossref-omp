<?php
/** @defgroup plugins_generic_crossref Crossref export plugin */

/**
 * @file    plugins/generic/crossref/CrossrefExportDeployment.php*
 * @class   CrossrefExportDeployment
 * @ingroup plugins_importexport_crossref*
 * @brief   Base class configuring the crossref export process to an
 * application's specifics.
 */
namespace APP\plugins\generic\crossref;
use APP\plugins\generic\crossref\classes\DOIPubIdExportPlugin;
use PKP\context\Context;
use PKP\plugins\importexport\PKPImportExportDeployment;
use PKP\plugins\Plugin;

class CrossrefExportDeployment extends PKPImportExportDeployment
{
    // XML attributes
    public const CROSSREF_XMLNS = 'http://www.crossref.org/schema/4.3.7';
    public const CROSSREF_XMLNS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';
    public const CROSSREF_XSI_SCHEMAVERSION = '4.3.7';
    public const CROSSREF_XSI_SCHEMALOCATION = 'https://www.crossref.org/schemas/crossref4.3.7.xsd';
    public const CROSSREF_XMLNS_JATS = 'http://www.ncbi.nlm.nih.gov/JATS1';
    public const CROSSREF_XMLNS_AI = 'http://www.crossref.org/AccessIndicators.xsd';
    public const CROSSREF_XMLNS_REL = 'http://www.crossref.org/relations.xsd';
    public const CROSSREF_XMLNS_XML = 'http://www.w3.org/XML/1998/namespace';

    /** @var Context The current import/export context */
    public $_context;

    /** @var CrossrefExportPlugin $_plugin The current import/export plugin */
    public CrossrefExportPlugin $_plugin;

    /**
     * Constructor
     *
     * @param Context $context
     * @param DOIPubIdExportPlugin $plugin
     */
    public function __construct($context, $plugin)
    {
        parent::__construct($context);
        $this->setPlugin($plugin);
    }

    //
    // Deployment items for subclasses to override
    //
    /**
     * Get the root element name
     *
     * @return string
     */
    public function getRootElementName(): string
    {
        return 'doi_batch';
    }

    /**
     * Get the namespace URN
     *
     * @return string
     */
    public function getNamespace(): string
    {
        return static::CROSSREF_XMLNS;
    }

    /**
     * Get the schema instance URN
     *
     * @return string
     */
    public function getXmlSchemaInstance(): string
    {
        return static::CROSSREF_XMLNS_XSI;
    }

    /**
     * Get the schema version
     *
     * @return string
     */
    public function getXmlSchemaVersion(): string
    {
        return static::CROSSREF_XSI_SCHEMAVERSION;
    }

    /**
     * Get the schema location URL
     *
     * @return string
     */
    public function getXmlSchemaLocation(): string
    {
        return self::CROSSREF_XSI_SCHEMALOCATION;
    }

    /**
     * Get the JATS namespace URN
     *
     * @return string
     */
	function getJATSNamespace() {
		return static::CROSSREF_XMLNS_JATS;
	}

    /**
     * Get the AI namespace URN
     *
     * @return string
     */
	function getAINamespace() {
		return static::CROSSREF_XMLNS_AI;
	}

    /**
     * Get the REL namespace URN
     *
     * @return string
     */
	function getRELNamespace() {
		return static::CROSSREF_XMLNS_REL;
	}

    /**
     * Get the XML namespace URN
     *
     * @return string
     */
    public function getXMLNamespace()
    {
        return static::CROSSREF_XMLNS_XML;
    }

    /**
     * Get the schema filename.
     *
     * @return string
     */
    public function getSchemaFilename(): string
    {
        return $this->getXmlSchemaLocation();
    }

    //
    // Getter/setters
    //
    /**
     * Set the import/export context.
     *
     * @param \PKP\context\Context $context
     */
    public function setContext($context)
    {
        $this->_context = $context;
    }

    /**
     * Get the import/export context.
     *
     * @return \PKP\context\Context
     */
    public function getContext(): Context
    {
        return $this->_context;
    }

    /**
     * Set the import/export plugin.
     *
     * @param CrossrefExportPlugin $plugin
     *
     * @return self
     */
    public function setPlugin(CrossrefExportPlugin $plugin): self
    {
        $this->_plugin = $plugin;
        return $this;
    }

    /**
     * Get the import/export plugin.
     *
     * @return CrossrefExportPlugin
     */
    public function getPlugin(): CrossrefExportPlugin
    {
        return $this->_plugin;
    }
}

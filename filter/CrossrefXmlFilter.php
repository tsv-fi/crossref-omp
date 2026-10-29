<?php

/**
 * @file plugins/generic/crossref/filter/CrossrefXmlFilter.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CrossrefXmlFilter
 *
 * @brief Class that converts a publication to a Crossref XML document.
 */

namespace APP\plugins\generic\crossref\filter;

use APP\author\Author;
use APP\core\Application;
use APP\facades\Repo;
use APP\monograph\Chapter;
use APP\plugins\generic\crossref\CrossrefExportDeployment;
use APP\publication\Publication;
use APP\submission\Submission;
use DOMDocument;
use DOMElement;
use DOMException;
use PKP\core\PKPApplication;
use PKP\context\Context;
use PKP\core\PKPString;
use PKP\filter\FilterGroup;
use PKP\i18n\LocaleConversion;
use PKP\plugins\importexport\native\filter\NativeExportFilter;
use PKP\db\DAORegistry;

class CrossrefXmlFilter extends NativeExportFilter
{
    // Maximum name lengths in the Crossref 5.4.0 schema
    public const SURNAME_MAX_LENGTH = 60;
    public const GIVEN_NAME_MAX_LENGTH = 60;
    public const INSTITUTION_NAME_MAX_LENGTH = 1024;

    /**
     * Constructor
     *
     * @param FilterGroup $filterGroup
     */
    public function __construct($filterGroup)
    {
        $this->setDisplayName('Crossref XML export');
        parent::__construct($filterGroup);
    }

    //
    // Implement template methods from Filter
    //
    /**
     * @param mixed $input
     *
     * @return DOMDocument
     * @throws DOMException
     * @see Filter::process()
     */
    public function &process(&$pubObjects)
    {
        // Create the XML document
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();

        // Create the root node
        $rootNode = $this->createRootNode($doc);
        $doc->appendChild($rootNode);

        // Create and append the 'head' node and all parts inside it
        $rootNode->appendChild($this->createHeadNode($doc));

        // Create and append the 'body' node, that contains everything
        $bodyNode = $doc->createElementNS($deployment->getNamespace(), 'body');
        $rootNode->appendChild($bodyNode);

        foreach ($pubObjects as $pubObject) {
            if (!$pubObject instanceof Submission) {
                throw new Exception('Expected instance of Submission.');
            }
            $bodyNode->appendChild($this->createBookNode($doc, $pubObject));
        }

        return $doc;
    }

    //
    // Conversion functions
    //

    /**
     * Create and return the Crossref root node 'doi_batch'
     *
     * @param DOMDocument $doc
     *
     * @return DOMElement
     */
    public function createRootNode(DOMDocument $doc): DOMElement
    {
        /** @var CrossrefExportDeployment $deployment */		
        $deployment = $this->getDeployment();
        $rootNode = $doc->createElementNS($deployment->getNamespace(), $deployment->getRootElementName());
		$rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', $deployment->getXmlSchemaInstance());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:jats', $deployment->getJATSNamespace());
		$rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ai', $deployment->getAINamespace());
		$rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:rel', $deployment->getRELNamespace());
        $rootNode->setAttribute('version', $deployment->getXmlSchemaVersion());
        $rootNode->setAttribute('xsi:schemaLocation', $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename());
        return $rootNode;
    }


    /**
     * Create and return the Crossref head node 'head'.
     *
     * @param \DOMDocument $doc
     *
     * @return \DOMElement
     */
    public function createHeadNode($doc)
    {
        /** @var CrossrefExportDeployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $plugin = $deployment->getPlugin();
        
        $headNode = $doc->createElementNS($deployment->getNamespace(), 'head');
        $headNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'doi_batch_id', htmlspecialchars($context->getData('acronym', $context->getPrimaryLocale()) . '_' . time(), ENT_COMPAT, 'UTF-8')));
        $timestampNode = $doc->createElementNS($deployment->getNamespace(), 'timestamp', date('YmdHisv'));
        $headNode->appendChild($timestampNode);
        
        $depositorNode = $doc->createElementNS($deployment->getNamespace(), 'depositor');
        $depositorName = $plugin->getSetting($context->getId(), 'depositorName');
        if (empty($depositorName)) {
            $depositorName = $context->getData('supportName');
        }
        $depositorEmail = $plugin->getSetting($context->getId(), 'depositorEmail');
        if (empty($depositorEmail)) {
            $depositorEmail = $context->getData('supportEmail');
        }

        $depositorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'depositor_name', htmlspecialchars($depositorName, ENT_COMPAT, 'UTF-8')));
        $depositorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'email_address', htmlspecialchars($depositorEmail, ENT_COMPAT, 'UTF-8')));
        $headNode->appendChild($depositorNode);
        $publisherName = $context->getLocalizedData('name', $context->getPrimaryLocale());
        $headNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'registrant', htmlspecialchars($publisherName, ENT_COMPAT, 'UTF-8')));
        return $headNode;
    }

    /**
     * Create and return the Crossref book node 'edited_book' or 'monograph'.
     *
     * @param \DOMDocument $doc
     *
     * @return \DOMElement
     */
	function createBookNode($doc, $submission) {
        /** @var CrossrefExportDeployment */
        $deployment = $this->getDeployment();

        $type = ($submission->getData('workType') == Submission::WORK_TYPE_EDITED_VOLUME) ? 'edited_book' : 'monograph';
        $publication = $submission->getCurrentPublication();

        $bookNode = $doc->createElementNS($deployment->getNamespace(), 'book');

		$bookNode->setAttribute('book_type', $type);
		$bookNode->appendChild($this->createBookMetadataNode($doc, $submission, $publication));

        $submissionFiles = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$publication->getData('submissionId')])
            ->getMany();

		$chapters = $publication->getData('chapters');
		foreach ($chapters as $chapter) {
			if ($chapter->getDoi()) {
                $bookNode->appendChild($this->createContentItemNode($doc, $submission, $publication, $chapter, $submissionFiles));
			}            
		}
		return $bookNode;
	}

    /**
     * Create and return the Crossref book metadata node 'book_series_metadata' or 'book_metadata'.
     *
     * @param \DOMDocument $doc
     *
     * @return \DOMElement
     */
	function createBookMetadataNode($doc, $submission, $publication) {
        /** @var CrossrefExportDeployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
		$locale = $publication->getData('locale');
        $series = null;

		// If the book is part of series use book_series_metadata else use book_metadata
		// Consider adding book_set_metadata option in cases where a series does not have an ISSN
        if ($seriesId = $publication->getData('seriesId')) {
            $series = Repo::section()->get($seriesId, $submission->getData('contextId'));
        }

		if ($series && ($series->getOnlineISSN() || $series->getPrintISSN())){
			$bookMetadataNodeType = 'book_series_metadata';
		} else{
			$bookMetadataNodeType = 'book_metadata';
		}

		$bookMetadataNode = $doc->createElementNS($deployment->getNamespace(), $bookMetadataNodeType);
		$bookMetadataNode->setAttribute('language', LocaleConversion::getIso1FromLocale($locale));

		// If a series and the series has ISSN, add series metadata
		if ($series && ($series->getOnlineISSN() || $series->getPrintISSN())) {
			$bookMetadataNode->appendChild($this->createSeriesMetadataNode($doc, $series));
		}

		// Contributors: editors and authors
		if ($authors = $publication->getData('authors')) {
            // For an edited volume only the volume editors are listed at book level,
            // the chapter authors are listed in their chapters
            if ($submission->getData('workType') == Submission::WORK_TYPE_EDITED_VOLUME) {
                $volumeEditors = [];
                foreach ($authors as $author) {
                    if ($author->getData('isVolumeEditor')) {
                        $volumeEditors[] = $author;
                    }
                }
                if (!empty($volumeEditors)) {
                    $authors = $volumeEditors;
                }
            }
            if ($contributorsNode = $this->createContributorsNode($doc, $authors, $locale)) {
                $bookMetadataNode->appendChild($contributorsNode);
            }
		}

		// Book title, with its prefix ("The", "Le") and as plain text. A title without the prefix
		// does not match the title Crossref already has for the ISBN and the deposit is refused.
		$titleLocale = null;
		$title = $this->plainText($publication->getLocalizedData('title', $locale, $titleLocale));
		if ($titleLocale && ($prefix = $this->plainText($publication->getData('prefix', $titleLocale)))) {
			$title = $prefix . ' ' . $title;
		}
		$titlesNode = $doc->createElementNS($deployment->getNamespace(), "titles");
		$titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), "title", $this->xmlEscape($title)));
		if ($titleLocale && ($subtitle = $this->plainText($publication->getData('subtitle', $titleLocale)))){
			$titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'subtitle', $this->xmlEscape($subtitle)));
		}
		$bookMetadataNode->appendChild($titlesNode);

		// Abstracts
		$this->appendAbstractNodes($doc, $bookMetadataNode, $publication->getData('abstract'));

		// Book publication date
		$datePublished = $publication->getData('datePublished');
		$publicationDateNode = $doc->createElementNS($deployment->getNamespace(), "publication_date");
		$publicationDateNode->setAttribute('media_type', 'online');
		$publicationDateNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'month', $this->xmlEscape( date('m', strtotime($datePublished)) )));
		$publicationDateNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'day', $this->xmlEscape( date('d', strtotime($datePublished)) )));
		$publicationDateNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'year', $this->xmlEscape( date('Y', strtotime($datePublished)) )));
		$bookMetadataNode->appendChild($publicationDateNode);

		// ISBN
		$publicationFormats = $publication->getData('publicationFormats');
		$noIsbn = true;
		foreach ($publicationFormats as $publicationFormat) {
			$identificationCodes = $publicationFormat->getIdentificationCodes();
			while ($identificationCode = $identificationCodes->next()) {
				if ($identificationCode->getCode() == "02" || $identificationCode->getCode() == "15") {
					// 02 and 15: ONIX codes for ISBN-10 or ISBN-13
					$isbnNode = $doc->createElementNS($deployment->getNamespace(), 'isbn', $this->xmlEscape($identificationCode->getValue()));
					$isbnNode->setAttribute('media_type', $publicationFormat->getPhysicalFormat() ? 'print' : 'electronic');
					$bookMetadataNode->appendChild($isbnNode);
					$noIsbn = false;
				}
			}
		}
		if ($noIsbn){
			$noIsbnNode = $doc->createElementNS($deployment->getNamespace(), 'noisbn');
			$noIsbnNode->setAttribute('reason', 'archive_volume'); // Consider OMP book setting for noisbn?
			$bookMetadataNode->appendChild($noIsbnNode);
		}

		// Book publisher
		$publisherNode = $doc->createElementNS($deployment->getNamespace(), 'publisher');
		$publisherNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'publisher_name', $this->xmlEscape($context->getData('publisher'))));
        $location = $context->getSetting('location');
        if (!empty($location)) {
            $publisherNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'publisher_place', $location));
        }
		$bookMetadataNode->appendChild($publisherNode);

		// License
		if ($licenseUrl = $publication->getData('licenseUrl')) {
			$bookMetadataNode->appendChild($this->createLicenseNode($doc, $licenseUrl));
		}

		// DOI data
        $request = Application::get()->getRequest();
        $dispatcher = $this->_getDispatcher($request);
        $url = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'catalog', 'book', [$submission->getBestId()], null, null, true, '');
		$doi = $publication->getDoi();
		$doiDataNode = $doc->createElementNS($deployment->getNamespace(), "doi_data");
		$doiDataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'doi', $this->xmlEscape($doi)));
		$doiDataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'resource', $this->xmlEscape($url)));
		$bookMetadataNode->appendChild($doiDataNode);

        //Citations
        // citationsRaw is a Stringable object, so check the string value
        if (trim((string) $publication->getData('citationsRaw')) !== '') {
            $bookMetadataNode->appendChild($this->createCitationsNode($doc, $publication));
        }

		return $bookMetadataNode;
	}

    /**
     * Create and return the Crossref book series metadata node 'series_metadata'.
     *
     * @param \DOMDocument $doc
     *
     * @return \DOMElement
     */    
	function createSeriesMetadataNode($doc, $series) {
            $deployment = $this->getDeployment();
            $context = $deployment->getContext();

			$seriesMetadataNode = $doc->createElementNS($deployment->getNamespace(), 'series_metadata');

			// Series title
			$titlesNode = $doc->createElementNS($deployment->getNamespace(), "titles");

			// Always use press primary locale for series title, because only one series title translation can be attached to an ISSN in Crossref registry!
			$titlesNode->appendChild($doc->createElementNS($deployment->getNamespace(), "title", $this->xmlEscape($series->getData('title', $context->getPrimaryLocale()))));
			$seriesMetadataNode->appendChild($titlesNode);

			// Series ISSN
			if ($series->getOnlineISSN()) {
				$issnNode = $doc->createElementNS($deployment->getNamespace(), "issn", $this->xmlEscape($series->getOnlineISSN()));
				$issnNode->setAttribute('media_type', 'electronic');
			} elseif ($series->getPrintISSN()){
				$issnNode = $doc->createElementNS($deployment->getNamespace(), "issn", $this->xmlEscape($series->getPrintISSN()));
				$issnNode->setAttribute('media_type', 'print');
			} else{
				throw new \Exception("Series has no ISSN!");
			}
			$seriesMetadataNode->appendChild($issnNode);

		return $seriesMetadataNode;
	}

    /**
     * Create and return the Crossref book chapter node 'content_item'.
     *
     * @param \DOMDocument $doc
     *
     * @return \DOMElement
     */        
	function createContentItemNode($doc, $submission, $publication, $chapter, $submissionFiles) {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
		$locale = $publication->getData('locale');

		$contentItemNode = $doc->createElementNS($deployment->getNamespace(), 'content_item');
		$contentItemNode->setAttribute('language', LocaleConversion::getIso1FromLocale($locale));
		$contentItemNode->setAttribute('component_type', 'chapter');

        $chapterFilesExist = false;
        foreach ($submissionFiles as $submissionFile) { /** @var SubmissionFile $submissionFile */
            if ($submissionFile->getData('chapterId') == $chapter->getId()) {
                $chapterFilesExist = true;
            }
        }

		if ($chapterFilesExist) {
			$contentItemNode->setAttribute('publication_type', 'full_text');
		} elseif ($chapter->getLocalizedData('abstract', $locale)) {
			$contentItemNode->setAttribute('publication_type', 'abstract_only');
		} else {
			$contentItemNode->setAttribute('publication_type', 'bibliographic_record');
		}

		// Chapter authors        
        if ($chapter->getAuthors()) {
            if ($contributorsNode = $this->createContributorsNode($doc, $chapter->getAuthors(), $locale, true)) {
                $contentItemNode->appendChild($contributorsNode);
            }
        }

		// Chapter title
		$titlesNode = $doc->createElementNS($deployment->getNamespace(), "titles");
		$titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), "title", $this->xmlEscape($chapter->getLocalizedData('title', $locale))));
		if ($subtitle = $chapter->getLocalizedData('subtitle', $locale)){
			$titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'subtitle', $this->xmlEscape($subtitle)));
		}
		$contentItemNode->appendChild($titlesNode);

		// Abstracts
		$this->appendAbstractNodes($doc, $contentItemNode, $chapter->getData('abstract'));

		// Date published
		$datePublished = $publication->getData('datePublished');
		$publicationDateNode = $doc->createElementNS($deployment->getNamespace(), "publication_date");
		$publicationDateNode->setAttribute('media_type', 'online');
		$publicationDateNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'month', $this->xmlEscape( date('m', strtotime($datePublished)) )));
		$publicationDateNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'day', $this->xmlEscape( date('d', strtotime($datePublished)) )));
		$publicationDateNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'year', $this->xmlEscape( date('Y', strtotime($datePublished)) )));
		$contentItemNode->appendChild($publicationDateNode);

        // Pages
        // Add <pages> element only if a valid page range or a single page is specified.
        // Accepts page ranges like "10-20" with optional spaces, or a single page like "10".
        // This avoids Crossref validation errors when ambiguous formats are provided.
        if ($chapter->getPages()) {
            $pagesText = trim($chapter->getPages());

            // Match a page range like "10-20" or "10 – 20"
            if (preg_match('/^(\d+)\s*[-–]\s*(\d+)$/', $pagesText, $matches)) {
                $pagesNode = $doc->createElementNS($deployment->getNamespace(), 'pages');
                $pagesNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'first_page', $this->xmlEscape($matches[1])));
                $pagesNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'last_page', $this->xmlEscape($matches[2])));
                $contentItemNode->appendChild($pagesNode);
            }
            // Match a single page like "10"
            elseif (preg_match('/^\d+$/', $pagesText)) {
                $pagesNode = $doc->createElementNS($deployment->getNamespace(), 'pages');
                $pagesNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'first_page', $this->xmlEscape($pagesText)));
                $contentItemNode->appendChild($pagesNode);
            }
        }

		// License: the chapter license, otherwise the book license
		if ($licenseUrl = $chapter->getLicenseUrl() ?: $publication->getData('licenseUrl')) {
			$contentItemNode->appendChild($this->createLicenseNode($doc, $licenseUrl));
		}

		// DOI data
        $request = Application::get()->getRequest();
        $dispatcher = $this->_getDispatcher($request);
        $doi = $chapter->getDoi();
        
        $url = $dispatcher->url(
            $request,
            PKPApplication::ROUTE_PAGE,
            $context->getPath(),
            'catalog',
            'book',
            $chapter->isPageEnabled() == 1
                ? [$submission->getBestId(), 'chapter', $chapter->getId()]
                : [$submission->getBestId()],
            null,
            null,
            true,
            ''
        );

        $doiDataNode = $doc->createElementNS($deployment->getNamespace(), "doi_data");
		$doiDataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'doi', $this->xmlEscape($doi)));
		$doiDataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'resource', $this->xmlEscape($url)));
		$contentItemNode->appendChild($doiDataNode);

		return $contentItemNode;
	}

    /**
     * Create and return the Crossref book or chapter contributors node 'contributors'.
     *
     * @param \DOMDocument $doc
     *
     * @return \DOMElement
     */        
	function createContributorsNode($doc, $authors, $locale, $isChapter = false) {
        /** @var CrossrefExportDeployment */
        $deployment = $this->getDeployment();

		$contributorsNode = $doc->createElementNS($deployment->getNamespace(), 'contributors');

        $isFirst = true;
        foreach ($authors as $author) { /** @var Author $author */
            $personNameNode = $doc->createElementNS($deployment->getNamespace(), 'person_name');

            if ($isChapter || !$author->getData('isVolumeEditor')) {
                $personNameNode->setAttribute('contributor_role', 'author');
            } else {
                $personNameNode->setAttribute('contributor_role', 'editor');
            }

            if ($isFirst) {
                $personNameNode->setAttribute('sequence', 'first');
            } else {
                $personNameNode->setAttribute('sequence', 'additional');
            }

            $familyNames = $author->getFamilyName(null);
            $givenNames = $author->getGivenName(null);

            // Check if both givenName and familyName is set for the submission language.
            if (!empty($familyNames[$locale]) && !empty($givenNames[$locale])) {
                $personNameNode->setAttribute('language', \Locale::getPrimaryLanguage($locale));
                $personNameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'given_name', htmlspecialchars(mb_substr($givenNames[$locale], 0, self::GIVEN_NAME_MAX_LENGTH), ENT_COMPAT, 'UTF-8')));
                $personNameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars(mb_substr($familyNames[$locale], 0, self::SURNAME_MAX_LENGTH), ENT_COMPAT, 'UTF-8')));
            } else {
                $personNameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars(mb_substr($givenNames[$locale], 0, self::SURNAME_MAX_LENGTH), ENT_COMPAT, 'UTF-8')));
            }

            $affiliationsNode = null;
            foreach ($author->getAffiliations() as $affiliation) {
                $institutionName = $affiliation->getLocalizedName($locale);
                if (trim($institutionName ?? '') === '') {
                    continue;
                }
                if ($affiliationsNode === null) {
                    $affiliationsNode = $doc->createElementNS($deployment->getNamespace(), 'affiliations');
                }
                $institutionNode = $doc->createElementNS($deployment->getNamespace(), 'institution');
                $institutionNameNode = $doc->createElementNS($deployment->getNamespace(), 'institution_name', htmlspecialchars(mb_substr($institutionName, 0, self::INSTITUTION_NAME_MAX_LENGTH), ENT_COMPAT, 'UTF-8'));
                $institutionNode->appendChild($institutionNameNode);
                $rorId = $affiliation->getRor();
                if ($rorId) {
                    $institutionIdNode = $doc->createElementNS($deployment->getNamespace(), 'institution_id', $rorId);
                    $institutionIdNode->setAttribute('type', 'ror');
                    $institutionNode->appendChild($institutionIdNode);
                }
                $affiliationsNode->appendChild($institutionNode);
            }
            if ($affiliationsNode !== null) {
                $personNameNode->appendChild($affiliationsNode);
            }

            if ($author->getData('orcid')) {
                $orcidNode = $doc->createElementNS($deployment->getNamespace(), 'ORCID', $author->getData('orcid'));
                $orcidAuthenticated = $author->getData('orcidIsVerified') ? 'true' : 'false';
                $orcidNode->setAttribute('authenticated', $orcidAuthenticated);
                $personNameNode->appendChild($orcidNode);
            }

            if (!empty($familyNames[$locale]) && !empty($givenNames[$locale])) {
                $hasAltName = false;
                foreach ($familyNames as $otherLocal => $familyName) {
                    if ($otherLocal != $locale && isset($familyName) && !empty($familyName)) {
                        if (!$hasAltName) {
                            $altNameNode = $doc->createElementNS($deployment->getNamespace(), 'alt-name');
                            $personNameNode->appendChild($altNameNode);
                            $hasAltName = true;
                        }

                        $nameNode = $doc->createElementNS($deployment->getNamespace(), 'name');
                        $nameNode->setAttribute('language', \Locale::getPrimaryLanguage($otherLocal));

                        $nameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars(mb_substr($familyName, 0, self::SURNAME_MAX_LENGTH), ENT_COMPAT, 'UTF-8')));
                        if (isset($givenNames[$otherLocal]) && !empty($givenNames[$otherLocal])) {
                            $nameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'given_name', htmlspecialchars(mb_substr($givenNames[$otherLocal], 0, self::GIVEN_NAME_MAX_LENGTH), ENT_COMPAT, 'UTF-8')));
                        }

                        $altNameNode->appendChild($nameNode);
                    }
                }
            }

            $contributorsNode->appendChild($personNameNode);
            $isFirst = false;
        }

        if (!$contributorsNode->hasChildNodes()) {
            return null;
        }

		return $contributorsNode;
	}

    /**
     * Create and return the Crossref citations node 'citation_list'.
     * This includes all raw citations entered in the metadata of the publication.
     * 
     * If parsed citations are available in the database, they will be used.
     * Otherwise, the method will fall back to using the raw citation text stored
     * in the 'citationsRaw' field of the publication.
     *
     * Each citation will be added as an individual 'citation' element
     * containing an 'unstructured_citation' node.
     *
     * @param \DOMDocument $doc The DOM document used to create XML nodes.
     * @param \APP\publication\Publication $publication The publication object containing citation data.
     *
     * @return \DOMElement|null The 'citation_list' element to be added to the XML, or null if an error occurs.
     */
    function createCitationsNode($doc, $publication)
    {
        $deployment = $this->getDeployment();
        $citationsNode = $doc->createElementNS($deployment->getNamespace(), 'citation_list');
        $citationDao = DAORegistry::getDAO('CitationDAO'); /* @var $citationDao CitationDAO */
        $parsedCitations = $citationDao->getByPublicationId($publication->getId())->toArray();

        $citationList = [];
        if ($parsedCitations) {
            foreach ($parsedCitations as $citation) {
                $citationList[] = $citation->getData('rawCitation');
            }
        } else {
            $citationList = explode("\n", (string) $publication->getData('citationsRaw'));
        }
        $citationList = array_values(array_filter(array_map('trim', $citationList), 'strlen'));

        foreach ($citationList as $key => $citation) {
            $citationNode = $doc->createElementNS($deployment->getNamespace(), 'citation');
            $citationNode->setAttribute('key', 'ref' . ($key + 1));
            $unstructuredcitationNode = $doc->createElementNS($deployment->getNamespace(), 'unstructured_citation', $this->xmlEscape($citation));
            $citationNode->appendChild($unstructuredcitationNode);
            $citationsNode->appendChild($citationNode);
        }

        return $citationsNode;
    }

    //
    // Helper functions
    //

    /**
     * Append a 'jats:abstract' node for each abstract language.
     *
     * @param \DOMDocument $doc
     * @param \DOMElement $parentNode
     * @param ?array $abstracts Abstracts keyed by locale
     */
    protected function appendAbstractNodes($doc, $parentNode, $abstracts)
    {
        $deployment = $this->getDeployment();
        foreach ($abstracts ?: [] as $lang => $abstract) {
            if (trim(strip_tags((string) $abstract)) === '') {
                continue;
            }
            $abstractNode = $doc->createElementNS($deployment->getJATSNamespace(), 'jats:abstract');
            $abstractNode->setAttributeNS($deployment->getXMLNamespace(), 'xml:lang', LocaleConversion::toBcp47($lang));
            $abstractNode->appendChild($node = $doc->createElementNS($deployment->getJATSNamespace(), 'jats:p', htmlspecialchars(html_entity_decode(strip_tags($abstract), ENT_COMPAT, 'UTF-8'), ENT_COMPAT, 'UTF-8')));
            $parentNode->appendChild($abstractNode);
        }
    }

    /**
     * Create and return the access indicators node 'ai:program' with the license URL.
     *
     * @param \DOMDocument $doc
     * @param string $licenseUrl
     *
     * @return \DOMElement
     */
    protected function createLicenseNode($doc, $licenseUrl)
    {
        $deployment = $this->getDeployment();
        $licenseNode = $doc->createElementNS($deployment->getAINamespace(), 'ai:program');
        $licenseNode->setAttribute('name', 'AccessIndicators');
        $licenseNode->appendChild($node = $doc->createElementNS($deployment->getAINamespace(), 'ai:license_ref', htmlspecialchars($licenseUrl, ENT_COMPAT, 'UTF-8')));
        return $licenseNode;
    }

    /**
     * Helper to ensure dispatcher is available even when called from CLI tools
     *
     */
    protected function _getDispatcher(\APP\core\Request $request): \PKP\core\Dispatcher
    {
        $dispatcher = $request->getDispatcher();
        if ($dispatcher === null) {
            $dispatcher = Application::get()->getDispatcher();
        }

        return $dispatcher;
    }

    /**
     * Convert a value that may contain HTML markup and entities to plain text.
     */
    protected function plainText($value): string
    {
        return trim(html_entity_decode(strip_tags((string) ($value ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    function xmlEscape($value): string {
        return htmlspecialchars(trim((string) ($value ?? '')), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

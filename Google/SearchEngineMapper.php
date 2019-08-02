<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\GoogleAnalyticsImporter\Google;


use Piwik\Container\StaticContainer;
use Piwik\Plugins\Referrers\SearchEngine;
use Psr\Log\LoggerInterface;

class SearchEngineMapper
{
    private $sourcesToSearchEngines = [];

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;

        $searchEngines = SearchEngine::getInstance();
        foreach ($searchEngines->getDefinitions() as $definition) {
            $lowerName = strtolower($definition['name']);
            $this->sourcesToSearchEngines[$lowerName] = $definition;

            $simpleName = preg_replace('/[^a-zA-Z0-9]/', '', $lowerName);
            $this->sourcesToSearchEngines[$simpleName] = $definition;
        }
        $this->sourcesToSearchEngines['search-results'] = $this->sourcesToSearchEngines['ask'];
    }

    public function mapSourceToSearchEngine($source)
    {
        $lowerSource = strtolower($source);
        if (isset($this->sourcesToSearchEngines[$lowerSource])) {
            return $this->sourcesToSearchEngines[$lowerSource]['name'];
        }

        $simpleName = preg_replace('/[^a-zA-Z0-9]/', '', $lowerSource);
        if (isset($this->sourcesToSearchEngines[$simpleName])) {
            return $this->sourcesToSearchEngines[$simpleName]['name'];
        }

        $this->logger->warning("Unknown search engine source received from Google Analytics: $source");
        return $source;
    }

    public function mapReferralMediumToSearchEngine($medium)
    {
        $searchEngines = SearchEngine::getInstance();
        $definition = $searchEngines->getDefinitionByHost($medium);
        if (empty($definition)) {
            return null;
        }
        return $definition['name'];
    }
}
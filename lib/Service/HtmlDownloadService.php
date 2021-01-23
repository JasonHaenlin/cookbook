<?php

namespace OCA\Cookbook\Service;

use OCA\Cookbook\Exception\ImportException;
use OCA\Cookbook\Helper\HTMLFilter\AbstractHTMLFilter;
use OCA\Cookbook\Helper\HTMLFilter\HTMLEntiryDecodeFilter;
use OCP\IL10N;
use OCP\ILogger;

class HtmlDownloadService {
	
	/**
	 * Indicates the parsing was successfully terminated
	 * @var integer
	 */
	public const PARSING_SUCCESS = 0;
	/**
	 * Indicates the parsing terminated with warnings
	 * @var integer
	 */
	public const PARSING_WARNING = 1;
	/**
	 * Indicates the parsing terminated with an error
	 * @var integer
	 */
	public const PARSING_ERROR = 2;
	/**
	 * Indicates that the parsing terminated with a fatal error
	 * @var integer
	 */
	public const PARSING_FATAL_ERROR = 3;
	
	/**
	 * @var array
	 */
	private $htmlFilters;
	
	/**
	 * @var ILogger
	 */
	private $logger;
	
	/**
	 * @var IL10N
	 */
	private $l;
	
	/**
	 * @var \DOMDocument
	 */
	private $dom;
	
	public function __construct(HTMLEntiryDecodeFilter $htmlEntityDecodeFilter, ILogger $logger, IL10N $l10n) {
		$this->htmlFilters = [ $htmlEntityDecodeFilter ];
		$this->logger = $logger;
		$this->l = $l10n;
		$this->dom = null;
	}
	
	/**
	 * Download a recipe URL and extract the JSON from it
	 *
	 * The return value is one of the constants of the class PARSE_SUCCESS, PARSE_WARNING,
	 * PARSE_ERROR, or PARSE_FATAL_ERROR.
	 *
	 * @param int The indicator if the HTML page was correctly parsed
	 * @return array The included JSON data array, unfiltered
	 * @throws ImportException
	 */
	public function downloadRecipe(string $url): int {
		$html = $this->fetchHtmlPage($url);
		
		// Filter the HTML code
		/** @var AbstractHTMLFilter $filter */
		foreach ($this->htmlFilters as $filter) {
			$filter->apply($html);
		}
		
		return $this->loadHtmlString($html, $url);
	}
	
	/**
	 * Get the HTML docuemnt after it has been downloaded and parsed with downloadRecipe()
	 * @return \DOMDocument The loaded HTML document
	 */
	public function getDom(): \DOMDocument {
		return $this->dom;
	}

	/**
	 * Fetch a HTML page from the internet
	 * @param string $url The URL of the page to fetch
	 * @throws ImportException If the given URL was not fetched
	 * @return string The content of the page as a plain string
	 */
	private function fetchHtmlPage(string $url): string {
		$host = parse_url($url);
		
		if (!$host) {
			throw new ImportException($this->l->t('Could not parse URL'));
		}
		
		$opts = [
			"http" => [
				"method" => "GET",
				"header" => "User-Agent: Nextcloud Cookbook App"
			]
		];
		
		$context = stream_context_create($opts);
		
		$html = file_get_contents($url, false, $context);
		
		if ($html === false) {
			throw new ImportException($this->l->t('Could not parse HTML code for site {url}', $url));
		}
		
		return $html;
	}
	
	/**
	 * @param string $html The HTML code to parse
	 * @param string $url The URL of the parsed recipe
	 * @throws ImportException If the parsing of the HTML page failed completely
	 * @return int Indicator of the parsing state
	 */
	private function loadHtmlString(string $html, string $url): int {
		$this->dom = new \DOMDocument();
		
		$libxml_previous_state = libxml_use_internal_errors(true);
		
		try {
			$parsedSuccessfully = $this->dom->loadHTML($html);
			
			// Error handling
			$errors = libxml_get_errors();
			$result = $this->checkXMLErrors($errors, $url);
			libxml_clear_errors();
			
			if (!$parsedSuccessfully) {
				throw new ImportException($this->l->t('Parsing of HTML failed.'));
			}
		} finally {
			libxml_use_internal_errors($libxml_previous_state);
		}
		
		return $result;
	}
	
	/**
	 * Compress the xml errors to small mount of log messages
	 *
	 * The return value indicates what the most critical value was.
	 * The value can be PARSING_SUCCESS, PARSING_WARNING, PARSING_ERROR, or PARSING_FATAL_ERROR.
	 *
	 * @param array $errors The array of all parsed errors
	 * @param string $url The parsed URL
	 * @return int Indicator what the most severe issue was
	 */
	private function checkXMLErrors(array $errors, string $url): int {
		$error_counter = [];
		$by_error_code = [];
		
		foreach ($errors as $error) {
			if (array_key_exists($error->code, $error_counter)) {
				$error_counter[$error->code] ++;
			} else {
				$error_counter[$error->code] = 1;
				$by_error_code[$error->code] = $error;
			}
		}
		
		$return = self::PARSING_SUCCESS;
		
		/**
		 * @var int $code
		 * @var int $count
		 */
		foreach ($error_counter as $code => $count) {
			/** @var \LibXMLError $error */
			$error = $by_error_code[$code];
			
			// Collect data for translations
			$params = [];
			$params['code'] = $error->code;
			$params['count'] = $count;
			$params['url'] = $url;
			$params['line'] = $error->line;
			$params['column'] = $error->column;
			
			switch ($error->level) {
				case LIBXML_ERR_WARNING:
					$error_message = $this->l->t("Warning {code} occurred {count} times while parsing {url}.", $params);
					$return = max($return, self::PARSING_WARNING);
					break;
				case LIBXML_ERR_ERROR:
					$error_message = $this->l->t("Error {code} occurred {count} times while parsing {url}.", $params);
					$return = max($return, self::PARSING_ERROR);
					break;
				case LIBXML_ERR_FATAL:
					$error_message = $this->l->t("Fatal error {code} occurred {count} times while parsing {url}.", $params);
					$return = max($return, self::PARSING_FATAL_ERROR);
					break;
				default:
					throw new \Exception($this->l->t('Unsupported error level during parsing of XML output.'));
			}
			
			$last_occurence = $this->l->t('Last time it occurred in line {line} and column {column}', $params);
			
			$error_message = "libxml: $error_message $last_occurence: " . $error->message;
			$this->logger->warning($error_message);
		}
		
		return $return;
	}
}
<?php
/**
*
*@package phpSesame
*/

require_once "HTTP/Request2.php";//The PEAR base directory must be in your include_path

require_once "resultFormats.php";

/**
 * This Class is an interface to Sesame. It connects and perform queries through http.
 *
 * This class requires Sesame running in a servlet container (tested on tomcat).
 * The class does not perform all the operation implemented in the sesame http api but
 * does the minimal work needed for hyperJournal. You can find more details about Sesame
 * and its HTTP API at www.openrdf.org
 *
 * Based on the phesame library, http://www.hjournal.org/phesame/
 *
 * @author Alex Latchford, modifications by Julian Klotz
 * @version 0.1
 */
class phpSesame
{
	// Return MIME types
	const SPARQL_XML = 'application/sparql-results+xml';
	const SPARQL_JSON = 'application/sparql-results+json';			// Unsupported - No results handler
	const BINARY_TABLE = 'application/x-binary-rdf-results-table';	// Unsupported - No results handler
	const BOOLEAN = 'text/boolean';									// Unsupported - No results handler

	// Input MIME Types
	const RDFXML = 'application/rdf+xml';
	const NTRIPLES = 'text/plain';
	const TURTLE = 'application/x-turtle';
	const N3 = 'text/rdf+n3';
	const TRIX = 'application/trix';
	const TRIG = 'application/x-trig';

	//const RDFTRANSACTION = 'application/x-rdftransaction';	// Unsupported, needs more documentation (http://www.franz.com/agraph/allegrograph/doc/http-protocol.html#header3-67)

	/**
	 * @var string connection string
	 */
	private $dsn;
	/**
	 * @var string the selected repository
	 */
	private $repository;

	/**
	 * @var string the charset param to append to HTTP-Content-Type field
	 */
	private $queryEncoding = 'utf-8';
	
	/**
	 * @var array The authentication data to use
	 */
	private $auth = array(
		'user' => null,
		'password' => null,
	);

	/**
	 * 
	 *
	 * @param	string	$sesameUrl		Sesame server connection string
	 * @param	string	$repository		The repository name
	 */
	function __construct($sesameUrl = 'http://localhost:8080/openrdf-sesame', $repository = null)
	{
		$this->dsn = $sesameUrl;
		$this->setRepository($repository);
	}

	/**
	 * Sets the charset param that is appended to content type http header
	 *
	 * @param {String} $encoding The encoding string
	 * @return {String} The encoding that was set
	 */
	public function setQueryEncoding($encoding)
	{
		$this->queryEncoding = $encoding;
		return $this->queryEncoding;
	}

	/**
	 * Selects a repository to work on
	 *
	 * @return	void
	 * @param	string	$rep	The repository name
	 */
	public function setRepository($rep)
	{
		$this->repository = $rep;
	}

	/**
	 * Set authentication data for requests.
	 *
	 * @param {string} $user The user name
	 * @param {password} $password The password to use
	 */
	public function setAuth($user, $password) 
	{
		$this->auth['user'] = $user;
		$this->auth['password'] = $password;
		return $this->auth;
	}

	/**
	 * Gets a list of all the available repositories on the Sesame installation
	 *
	 * @return	phpSesame_SparqlRes
	 */
	public function listRepositories()
	{
		$request =& new HTTP_Request2($this->dsn . '/repositories', HTTP_Request2::METHOD_GET);
		$request = $this->prepareRequest($request);
		$request->setHeader('Accept: ' . self::SPARQL_XML);

		$response = $request->send();
		if($response->getStatus() != 200)
		{
			throw new Exception ('Phesame engine response error');
		}

		return new phpSesame_SparqlRes($response->getBody());
	}

	private function checkRepository()
	{
		if (empty($this->repository) || $this->repository == '')
		{
			throw new Exception ('No repository has been selected.');
		}
	}

	private function checkQueryLang($queryLang)
	{
		if ($queryLang != 'sparql' && $queryLang != 'serql')
		{
			throw new Exception ('Please supply a valid query language, SPARQL or SeRQL supported.');
		}
	}

	/**
	 * @todo	Add in the other potentially supported formats once handlers have been written.
	 *
	 * @param	string	$format
	 */
	private function checkResultFormat($format)
	{
		if ($format != self::SPARQL_XML)
		{
			throw new Exception ('Please supply a valid result format.');
		}
	}

	/**
	 * 
	 *
	 * @param	string	&$context
	 */
	private function checkContext(&$context)
	{
		if($context != 'null')
		{
			$context = (substr($context, 0, 1) != '<' || substr($context, strlen($context) - 1, 1) != '>') ? "<$context>" : $context;
			$context = urlencode($context);
		}
	}

	private function checkInputFormat($format)
	{
		if ($format != self::RDFXML && $format != self::N3 && $format != self::NTRIPLES
				&& $format != self::TRIG && $format != self::TRIX && $format != self::TURTLE)
		{
			throw new Exception ('Please supply a valid input format.');
		}
	}
	
	/**
	 * Performs a simple Query.
	 *
	 * Performs a query and returns the result in the selected format. Throws an
	 * exception if the query returns an error. 
	 *
	 * @param	string	$query			String used for query
	 * @param	string	$resultFormat	Returned result format, see const definitions for supported list.
	 * @param	string	$queryLang		Language used for querying, SPARQL and SeRQL supported
	 * @param	bool	$infer			Use inference in the query
	 *
	 * @return	phpSesame_SparqlRes
	 */
	public function query($query, $resultFormat = self::SPARQL_XML, $queryLang = 'sparql', $infer = true)
	{
		$this->checkRepository();
		$this->checkQueryLang($queryLang);
		$this->checkResultFormat($resultFormat);

		$request =& new HTTP_Request2($this->dsn . '/repositories/' . $this->repository, HTTP_Request2::METHOD_POST);
		$request = $this->prepareRequest($request);
		$request->setHeader('Accept: ' . self::SPARQL_XML);
		$request->setHeader('Content-Type', 'application/x-www-form-urlencoded' . $this->getCharsetString());
		$request->addPostParameter('query', $query);
		$request->addPostParameter('queryLn', $queryLang);
		$request->addPostParameter('infer', $infer);

		$response = $request->send();
		if($response->getStatus() != 200)
		{
			throw new Exception ('Failed to run query, HTTP response error: ' . $response->getStatus());
		}

		return new phpSesame_SparqlRes($response->getBody());
	}

	/**
	 * Appends data to the selected repository
	 *
	 * TODO: Test me!
	 *
	 * @param	string	$data			Data in the supplied format
	 * @param	string	$context		The context the query should be run against
	 * @param	string	$inputFormat	See class const definitions for supported formats.
	 */
	public function append($data, $context = 'null', $inputFormat = self::RDFXML)
	{
		$this->checkRepository();
		$this->checkContext($context);
		$this->checkInputFormat($inputFormat);
		
		$request =& new HTTP_Request2($this->dsn . '/repositories/' . $this->repository . '/statements?context=' . $context, HTTP_Request2::METHOD_POST);
		$request = $this->prepareRequest($request);
		$request->setHeader('Content-type: ' . $inputFormat . $this->getCharsetString());
		$request->setBody($data);

		$response = $request->send();
		if($response->getStatus() != 204)
		{
			throw new Exception ('Failed to append data to the repository, HTTP response error: ' . $response->getStatus());
		}
	}

	/**
	 * Appends data to the selected repository
	 *
	 *
	 *
	 * @param	string	$filePath		The filepath of data, can be a URL
	 * @param	string	$context		The context the query should be run against
	 * @param	string	$inputFormat	See class const definitions for supported formats.
	 */
	public function appendFile($filePath, $context = 'null', $inputFormat = self::RDFXML)
	{
		$data = $this->getFile($filePath);
		$this->append($data, $context, $inputFormat);
	}

	/**
	 * Overwrites data in the selected repository, can optionally take a context parameter
	 *
	 * TODO: Test me!
	 *
	 * @param	string	$data			Data in the supplied format
	 * @param	string	$context		The context the query should be run against
	 * @param	string	$inputFormat	See class const definitions for supported formats.
	 */
	public function overwrite($data, $context = 'null', $inputFormat = self::RDFXML)
	{
		$this->checkRepository();
		$this->checkContext($context);
		$this->checkInputFormat($inputFormat);

		$request =& new HTTP_Request2($this->dsn . '/repositories/' . $this->repository . '/statements?context=' . $context, HTTP_Request2::METHOD_PUT);
		$request = $this->prepareRequest($request);
		$request->setHeader('Content-type: ' . $inputFormat . $this->getCharsetString());
		$request->setBody($data);

		$response = $request->send();
		if($response->getStatus() != 204)
		{
			throw new Exception ('Failed to append data to the repository, HTTP response error: ' . $response->getStatus());
		}
	}

	/**
	 * Overwrites data in the selected repository, can optionally take a context parameter
	 *
	 * @param	string	$filePath		The filepath of data, can be a URL
	 * @param	string	$context		The context the query should be run against
	 * @param	string	$inputFormat	See class const definitions for supported formats.
	 */
	public function overwriteFile($filePath, $context = 'null', $inputFormat = self::RDFXML)
	{
		$data = $this->getFile($filePath);
		$this->overwrite($data, $context, $inputFormat);
	}

	/**
	 * @param	string	$filePath	The filepath of data, can be a URL
	 * @return	string
	 */
	private function getFile($filePath)
	{
		if(empty($filePath) || $filePath == '')
		{
			throw new Exception('Please supply a filepath.');
		}

		return file_get_contents($filePath);
	}

	/**
	 * Gets the namespace URL for the supplied prefix
	 *
	 * TODO: Test me!
	 *
	 * @param	string	$prefix			Data in the supplied format
	 *
	 * @return	string	The URL of the namespace
	 */
	public function getNS($prefix)
	{
		$this->checkRepository();

		if(empty($prefix))
		{
			throw new Exception('Please supply a prefix.');
		}

		$request =& new HTTP_Request2($this->dsn . '/repositories/' . $this->repository . '/namespaces/' . $prefix, HTTP_Request2::METHOD_GET);
		$request = $this->prepareRequest($request);
		$request->setHeader('Accept: text/plain');

		$response = $request->send();
		if($response->getStatus() != 200)
		{
			throw new Exception ('Failed to run query, HTTP response error: ' . $response->getStatus());
		}

		return (string) $response->getBody();
	}

	/**
	 * Sets the the namespace for the specified prefix
	 *
	 * TODO: Test me!
	 *
	 * @param	string	$prefix			Data in the supplied format
	 * @param	string	$namespace		The context the query should be run against
	 */
	public function setNS($prefix, $namespace)
	{
		$this->checkRepository();

		if(empty($prefix) || empty($namespace))
		{
			throw new Exception('Please supply both a prefix and a namesapce.');
		}

		$request =& new HTTP_Request2($this->dsn . '/repositories/' . $this->repository . '/namespaces/' . $prefix, HTTP_Request2::METHOD_PUT);
		$request->setHeader('Content-type: text/plain' . $this->getCharsetString());
		$request = $this->prepareRequest($request);
		$request->setBody($namespace);

		$response = $request->send();
		if($response->getStatus() != 204)
		{
			throw new Exception ('Failed to set the namespace, HTTP response error: ' . $response->getStatus());
		}
	}

	/**
	 * Deletes the namespace for the specified prefix
	 *
	 * TODO: Test me!
	 *
	 * @param	string	$prefix			Data in the supplied format
	 */
	public function deleteNS($prefix)
	{
		$this->checkRepository();

		if(empty($prefix))
		{
			throw new Exception('Please supply a prefix.');
		}

		$request =& new HTTP_Request2($this->dsn . '/repositories/' . $this->repository . '/namespaces/' . $prefix, HTTP_Request2::METHOD_DELETE);
		$request = $this->prepareRequest($request);

		$response = $request->send();
		if($response->getStatus() != 204)
		{
			throw new Exception ('Failed to delete the namespace, HTTP response error: ' . $response->getStatus());
		}
	}

	/**
	 * Returns a list of all the contexts in the repository.
	 *
	 * TODO: Test me!
	 *
	 * @param	string	$resultFormat	Returned result format, see const definitions for supported list.
	 *
	 * @return	phpSesame_SparqlRes
	 */
	public function contexts($resultFormat = self::SPARQL_XML)
	{
		$this->checkRepository();
		$this->checkResultFormat($resultFormat);

		$request =& new HTTP_Request2($this->dsn . '/repositories/' . $this->repository . '/contexts', HTTP_Request2::METHOD_POST);
		$request = $this->prepareRequest($request);
		$request->setHeader('Accept: ' . self::SPARQL_XML);

		$response = $request->send();
		if($response->getStatus() != 200)
		{
			throw new Exception ('Failed to run query, HTTP response error: ' . $response->getStatus());
		}

		return new phpSesame_SparqlRes($response->getBody());
	}

	/**
	 * Returns the size of the repository
	 *
	 * TODO: Test me!
	 *
	 * @param	string	$context		The context the query should be run against
	 *
	 * @return	int
	 */
	public function size($context = 'null')
	{
		$this->checkRepository();

		$request =& new HTTP_Request2($this->dsn . '/repositories/' . $this->repository . '/size?context=' . $context, HTTP_Request2::METHOD_POST);
		$request->setHeader('Accept: text/plain');
		$request = $this->prepareRequest($request);

		$response = $request->send();
		if($response->getStatus() != 200)
		{
			throw new Exception ('Failed to run query, HTTP response error: ' . $response->getStatus());
		}

		return (int) $response->getBody();
	}

	/**
	 * Clears the repository
	 *
	 * TODO: Test me!
	 *
	 * Removes all data from the selected repository from ALL contexts.
	 *
	 * @return	void
	 */
	public function clear()
	{
	    $this->checkRepository();
		
		$request =& new HTTP_Request2($this->dsn . '/repositories/' . $this->repository . '/statements', HTTP_Request2::METHOD_DELETE);
		$request = $this->prepareRequest($request);

		$response = $request->send();
		if($response->getStatus() != 204)
		{
			throw new Exception ('Failed to clear repository, HTTP response error: ' . $response->getStatus());
		}
	}
	
	/**
	 * Returns that charset string for content type HTTP header field.
	 *
	 * @return {String} The charset string
	 */
	public function getCharsetString() 
	{
		return ";charset=" . $this->queryEncoding;
	}


	/**
	 * Prepares a request object.
	 *
	 * @param		HTTP_Request2 The object to prepare
	 * @return	HTTP_Request2 The prepared request object
	 */
	private function prepareRequest($request)
	{
		if($this->auth['user'] != null)
		{
			// TODO: Add support for other Authentication Methods.
			// http://pear.php.net/package/HTTP_Request2/docs/latest/HTTP_Request2/HTTP_Request2.html
			$request->setAuth($this->auth['user'], $this->auth['password']);
		}
		return $request;
	}
}
?>

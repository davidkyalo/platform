<?php defined('SYSPATH') OR die('No direct access allowed.');

/**
 * Ushahidi REST Base Controller
 *
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    Ushahidi\Application\Controllers
 * @copyright  2013 Ushahidi
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License Version 3 (AGPL3)
 */

use Ushahidi\SearchData;
use UshahidiApi\Endpoint;

abstract class Ushahidi_Rest extends Controller {

	/**
	 * @var Current API version
	 */
	protected static $version = '2';

	/**
	 * @var Object Request Payload
	 */
	protected $_request_payload = NULL;

	/**
	 * @var Object Response Payload
	 */
	protected $_response_payload = NULL;

	/**
	 * @var array Map of HTTP methods -> actions
	 */
	protected $_action_map = array
	(
		Http_Request::POST   => 'post',   // Typically Create..
		Http_Request::GET    => 'get',
		Http_Request::PUT    => 'put',    // Typically Update..
		Http_Request::DELETE => 'delete',
	);

	/**
	 * @var array List of HTTP methods which support body content
	 */
	protected $_methods_with_body_content = array
	(
		Http_Request::POST,
		Http_Request::PUT,
	);

	/**
	 * @var array List of HTTP methods which may be cached
	 */
	protected $_cacheable_methods = array
	(
		Http_Request::GET,
	);

	/**
	 * Get the required scope for this endpoint.
	 * @return string
	 */
	abstract protected function _scope();

	public function before()
	{
		parent::before();

		// Set up custom error view
		Kohana_Exception::$error_view_content_type = 'application/json';
		Kohana_Exception::$error_view = 'error/api';
		Kohana_Exception::$error_layout = FALSE;

		HTTP_Exception_404::$error_view = 'error/api';

		$this->_parse_request();
		$this->_check_access();
	}

	public function after()
	{
		$this->_prepare_response();

		parent::after();
	}

	/**
	 * Get current api version
	 */
	public static function version()
	{
		return self::$version;
	}

	/**
	 * Get an API URL for a resource.
	 * @param  string  $resource
	 * @param  mixed   $id
	 * @return string
	 */
	public static function url($resource, $id = null)
	{
		return rtrim(sprintf('api/v%d/%s/%d', static::version(), $resource, $id), '/');
	}

	/**
	 * Get the request access method
	 *
	 * Allows controllers to customize how different methods are treated.
	 *
	 * @return string
	 */
	protected function _get_access_method()
	{
		return strtolower($this->request->method());
	}

	/**
	 * Check if access is allowed
	 * Checks if oauth token and user permissions
	 *
	 * @return bool
	 * @throws HTTP_Exception|OAuth_Exception
	 */
	protected function _check_access()
	{
		$server = service('oauth.server.resource');

		// Using an "Authorization: Bearer xyz" header is required, except for GET requests
		$require_header = $this->request->method() !== Request::GET;

		try
		{
			$server->isValid($require_header);
			$server->hasScope($this->_scope(), true);
		}
		catch (League\OAuth2\Server\Exception\OAuth2Exception $e)
		{

			// Auth server returns an indexed array of headers, along with the server
			// status as a header, which must be converted to use with Kohana.
			$raw_headers = $server::getExceptionHttpHeaders($server::getExceptionType($e->getCode()));

			$status = 400;
			$headers = array();
			foreach ($raw_headers as $header)
			{
				if (preg_match('#^HTTP/1.1 (\d{3})#', $header, $matches))
				{
					$status = (int) $matches[1];
				}
				else
				{
					list($name, $value) = explode(': ', $header);
					$headers[$name] = $value;
				}
			}

			$exception = HTTP_Exception::factory($status, $e->getMessage());
			if ($status === 401)
			{
				// Pass through additional WWW-Authenticate headers, but only for
				// HTTP 401 Unauthorized responses!
				$exception->headers($headers);
			}
			throw $exception;
		}
	}

	/**
	 * Parse the request...
	 */
	protected function _parse_request()
	{
		// Override the method if needed.
		$this->request->method(Arr::get(
			$_SERVER,
			'HTTP_X_HTTP_METHOD_OVERRIDE',
			$this->request->method()
		));

		// Is that a valid method?
		if ( ! isset($this->_action_map[$this->request->method()]))
		{
			throw HTTP_Exception::factory(405, 'The :method method is not supported. Supported methods are :allowed_methods', array(
				':method'          => $this->request->method(),
				':allowed_methods' => implode(', ', array_keys($this->_action_map)),
			))
			->allowed(array_keys($this->_action_map));
		}

		// Get the basic verb based action..
		$action = $this->_action_map[$this->request->method()];

		// If this is a custom action, lets make sure we use it.
		if ($this->request->action() != '_none')
		{
			$action .= '_'.$this->request->action();
		}

		// If we are acting on a collection, append _collection to the action name.
		if ($this->request->param('id', FALSE) === FALSE AND
			$this->request->param('locale', FALSE) === FALSE)
		{
			$action .= '_collection';
		}

		// Override the action
		$this->request->action($action);

		if (! method_exists($this, 'action_'.$action))
		{
			// TODO: filter 'Allow' header to only return implemented methods
			throw HTTP_Exception::factory(405, 'The :method method is not supported. Supported methods are :allowed_methods', array(
				':method'          => $this->request->method(),
				':allowed_methods' => implode(', ', array_keys($this->_action_map)),
			))
			->allowed(array_keys($this->_action_map));
		}

		// Are we be expecting body content as part of the request?
		if (in_array($this->request->method(), $this->_methods_with_body_content))
		{
			$this->_parse_request_body();
		}
	}

	/**
	 * Parse the request body
	 * Decodes JSON request body into PHP array
	 *
	 * @todo Support more than just JSON
	 * @throws HTTP_Exception_400
	 */
	protected function _parse_request_body()
	{
			$this->_request_payload = json_decode($this->request->body(), TRUE);

			if ( $this->_request_payload === NULL )
			{
				// Get further error info
				switch (json_last_error()) {
					case JSON_ERROR_NONE:
						$error = 'No errors';
					break;
					case JSON_ERROR_DEPTH:
						$error = 'Maximum stack depth exceeded';
					break;
					case JSON_ERROR_STATE_MISMATCH:
						$error = 'Underflow or the modes mismatch';
					break;
					case JSON_ERROR_CTRL_CHAR:
						$error = 'Unexpected control character found';
					break;
					case JSON_ERROR_SYNTAX:
						$error = 'Syntax error, malformed JSON';
					break;
					case JSON_ERROR_UTF8:
						$error = 'Malformed UTF-8 characters, possibly incorrectly encoded';
					break;
					default:
						$error = 'Unknown error';
					break;
				}



				throw new HTTP_Exception_400('Invalid json supplied. Error: \':error\'. \':json\'', array(
					':json' => $this->request->body(),
					':error' => $error,
				));
			}
			// Ensure JSON object/array was supplied, not string etc
			elseif ( ! is_array($this->_request_payload) AND ! is_object($this->_request_payload) )
			{
				throw new HTTP_Exception_400('Invalid json supplied. Error: \'JSON must be array or object\'. \':json\'', array(
					':json' => $this->request->body(),
				));
			}
	}

	/**
	 * Prepare response headers and body
	 */
	protected function _prepare_response()
	{
		// Should we prevent this request from being cached?
		if ( ! in_array($this->request->method(), $this->_cacheable_methods))
		{
			$this->response->headers('Cache-Control', 'no-cache, no-store, max-age=0, must-revalidate');
		}

		// Get the requested response format, use JSON for default
		$type = strtolower($this->request->query('format')) ?: 'json';

		try
		{
			$format = service("formatter.output.$type");

			$body = $format($this->_response_payload);
			$mime = $format->getMimeType();

			if ($type === 'jsonp')
			{
				// Prevent Opera and Chrome from executing the response as anything
				// other than JSONP, see T455.
				$this->response->headers('X-Content-Type-Options', 'nosniff');
			}

			$this->response->headers('Content-Type', $mime);
			$this->response->body($body);
		}
		catch (Aura\Di\Exception\ServiceNotFound $e)
		{
			throw new HTTP_Exception_400('Unknown response format: :format', array(':format' => $type));
		}
		catch (InvalidArgumentException $e)
		{
			throw new HTTP_Exception_400('Bad formatting parameters: :message', array(':message' => $e->getMessage()));
		}
		catch (Ushahidi\Exception\FormatterException $e)
		{
			throw new HTTP_Exception_500('Error while formatting response: :message', array(':message' => $e->getMessage()));
		}
	}

	protected function _restful(Endpoint $endpoint, Array $request)
	{
		try
		{
			$this->_response_payload = $endpoint->run($request);
		}
		catch (Ushahidi\Exception\NotFoundException $e)
		{
			throw new HTTP_Exception_404($e->getMessage());
		}
		catch (Ushahidi\Exception\AuthorizerException $e)
		{
			throw new HTTP_Exception_403($e->getMessage());
		}
		catch (Ushahidi\Exception\ValidatorException $e)
		{
			// Also handles ParserException
			throw new HTTP_Exception_400('Validation Error: \':errors\'', array(
				':errors' => implode(', ', Arr::flatten($e->getErrors())),
			));
		}
	}
}
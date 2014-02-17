<?php

namespace GuzzleHttp;

use GuzzleHttp\Event\HasEmitterTrait;
use GuzzleHttp\Adapter\FakeParallelAdapter;
use GuzzleHttp\Adapter\ParallelAdapterInterface;
use GuzzleHttp\Adapter\AdapterInterface;
use GuzzleHttp\Adapter\StreamAdapter;
use GuzzleHttp\Adapter\StreamingProxyAdapter;
use GuzzleHttp\Adapter\Curl\CurlAdapter;
use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Adapter\TransactionIterator;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\MessageFactoryInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Url;

/**
 * HTTP client
 */
class Client implements ClientInterface
{
    use HasEmitterTrait;

    /** @var MessageFactoryInterface Request factory used by the client */
    private $messageFactory;

    /** @var AdapterInterface */
    private $adapter;

    /** @var ParallelAdapterInterface */
    private $parallelAdapter;

    /** @var Url Base URL of the client */
    private $baseUrl;

    /** @var Collection Configuration data */
    private $config;

    /**
     * Clients accept an array of constructor parameters.
     *
     * Here's an example of creating a client using a URI template for the
     * client's base_url and an array of default request options to apply
     * to each request:
     *
     *     $client = new Client([
     *         'base_url' => [
     *              'http://www.foo.com/{version}/',
     *              ['version' => '123']
     *          ],
     *         'defaults' => [
     *             'timeout'         => 10,
     *             'allow_redirects' => false,
     *             'proxy'           => '192.168.16.1:10'
     *         ]
     *     ]);
     *
     * @param array $config Client configuration settings
     *     - base_url: Base URL of the client that is merged into relative URLs. Can be a string or
     *       an array that contains a URI template followed by an associative array of
     *       expansion variables to inject into the URI template.
     *     - adapter: Adapter used to transfer requests
     *     - parallel_adapter: Adapter used to transfer requests in parallel
     *     - message_factory: Factory used to create request and response object
     *     - defaults: Default request options to apply to each request
     */
    public function __construct(array $config = [])
    {
        $this->configureBaseUrl($config);
        $this->configureDefaults($config);
        $this->configureAdapter($config);
        $this->config = new Collection($config);
    }

    /**
     * Get the default User-Agent string to use with Guzzle
     *
     * @return string
     */
    public static function getDefaultUserAgent()
    {
        static $defaultAgent = '';
        if (!$defaultAgent) {
            $defaultAgent = 'Guzzle/' . \GuzzleHttp\VERSION;
            if (extension_loaded('curl')) {
                $defaultAgent .= ' curl/' . curl_version()['version'];
            }
            $defaultAgent .= ' PHP/' . PHP_VERSION;
        }

        return $defaultAgent;
    }

    public function getConfig($keyOrPath = null)
    {
        return $keyOrPath === null ? $this->config->toArray() : $this->config->getPath($keyOrPath);
    }

    public function setConfig($keyOrPath, $value)
    {
        // Ensure that "defaults" is always an array
        if ($keyOrPath == 'defaults' && !is_array($value)) {
            throw new \InvalidArgumentException('The value for "defaults" must be an array');
        }

        $this->config->setPath($keyOrPath, $value);
    }

    public function createRequest($method, $url = null, array $options = [])
    {
        // Merge in default options
        $options = array_replace_recursive($this->config['defaults'], $options);

        // Use a clone of the client's emitter
        $options['config']['emitter'] = clone $this->getEmitter();

        $request = $this->messageFactory->createRequest(
            $method,
            $url ? (string) $this->buildUrl($url) : (string) $this->baseUrl,
            $options
        );

        return $request;
    }

    public function get($url = null, $options = [])
    {
        return $this->send($this->createRequest('GET', $url, $options));
    }

    public function head($url = null, array $options = [])
    {
        return $this->send($this->createRequest('HEAD', $url, $options));
    }

    public function delete($url = null, array $options = [])
    {
        return $this->send($this->createRequest('DELETE', $url, $options));
    }

    public function put($url = null, array $options = [])
    {
        return $this->send($this->createRequest('PUT', $url, $options));
    }

    public function patch($url = null, array $options = [])
    {
        return $this->send($this->createRequest('PATCH', $url, $options));
    }

    public function post($url = null, array $options = [])
    {
        return $this->send($this->createRequest('POST', $url, $options));
    }

    public function options($url = null, array $options = [])
    {
        return $this->send($this->createRequest('OPTIONS', $url, $options));
    }

    public function send(RequestInterface $request)
    {
        $transaction = new Transaction($this, $request);
        try {
            if ($response = $this->adapter->send($transaction)) {
                return $response;
            }
            throw new \LogicException('No response was associated with the transaction');
        } catch (RequestException $e) {
            throw $e;
        } catch (TransferException $e) {
            // Wrap exceptions in a RequestException to adhere to the interface
            throw new RequestException($e->getMessage(), $request, null, $e);
        }
    }

    public function sendAll($requests, array $options = [])
    {
        if (!($requests instanceof TransactionIterator)) {
            $requests = new TransactionIterator($requests, $this, $options);
        }

        $this->parallelAdapter->sendAll(
            $requests,
            isset($options['parallel']) ? $options['parallel'] : 50
        );
    }

    /**
     * Get an array of default options to apply to the client
     *
     * @return array
     */
    protected function getDefaultOptions()
    {
        // Use the bundled cacert if it is a regular file, or set to true if
        // using a phar file (because curL and the stream wrapper can't read
        // cacerts from the phar stream wrapper).
        return [
            'allow_redirects' => true,
            'exceptions'      => true,
            'verify'          => substr(__FILE__, 0, 7) == 'phar://' ? true : (__DIR__ . '/cacert.pem')
        ];
    }

    /**
     * Expand a URI template and inherit from the base URL if it's relative
     *
     * @param string|array $url URL or URI template to expand
     *
     * @return string
     */
    private function buildUrl($url)
    {
        if (!is_array($url)) {
            if (substr($url, 0, 4) === 'http') {
                return (string) $url;
            }
            $baseUrl = clone $this->baseUrl;
            return (string) $baseUrl->combine($url);
        } elseif (substr($url[0], 0, 4) == 'http') {
            return \GuzzleHttp\uriTemplate($url[0], $url[1]);
        }

        $baseUrl = clone $this->baseUrl;
        return (string) $baseUrl->combine(
            \GuzzleHttp\uriTemplate($url[0], $url[1])
        );
    }

    /**
     * Get a default parallel adapter to use based on the environment
     *
     * @return ParallelAdapterInterface|null
     * @throws \RuntimeException
     */
    private function getDefaultParallelAdapter()
    {
        return extension_loaded('curl')
            ? new CurlAdapter($this->messageFactory)
            : new FakeParallelAdapter($this->adapter);
    }

    /**
     * Get a default adapter to use based on the environment
     *
     * @return AdapterInterface
     * @throws \RuntimeException
     */
    private function getDefaultAdapter()
    {
        if (extension_loaded('curl')) {
            if (!ini_get('allow_url_fopen')) {
                return $this->parallelAdapter = $this->adapter = new CurlAdapter($this->messageFactory);
            } else {
                // Assume that the parallel adapter will also be this CurlAdapter
                $this->parallelAdapter = new CurlAdapter($this->messageFactory);
                return new StreamingProxyAdapter(
                    $this->parallelAdapter,
                    new StreamAdapter($this->messageFactory)
                );
            }
        } elseif (ini_get('allow_url_fopen')) {
            return new StreamAdapter($this->messageFactory);
        } else {
            throw new \RuntimeException('The curl extension must be installed or you must set allow_url_fopen to true');
        }
    }

    private function configureBaseUrl(&$config)
    {
        if (!isset($config['base_url'])) {
            $this->baseUrl = new Url('', '');
        } elseif (is_array($config['base_url'])) {
            $this->baseUrl = Url::fromString(\GuzzleHttp\uriTemplate($config['base_url'][0], $config['base_url'][1]));
            $config['base_url'] = (string) $this->baseUrl;
        } else {
            $this->baseUrl = Url::fromString($config['base_url']);
        }
    }

    private function configureDefaults(&$config)
    {
        if (isset($config['defaults'])) {
            $config['defaults'] = array_replace($this->getDefaultOptions(), $config['defaults']);
        } else {
            $config['defaults'] = $this->getDefaultOptions();
        }

        // Add the default user-agent header
        if (!isset($config['defaults']['headers'])) {
            $config['defaults']['headers'] = ['User-Agent' => static::getDefaultUserAgent()];
        } elseif (!isset(array_change_key_case($config['defaults']['headers'])['user-agent'])) {
            // Add the User-Agent header if one was not already set
            $config['defaults']['headers']['User-Agent'] = static::getDefaultUserAgent();
        }
    }

    private function configureAdapter(&$config)
    {
        if (isset($config['message_factory'])) {
            $this->messageFactory = $config['message_factory'];
            unset($config['message_factory']);
        } else {
            $this->messageFactory = new MessageFactory();
        }
        if (isset($config['adapter'])) {
            $this->adapter = $config['adapter'];
            unset($config['adapter']);
        } else {
            $this->adapter = $this->getDefaultAdapter();
        }
        // If no parallel adapter was explicitly provided and one was not
        // defaulted when creating the default adapter, then create one now.
        if (isset($config['parallel_adapter'])) {
            $this->parallelAdapter = $config['parallel_adapter'];
            unset($config['parallel_adapter']);
        } elseif (!$this->parallelAdapter) {
            $this->parallelAdapter = $this->getDefaultParallelAdapter();
        }
    }
}
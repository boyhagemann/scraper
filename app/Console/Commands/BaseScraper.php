<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Closure;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use ArrayObject;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Log;

class BaseScraper extends Command
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var Client
     */
    protected $storage;

    /**
     * @var Array
     */
    protected $log;

    /**
     * The base url of the website to be scraped.
     *
     * @var string
     */
    protected $baseUrl;

    /**
     * The endpoint for the storage rest client.
     *
     * @var string
     */
    protected $storageUrl = 'http://storage-boyhagemann.rhcloud.com';

    /**
     * The number of simultanious calls in a request pool.
     *
     * @var int
     */
    protected $concurrency = 25;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
        ]);

        $this->storage = new Client([
            'base_uri' => $this->storageUrl,
        ]);
    }

    /**
     * Keep track of the successes and failures while scraping.
     *
     * @param Request $request
     * @param $status
     * @param $message
     */
    protected function log(Request $request, $status, $message)
    {
        $url = $request->getUri()->getPath();

        $this->log[$status][$url][] = $message;
    }

    /**
     * Fetch data and catch errors if they occur.
     *
     * @param Request $request
     * @param $property
     * @param Closure $callback
     * @return mixed
     */
    protected function fetch(Request $request, $property, Closure $callback)
    {
        try {
            $result = call_user_func($callback);

            $this->log($request, 'success', $property);

            return $result;
        }
        catch(\Exception $e) {
            $this->logException($e, $request, compact('property'));
        }
    }

    /**
     * Store the data in the storage thru the rest client.
     *
     * @param Request     $request
     * @param ArrayObject $data
     */
    protected function store(Request $request, ArrayObject $data)
    {
        try {
            // Save to the storage
            $json = $data->getArrayCopy();
            $this->storage->post('products', compact('json'));
        }
        catch(\Exception $e) {
            $this->logException($e, $request, compact('data'));
        }
    }

    /**
     * Do one or more requests simultaniously.
     *
     * @param string|array $paths
     * @param Closure|array $callback
     */
    protected function request($paths, $callback)
    {
        /** @var Request[] $requests */
        $requests = [];
        $callbacks = [];

        // We need to keep the same indeces for both the request and
        // the callback. We lose the reference to the request in the
        // asynchronous request pool, so the index is very important
        // for matching the right callback.
        foreach((array) $paths as $path) {
            $requests[] = $this->createRequest($path);
            $callbacks[] = $callback;
        }

        // Handle each request simultanious.
        $pool = new Pool($this->client, $requests, [
            'concurrency' => $this->concurrency,
            'fulfilled' => function (Response $response, $index) use ($callbacks, $requests) {

                // Setup a crawler instance
                $crawler = new Crawler();
                $crawler->addContent($response->getBody(), $response->getHeaderLine('Content-Type'));
                $request = $requests[$index];

                try {

                    // Perform the callback, fetching data from the DOM.
                    call_user_func_array($callbacks[$index], [$crawler, $request]);
                    $this->log($request, 'success', 'Callback complete');
                }
                catch(\Exception $e) {
                    $this->logException($e, $request, compact('crawler'));
                }

            },
            'rejected' => function (\Exception $e, $index) use($requests) {
                $request = $requests[$index];
                $this->logException($e, $request);
            },
        ]);

        // Initiate the transfers and create a promise
        $promise = $pool->promise();

        // Force the pool of requests to complete.
        $promise->wait();
    }

    /**
     * @param \Exception $e
     * @param Request $request
     * @param array $data
     */
    protected function logException(\Exception $e, Request $request, Array $data = [])
    {
        $data['exception'] = $e;
        $data['request'] = $request;

        $this->error($e->getMessage());
        $this->log($request, 'error', $e->getMessage());
        Log::error($e->getMessage(), $data);
    }

    /**
     * @param Crawler $crawler
     * @param string $filter
     * @param $callback
     */
    protected function click(Crawler $crawler, $filter, $callback)
    {
        $links = new ArrayObject();
        $items = $crawler->filter($filter)->each(function(Crawler $node) use ($links) {
            $links[] = $node->attr('href');
        });

        $this->request($links, $callback);
    }

    /**
     * @param string|Request $request
     * @return Request
     */
    protected function createRequest($request)
    {
        if($request instanceof Request) return $request;

        if(strstr($request, ' ')) {
            list($method, $url) = explode(' ', $request);
            return new Request($method, $url);
        }

        return new Request('GET', $request);
    }

}

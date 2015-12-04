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

class NtiScraper extends BaseScraper
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape:nti';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get course data from the NTI website';

    /**
     * The base url of the website to be scraped.
     *
     * @var string
     */
    protected $baseUrl = 'http://nti.nl';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->request(['mbo', 'hbo'], function(Crawler $crawler) {
            $this->click($crawler, '.zfsection a', [$this, 'hbo']);
        });

        $this->info(sprintf('Success: %d', isset($this->log['success']) ? count($this->log['success']) : 0));
        $this->info(sprintf('Errors: %d', isset($this->log['error']) ? count($this->log['error']) : 0));
    }

    /**
     * @param Crawler $crawler
     *
     * Meta
     * - price
     * - price_per_month
     * - flexibel [bool]
     * - duration [integer]
     * - education_level [string]
     * - examination_costs [float]
     * - 21_plus_test [bool]
     * - recognized_diploma
     * - specialized_literature_amount
     * - practice_sessions_count
     */
    public function hbo(Crawler $crawler, Request $request)
    {
        $data = new ArrayObject();

        $this->fetch($request, 'title', function() use($crawler, $data) {
            $title = trim($crawler->filter('h1')->text());
            $data['title'] = $title;
            $data['uid'] = 'nti-' . Str::slug($title);
            $data['slug'] = Str::slug($title);
        });

        $this->fetch($request, 'teaser', function() use($crawler, $data) {
            $data['teaser'] = trim($crawler->filter('.ParagraafOrder_1 p')->first()->text());
        });

        $this->fetch($request, 'price', function() use($crawler, $data, $request) {

            try {
                $price = $crawler->filter('.old-lesgeld-table tr')->eq(1)->filter('td')->eq(1)->text();
                dd($price);
            }
            catch(\Exception $e) {
                $this->error('aaarg');
                $price = $crawler->filter('.old-lesgeld-table tr')->count();
                $this->info($request->getUri()->getPath());
                dd($price);
                dd($e->getMessage());
            }
            $data['meta'][] = [
                'name' => 'price',
                'value' => 0,
            ];
        });

        $this->fetch($request, 'flexible', function() use($crawler, $data) {
            $data['meta'][] = [
                'name' => 'flexible_course',
                'value' => strstr($data['title'], 'Klassikaal') ? 0 : 1,
            ];
        });

        // Save to the storage
        $this->store($request, $data);
    }

}

<?php

declare(strict_types=1);

/*
 * social feed bundle for Contao Open Source CMS
 *
 * Copyright (c) 2024 pdir / digital agentur // pdir GmbH
 *
 * @package    social-feed-bundle
 * @link       https://github.com/pdir/social-feed-bundle
 * @license    http://www.gnu.org/licences/lgpl-3.0.html LGPL
 * @author     Mathias Arzberger <develop@pdir.de>
 * @author     Philipp Seibt <develop@pdir.de>
 * @author     pdir GmbH <https://pdir.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Pdir\SocialFeedBundle\Importer;

use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Dbafs;
use Contao\File;
use Contao\FilesModel;
use Contao\NewsModel;
use Contao\System;
use LinkedIn\Client;
use LinkedIn\Exception;
use Pdir\SocialFeedBundle\Model\SocialFeedModel;
use Psr\Log\LogLevel;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LinkedIn
{
    public int $counter = 0;
    public bool $poorManCron = false;
    private int $maxPosts = 100;
    private bool $debug = false;
    private bool $ignoreInterval = false;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    public function import(): bool
    {
        $logger = System::getContainer()->get('monolog.logger.contao');

        if (!$this->poorManCron) {
            $objSocialFeed = SocialFeedModel::findBy('socialFeedType', 'LinkedIn');
        } else {
            $objSocialFeed = SocialFeedModel::findBy(
                ['socialFeedType = ?', 'pdir_sf_fb_news_cronjob != ?'],
                ['LinkedIn', 'no_cronjob']
            );
        }

        if ($this->debug) {
            dump($objSocialFeed);
        }

        if (null === $objSocialFeed) {
            if ($this->debug) {
                dump('--- no LinkedIn social feed account available!');
            }

            return false;
        }

        $this->counter = 0;

        foreach ($objSocialFeed as $account) {
            $this->counter = 0;
            $cron = $account->pdir_sf_fb_news_cronjob;
            $lastImport = $account->pdir_sf_fb_news_last_import_date;

            if ($this->poorManCron) {
                $this->maxPosts = $account->number_posts;
            }

            if ('' === $lastImport) {
                $lastImport = 0;
            }
            $interval = time() - $lastImport;

            if (($interval >= $cron && 'no_cronjob' !== $cron) || (0 === $lastImport && 'no_cronjob' !== $cron) || true === $this->ignoreInterval) {
                NewsImporter::setLastImportDate($account);

                $client = new Client(
                    $account->linkedin_client_id,
                    $account->linkedin_client_secret
                );

                $client->setApiHeaders([
                    'Content-Type' => 'application/json',
                    'X-Restli-Protocol-Version' => '2.0.0', // use protocol v2
                ]);

                $client->setAccessToken($account->linkedin_access_token);

                /*
                if ($this->debug) {
                    #dump('-- List organizationalEntityAcls');
                    #$profile = $client->get('organizationalEntityAcls',['q' => 'roleAssignee']);
                    #dump($profile);
                }

                if ($this->debug) {
                    dump('-- List organizations');
                    #$companyInfo = $client->get('organizations/'.$account->linkedin_company_id);
                    dump($companyInfo);
                }
                */

                $client->setApiRoot('https://api.linkedin.com/rest/');
                $client->setApiHeaders([
                    'Content-Type' => 'application/json',
                    'X-Restli-Protocol-Version' => '2.0.0', // use protocol v2,
                    'LinkedIn-Version' => '202608', // use latest version (year + month)
                ]);

                // get posts
                $posts = $client->get(
                    'posts?q=author&author=urn%3Ali%3Aorganization%3A'.$account->linkedin_company_id.'&sortBy=LAST_MODIFIED&count='.$this->maxPosts
                );

                if (!\is_array($posts['elements'])) {
                    continue;
                }

                foreach ($posts['elements'] as $element) {
                    $objFile = null;

                    if ($this->debug) {
                        dump('-- LinkedIn API: element data');
                        dump($element);
                    }

                    // @todo import shared content only if wanted by user $account->linkedinImportSharedContent

                    // continue if news exists
                    if (null !== NewsModel::findBy('social_feed_id', $element['id'])) {
                        if ($this->debug) {
                            dump('ignore existing post '.$element['id']);
                        }
                        continue;
                    }

                    $item = [];

                    // get post image
                    $media = $element['content']['multiImage']['images'][0] ?? $element['content']['media'] ?? null;

                    if (!empty($media) && \is_array($media)) {
                        $imgPath = NewsImporter::createImageFolder($account->linkedin_company_id);
                        $mediaPath = $imgPath.str_replace('urn:li:share:', '', $element['id']);

                        // use id of media for image download
                        $firstMediaId = $media['id'] ?? null;
                        $firstMedia = null;

                        if (\is_string($firstMediaId)) {
                            // Support images atm
                            if (str_contains($firstMediaId, 'urn:li:image:')) {
                                $apiPath = 'images/' . urlencode($firstMediaId);
                                $mediaPath .= '.jpg';
//                            } elseif (str_contains($firstMediaId, 'urn:li:video:')) {
//                                $apiPath = 'videos/' . urlencode($firstMediaId);
//                                $mediaPath .= '.mp4';
                            } else {
                                $apiPath = null;
                            }

                            if (isset($apiPath)) {
                                $response = $client->get($apiPath);
                                $firstMedia = \is_array($response) ? ($response['downloadUrl'] ?? null) : null;
                            }
                        }

                        // use first thumbnail for articles
                        // dont have an use case for this to test yet
                        if (isset($media[0]) && str_contains($media[0]['media'], 'urn:li:article:') && isset($media[0]['thumbnails'][0])) {
                            $firstMedia  = $media[0]['thumbnails'][0]['url'] ?? null;
                        }

                        // get first image
                        if (!file_exists($mediaPath) && isset($firstMedia)) {

                            $strImage = $this->fetchImageContent($firstMedia);
                            // Write to filesystem
                            $file = new File($mediaPath);
                            $file->write($strImage);
                            $file->close();

                            // Add the resource
                            $objFile = Dbafs::addResource($mediaPath);
                        }

                        // get files model for existing image
                        if (file_exists($mediaPath) && null === $objFile) {
                            $objFile = FilesModel::findByPath($mediaPath);
                        }
                    }

                    $item['id'] = $element['id'];
                    $item['headline'] = NewsImporter::shortenHeadline($element['commentary'] ?? '');
                    $item['teaser'] = str_replace("\n", '<br>', $element['commentary'] ?? '');
                    $item['singleSRC'] = null !== $objFile ? $objFile->uuid : '';
                    $item['date'] = $element['publishedAt'] / 1000;
                    $item['time'] = $element['publishedAt'] / 1000;
                    $item['permalink'] = 'https://www.linkedin.com/feed/update/'.$item['id'].'/';

                    // @todo get organization and set account picture
                    // $item['social_feed_account'] = $organization?? $organization['localizedName'];

                    if ($this->debug) {
                        dump('-- Post data for import');
                        dump($item);
                    }

                    // write to db
                    $importer = new NewsImporter();
                    $importer->setNews($item);
                    $importer->execute($account->pdir_sf_fb_news_archive, $account);

                    ++$this->counter;
                }

                if (0 < $this->counter) {
                    $logger->log(LogLevel::INFO, 'Social Feed (ID '.$account->id.'): LinkedIn - imported '.$this->counter.' items.', ['contao' => new ContaoContext(__METHOD__, 'INFO')]);
                }
            }

            if (0 === $this->counter) {
                $logger->log(LogLevel::INFO, 'Social Feed (ID '.$account->id.'): LinkedIn Import - nothing to import', ['contao' => new ContaoContext(__METHOD__, 'INFO')]);
            }
        }

        return true;
    }

    public function setPoorManCronMode($flag): void
    {
        if (true === $flag) {
            $this->poorManCron = true;
        }
    }

    public function setDebugMode($flag): void
    {
        if (true === $flag) {
            $this->debug = true;
        }
    }

    public function setIgnoreInterval($flag): void
    {
        if (true === $flag) {
            $this->ignoreInterval = true;
        }
    }

    public function setMaxPosts($maxPosts): void
    {
        $this->maxPosts = $maxPosts;
    }

    private function fetchImageContent(string $imageUrl): string
    {
        $response = $this->httpClient->request('GET', $imageUrl, [
            'timeout' => 20,
            'headers' => [
                'User-Agent' => 'SocialFeedBot/1.0',
                'Accept' => 'image/*,*/*;q=0.8',
            ],
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Image could not be loaded: ' . $imageUrl);
        }
        return $response->getContent();
    }
}

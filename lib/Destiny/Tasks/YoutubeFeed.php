<?php
namespace Destiny\Tasks;

use Destiny\Common\Annotation\Schedule;
use Destiny\Common\Application;
use Destiny\Common\Config;
use Destiny\Common\TaskInterface;
use Destiny\Common\Utils\ImageDownload;
use Destiny\Youtube\YoutubeApiService;

/**
 * @Schedule(frequency=30,period="minute")
 */
class YoutubeFeed implements TaskInterface {

    public function execute() {
        $json = YoutubeApiService::instance()->getYoutubePlaylist();
        if (!empty ($json)) {
            foreach ($json ['items'] as $i => $item) {
                $path = ImageDownload::download($json ['items'][$i]['snippet']['thumbnails']['high']['url']);
                if (!empty($path))
                    $json ['items'][$i]['snippet']['thumbnails']['high']['url'] = Config::cdni() . '/' . $path;
            }
            $cache = Application::instance()->getCache();
            $cache->save('youtubeplaylist', $json);
        }
    }

}
<?php

require_once './vendor/autoload.php';

date_default_timezone_set('Asia/Jakarta');

$dotenv = new Symfony\Component\Dotenv\Dotenv;
$dotenv->load(__DIR__ . '/.env');

if (file_exists(__DIR__ . '/cache.txt') === false) {
    file_put_contents('cache.txt', '');
}

function getFollowedUpdates() {
    $client = new GuzzleHttp\Client();
    $cookies = GuzzleHttp\Cookie\CookieJar::fromArray([
        'mangadex_session' => $_ENV['MANGADEX_SESSION'],
        'mangadex_rememberme_token' => $_ENV['MANGADEX_REMEMBERME_TOKEN']
    ], 'mangadex.org');
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.88 Safari/537.36';

    $response = $client->request('GET', 'https://mangadex.org/api/v2/user/me/followed-updates', [
        'headers' => [
            'User-Agent' => $userAgent
        ],
        'http_errors' => false,
        'cookies' => $cookies
    ]);

    return json_decode($response->getBody(), true);
}


function sendTelegramPhoto($chatId, $photo, $caption) {
    $client = new GuzzleHttp\Client();
    $client->request('POST', 'https://api.telegram.org/bot' . $_ENV['TELEGRAM_BOT_TOKEN'] . '/sendPhoto', [
        'headers' => [  
            'Content-Type' => 'application/json'
        ],
        'json' => [
            'chat_id' => $chatId,
            'photo' => $photo,
            'caption' => $caption
        ],
        'http_errors' => false
    ]);
}

function getMangaCover($mangaId) {
    $client = new GuzzleHttp\Client();
    $response = $client->request('GET', 'https://mangadex.org/api/v2/manga/' . $mangaId . '/covers');

    $cover = json_decode($response->getBody(), true)['data'];
    
    return count($cover) === 0 ?  getMainCover($mangaId) : end($cover)['url'];
}

function getMainCover($mangaId) {
    $client = new GuzzleHttp\Client();
    $response = $client->request('GET', 'https://mangadex.org/api/v2/manga/' . $mangaId);

    return json_decode($response->getBody(), true)['data']['mainCover'];
}

function getLastChapterId($chapters) {
    $file = __DIR__ . '/cache.txt';

    if (file_exists($file)) {
        $cache = file_get_contents($file);

        if (is_numeric($cache)) {
            return $cache;
        }
    }

    return end($chapters)['id'];
}

function translateLanguage($language) {
    $lang = [
        'id' => 'Indonesia',
        'gb' => 'English'
    ];

    return $lang[$language];
}

$json = getFollowedUpdates();
$chapters = $json['data']['chapters'];
$lastChapterId = getLastChapterId($chapters);
$chapters = array_slice($chapters, 0, array_search($lastChapterId, array_column($chapters, 'id')));
$chapters = array_values(array_filter($chapters, function ($chapter) {
    return in_array($chapter['language'], ['id', 'gb']);
}));

$groupedChapters = [];

foreach ($chapters as $chapter) {
    $groupedChapters[$chapter['mangaId']][] = $chapter;
}

$groupedChapters = array_reverse(array_values($groupedChapters));

foreach ($groupedChapters as $groupedChapter) {
    $text = null;

    foreach ($groupedChapter as $chapter) {
        $beforeText = $text;

        $text .= "\n";
        $text .= '[NEW UPDATE]';
        $text .= "\n";
        $text .= $chapter['mangaTitle'];
        $text .= "\n";
        $text .= 'Chapter: ' . $chapter['chapter'];
        $text .= "\n";
        $text .= 'Language: ' . translateLanguage($chapter['language']);
        $text .= "\n";
        $text .= 'Updated At: ' . date('l, j F Y H:i:s', $chapter['timestamp']);
        $text .= "\n";
        $text .= 'Click link below to read';
        $text .= "\n";
        $text .= 'https://mangadex.org/chapter/' . $chapter['id'];
        $text .= "\n";

        if (strlen($text) > 1024) {
            sendTelegramPhoto($_ENV['TELEGRAM_CHAT_ID'], getMangaCover($chapter['mangaId']), $beforeText);
            $text = str_replace($beforeText, '', $text);
        }
    }

    sendTelegramPhoto($_ENV['TELEGRAM_CHAT_ID'], getMangaCover(current($groupedChapter)['mangaId']), $text);
} 

if (count($chapters) !== 0) {
    file_put_contents(__DIR__ . '/cache.txt', $chapters[0]['id']);
}
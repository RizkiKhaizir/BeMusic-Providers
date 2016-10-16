<?php namespace App\Services\Providers\Lastfm;

use App;
use Cache;
use App\Album;
use App\Artist;
use Carbon\Carbon;
use Illuminate\Support\Str;
use App\Services\HttpClient;
use App\Services\ArtistSaver;
use Illuminate\Support\Collection;
use App\Services\Providers\Spotify\SpotifyArtist;

class LastfmGenres {

    /**
     * Links of artist placeholder images on last.fm
     * 
     * @var Array
     */
    private $lastfmPlaceholderImages = [
        'https://lastfm-img2.akamaized.net/i/u/289e0f7b270445e5c550714f606fd8fd.png'
    ];

    /**
     * HttpClient instance.
     *
     * @var HttpClient
     */
    private $httpClient;

    /**
     * SpotifyArtist service instance.
     *
     * @var SpotifyArtist
     */
    private $spotifyArtist;

    /**
     * ArtistSaver instance.
     * 
     * @var ArtistSaver
     */
    private $saver;

    /**
     * Settings service instance.
     *
     * @var App\Services\Settings
     */
    private $settings;

    /**
     * Create new LastfmGenres instance.
     */
    public function __construct(SpotifyArtist $spotifyArtist, ArtistSaver $saver)
    {
        $this->httpClient    = new HttpClient(['base_url' => 'http://ws.audioscrobbler.com/2.0/']);
        $this->spotifyArtist = $spotifyArtist;
        $this->saver         = $saver;
        $this->settings      = App::make('Settings');
        $this->apiKey        = $this->settings->get('lastfm_api_key');

        if ( ! $this->apiKey) {
            $this->apiKey = env('LASTFM_API_KEY');
        }

        ini_set('max_execution_time', 0);
    }

    public function getGenres($genreNames = null)
    {
        return Cache::remember('lastfm.genres', Carbon::now()->addDays(2), function() use($genreNames) {
            if ($genreNames) {
                return $this->formatGenres($genreNames)['formatted'];
            } else {
                return $this->getMostPopular();
            }
        });
    }

    public function getMostPopular()
    {
        $response = $this->httpClient->get("?method=tag.getTopTags&api_key=$this->apiKey&format=json");

        if ( ! isset($response['toptags'])) {
            sleep(3);
            $response = $this->httpClient->get("?method=tag.getTopTags&api_key=$this->apiKey&format=json");
        }

        $formatted = $this->formatGenres($response['toptags']['tag']);

        $this->saver->saveOrUpdate($formatted['names'], array_flatten($formatted['names']), 'genres');

        return $formatted['formatted'];
    }

    public function formatGenres($genres) {
        $formatted = [];
        $names     = [];

        if (is_string($genres)) {
            $genres = explode(',', $genres);
        }

        foreach($genres as $genre) {
            if (is_array($genre)) {
                $formatted[] = ['name' => $genre['name'], 'popularity' => $genre['count'], 'image' => $this->getLocalImagePath($genre['name'])];
                $names[] = ['name' => $genre['name']];
            } else {
                $genre = trim($genre);
                $formatted[] = ['name' => $genre, 'popularity' => 0, 'image' => $this->getLocalImagePath($genre)];
                $names = $genres;
            }
        }

        return ['formatted' => $formatted, 'names' => $names];
    }

    public function getGenreArtists($genre)
    {
        $genreName = $genre['name'];
        $response  = $this->httpClient->get("?method=tag.gettopartists&tag=$genreName&api_key=$this->apiKey&format=json&limit=50");
        $artists   = $response['topartists']['artist'];
        $names     = [];
        $formatted = [];

        foreach($artists as $artist) {
            if ( ! $this->collectionContainsArtist($artist['name'], $formatted)) {

                $img = ! in_array($artist['image'][4]['#text'], $this->lastfmPlaceholderImages) ? $artist['image'][4]['#text'] : null;
               
                $formatted[] = [
                    'name' => $artist['name'],
                    'image_small' => $img,
                    'fully_scraped' => 0,
                ];

                $names[] = $artist['name'];
            }
        }

        $existing = Artist::whereIn('name', $names)->get();

        $insert = array_filter($formatted, function($artist) use ($existing) {
            return ! $this->collectionContainsArtist($artist['name'], $existing);
        });

        Artist::insert($insert);

        $artists = Artist::whereIn('name', $names)->get();

        $this->attachGenre($artists, $genre);

        return $artists;
    }

    /**
     * Attach genre to artists in database.
     *
     * @param Collection $artists
     * @param App\Genre $genre
     */
    private function attachGenre($artists, $genre)
    {
        $pivotInsert = [];

        foreach ($artists as $artist) {
            $pivotInsert[] = ['genre_id' => $genre['id'], 'artist_id' => $artist['id']];
        }

        $this->saver->saveOrUpdate($pivotInsert, array_flatten($pivotInsert), 'genre_artist');
    }

    public function getLocalImagePath($genreName)
    {
        $genreName = str_replace(' ', '-', strtolower(trim($genreName)));

        $end = 'assets/images/genres/'.$genreName.'.jpg';

        return App::make('Settings')->get('enable_https') ? secure_url($end) : url($end);
    }

    private function collectionContainsArtist($name, $collection) {
        foreach ($collection as $artist) {
            $needle = Str::slug($name);
            $artistName = Str::slug($artist['name']);

            if (( ! $needle || ! $artistName) || $needle == $artistName) {
                return true;
            }
        }

        return false;
    }
}
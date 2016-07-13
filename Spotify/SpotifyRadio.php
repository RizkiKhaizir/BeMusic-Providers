<?php namespace App\Services\Providers\Spotify;

use App\Services\HttpClient;
use App\Traits\AuthorizesWithSpotify;

class SpotifyRadio {

    use AuthorizesWithSpotify;

    /**
     * HttpClient instance.
     *
     * @var HttpClient
     */
    private $httpClient;

    /**
     * Spotify Search Instance.
     *
     * @var SpotifySearch
     */
    private $spotifySearch;

    /**
     * Create new SpotifyArtist instance.
     */
    public function __construct(SpotifySearch $spotifySearch) {
        $this->httpClient = new HttpClient(['base_url' => 'https://api.spotify.com/v1/']);
        $this->spotifySearch = $spotifySearch;
    }

    public function getSuggestions($name)
    {
        $this->authorize();

        $response = $this->spotifySearch->search($name, 1, 'artist');

        if ( ! isset($response['artists']) || empty($response['artists'])) return [];

        $spotifyId = $response['artists'][0]['spotify_id'];

        $response = $this->httpClient->get('https://api.spotify.com/v1/recommendations',
            [
                'query' => [
                    'seed_artists'  => $spotifyId,
                    'min_popularity' => 30,
                    'limit' => 100,
                ],
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token
                ]
            ]
        );

        if ( ! isset($response['tracks'][0])) return [];

        $tracks = [];

        foreach($response['tracks'] as $track) {
            $tracks[] = [
                'name' => $track['name'],
                'artist' => ['name' => $track['artists'][0]['name']],
            ];
        }

        return $tracks;
    }
}
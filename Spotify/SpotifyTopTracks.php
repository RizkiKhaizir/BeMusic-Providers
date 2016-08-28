<?php namespace App\Services\Providers\Spotify;

use App\Album;
use App\Track;
use App\Artist;
use App\Services\HttpClient;
use App\Services\ArtistSaver;
use App\Services\Providers\Spotify\SpotifyArtist;

class SpotifyTopTracks {

    /**
     * SpotifyArtist service instance.
     *
     * @var SpotifyArtist
     */
    private $spotifyArtist;

    /**
     * ArtistSaver service instance.
     *
     * @var ArtistSaver
     */
    private $saver;

    /**
     * Create new SpotifyTopTracks instance.
     */
    public function __construct(SpotifyArtist $spotifyArtist, ArtistSaver $saver)
    {
        $this->spotifyArtist = $spotifyArtist;
        $this->saver         = $saver;

        ini_set('max_execution_time', 0);
    }

    public function getTopTracks()
    {
        $csv = $this->getSpotifyChartsCsv();

        $split = explode("\n", $csv);

        $ids = '';

        foreach ($split as $k => $line) {
            if ($k === 0) continue;
            if ($k > 50) break;

            preg_match('/.+?\/track\/(.+)/', $line, $matches);

            if (isset($matches[1])) {
                $ids .= $matches[1].',';
            }
        }

        $tracks = $this->getTracks(trim($ids, ','));

        $tracks = $this->spotifyArtist->formatTracks($tracks, true);

        $artists = [];
        $artistNames = [];

        foreach($tracks as $track) {
            $artistNames[] = $track['artist']['name'];

            $artists[] = [
                'name' => $track['artist']['name'],
                'fully_scraped' => 0
            ];
        }

        $artists = $this->saveArtists($artists, $artistNames);

        $albums = $this->saveAlbums($tracks, $artists);

        return $this->saveTracks($tracks, $albums)->values();
    }

    /**
     * Get spotify charts data in csv format.
     * 
     * @return string
     */
    private function getSpotifyChartsCsv()
    {
        $ch = curl_init('https://spotifycharts.com/regional/global/daily/latest/download');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    private function getTracks($ids)
    {
        $response = (new HttpClient())->get('https://api.spotify.com/v1/tracks?ids='.$ids);

        return $response['tracks'];
    }

    private function saveTracks($tracks, $albums)
    {
        $tracks = array_values($tracks);

        $originalOrder = [];

        $tempId = str_random(8);

        foreach($tracks as $k => $track) {

            $tracks[$k]['album_id'] = $this->getItemId($track['album']['name'], $albums);
            $tracks[$k]['temp_id'] = $tempId;

            unset($tracks[$k]['artist']);
            unset($tracks[$k]['album']);

            $originalOrder[$track['name']] = $k;
        }

        $this->saver->saveOrUpdate($tracks, array_flatten($tracks), 'tracks');

        $tracks = Track::with('album.artist')->where('temp_id', $tempId)->limit(50)->get();

        return $tracks->sort(function($a, $b) use ($originalOrder) {
            $originalAIndex = isset($originalOrder[$a->name]) ? $originalOrder[$a->name] : 0;
            $originalBIndex = isset($originalOrder[$b->name]) ? $originalOrder[$b->name] : 0;

            if ($originalAIndex == $originalBIndex) {
                return 0;
            }
            return ($originalAIndex < $originalBIndex) ? -1 : 1;
        });
    }

    private function saveAlbums($tracks, $artists)
    {
        $albums = []; $albumNames = []; $albumImages = [];

        foreach($tracks as $track) {
            $image = isset($track['album']['images'][1]['url']) ? $track['album']['images'][1]['url'] : head($track['album']['images'])['url'];

            $albums[] = [
                'name'  => $track['album']['name'],
                'image' => $image,
                'fully_scraped' => 0,
                'artist_id' => $this->getItemId($track['artist']['name'], $artists)
            ];

            $albumNames[]  = $track['album']['name'];
            $albumImages[] = $image;
        }

        $existing = Album::whereIn('name', $albumNames)->whereIn('image', $albumImages)->groupBy('name')->distinct()->get();

        $albumsToFetch = [];

        foreach($albums as $k => $album) {
            if ($this->inArray($album['name'], $existing)) {
                unset($albums[$k]);
            } else {
                $albumsToFetch[] = $album['name'];
            }
        }

        $this->saver->saveOrUpdate($albums, array_flatten($albums), 'albums');

        $new = Album::whereIn('name', $albumsToFetch)->get();

        return $existing->merge($new);

    }

    private function saveArtists($artists, $artistNames)
    {
        $existing = Artist::whereIn('name', $artistNames)->get();

        $artistsToFetch = [];

        foreach($artists as $k => $artist) {
            if ($this->inArray($artist['name'], $existing)) {
                unset($artists[$k]);
            } else {
                $artistsToFetch[] = $artist['name'];
            }
        }

        $this->saver->saveOrUpdate($artists, array_flatten($artists), 'artists');

        $new = Artist::whereIn('name', $artistsToFetch)->get();

        return $existing->merge($new);
    }

    private function getItemId($name, $items)
    {
        foreach($items as $item) {
            if (strtolower($name) == strtolower($item->name)) {
                return $item->id;
            }
        }
    }

    private function inArray($name, $items)
    {
        foreach($items as $item) {
            if (strtolower($name) == strtolower($item->name)) {
                return true;
            }
        }
    }
}
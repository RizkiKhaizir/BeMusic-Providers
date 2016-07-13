<?php namespace App\Services\Providers\Local;

use DB;
use Input;
use Cache;
use App\Genre;
use App\Track;
use Carbon\Carbon;
use App\Services\Paginator;
use Illuminate\Database\Eloquent\Collection;

class LocalGenres {

    /**
     * Paginator Instance.
     *
     * @var Paginator
     */
    private $paginator;

    /**
    * Create new LocalGenres instance.
    */
    public function __construct(Paginator $paginator)
    {
        $this->paginator = $paginator;
    }

    /**
     * Get genres using local provider.
     *
     * @return Collection
     */
    public function getGenres($names) {
        $names    = str_replace(', ', ',', $names);
        $orderBy  = implode(',', array_map(function($v) { return "'".$v."'"; }, explode(',', $names)));
        $cacheKey = 'genres.'.Input::get('limit', 20).$names;

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $genres = Genre::whereIn('name', explode(',', $names))->orderByRaw(DB::raw("FIELD(name, $orderBy)"))->get();

        if ($genres->isEmpty()) {
            abort(404);
        }

        //limit artists loaded for genres
        $genres->map(function ($genre) {
            $genre->load(['artists' => function ($q) {
                $q->limit(Input::get('limit', 20));
            }]);

            return $genre;
        });

        Cache::put($cacheKey, $genres, Carbon::now()->addDays(1));

        return $genres;
    }
    
    public function getGenreArtists(Genre $genre)
    {
        $input = Input::all(); $input['itemsPerPage'] = 20;
        $artists = $this->paginator->paginate($genre->artists(), $input, 'artists')->toArray();

        return ['genre' => $genre, 'artists' => $artists];
    }
}
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Search\SearchRequest;
use App\Http\Resources\PublicUserResource;
use App\Http\Resources\TeamSearchResource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller
{
    public function index(SearchRequest $request): JsonResponse
    {
        $q        = $request->q;
        $perPage  = $request->integer('per_page', 15);
        $term     = '%' . $q . '%';

        $users = User::where('name', 'LIKE', $term)
            ->whereNotIn('status', ['banned', 'suspended'])
            ->where('id', '!=', $request->user()->id)
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'users_page');

        $teams = Team::with('leader')
            ->withCount('members')
            ->where('name', 'LIKE', $term)
            ->where('status', '!=', 'inactive')
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'teams_page');

        return $this->success(
            [
                'users' => [
                    'items' => PublicUserResource::collection($users->items()),
                    'meta'  => [
                        'current_page' => $users->currentPage(),
                        'per_page'     => $users->perPage(),
                        'total'        => $users->total(),
                        'last_page'    => $users->lastPage(),
                    ],
                ],
                'teams' => [
                    'items' => TeamSearchResource::collection($teams->items()),
                    'meta'  => [
                        'current_page' => $teams->currentPage(),
                        'per_page'     => $teams->perPage(),
                        'total'        => $teams->total(),
                        'last_page'    => $teams->lastPage(),
                    ],
                ],
            ],
            'Search results retrieved successfully.'
        );
    }
}

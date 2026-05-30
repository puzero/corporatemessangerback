<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SearchController extends Controller
{
    public function search(Request $request)
    {
        $userAccessToken = $request->attributes->get('matrix_access_token');
        if (!$userAccessToken) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'query' => 'required|string|min:1',
        ]);

        $query = $request->input('query');
        $homeserver = config('matrix.homeserver_url');

        $response = Http::withToken($userAccessToken)->post($homeserver . '/_matrix/client/v3/search', [
            'search_categories' => [
                'room_events' => [
                    'search_term' => $query,
                    'keys' => ['content.body'],
                    'order_by' => 'recent',
                    'limit' => 50,
                ]
            ]
        ]);

        if (!$response->successful()) {
            return response()->json(['error' => 'Search failed'], 500);
        }

        $results = $response->json();
        $formatted = [];

        if (isset($results['search_categories']['room_events']['results'])) {
            foreach ($results['search_categories']['room_events']['results'] as $result) {
                $event = $result['result'];
                $formatted[] = [
                    'room_id' => $event['room_id'],
                    'sender' => $event['sender'],
                    'body' => $event['content']['body'] ?? '',
                    'timestamp' => $event['origin_server_ts'],
                    'event_id' => $event['event_id'],
                ];
            }
        }

        return response()->json(['results' => $formatted]);
    }
}
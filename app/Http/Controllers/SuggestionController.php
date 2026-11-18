<?php

namespace App\Http\Controllers;

use App\Services\Search\Suggestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Phase 10F: the JSON behind <x-ui.typeahead>. Three doors, each with its own route, role and named throttle:
 * the owner's scopes (owner only), the signed-in customer's own requests (customer, verified), and the published
 * catalog (anyone). Read only; at most 8 items; nothing but the fields the dropdown shows.
 */
class SuggestionController extends Controller
{
    public function __construct(private readonly Suggestions $suggestions) {}

    public function owner(Request $request): JsonResponse
    {
        $data = $this->term($request, ['scope' => ['required', Rule::in(Suggestions::OWNER_SCOPES)]]);

        return $this->answer($data, fn (string $q) => $this->suggestions->owner($data['scope'], $q));
    }

    public function customer(Request $request): JsonResponse
    {
        $data = $this->term($request);

        return $this->answer($data, fn (string $q) => $this->suggestions->customer($request->user(), $q));
    }

    public function catalog(Request $request): JsonResponse
    {
        $data = $this->term($request, ['scope' => ['required', Rule::in(Suggestions::PUBLIC_SCOPES)]]);

        return $this->answer($data, fn (string $q) => $this->suggestions->catalog($data['scope'], $q));
    }

    /**
     * A wrong scope or a term over 60 characters is not an error page: the dropdown simply gets no items.
     *
     * @return array<string, mixed>|null
     */
    private function term(Request $request, array $rules = []): ?array
    {
        $validator = Validator::make($request->query(), ['q' => ['nullable', 'string', 'max:60']] + $rules);
        if ($validator->fails()) {
            return null;
        }
        $data = $validator->validated();
        $data['q'] = trim((string) ($data['q'] ?? ''));

        return $data;
    }

    private function answer(?array $data, callable $find): JsonResponse
    {
        $q = $data['q'] ?? '';

        return response()->json(['query' => $q, 'items' => $data === null || $q === '' ? [] : $find($q)])->header('Cache-Control', 'no-store, private');
    }
}

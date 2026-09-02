<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCharacterRequest;
use App\Http\Requests\UpdateCharacterRequest;
use App\Models\Character;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class CharacterController extends Controller
{
    public function store(StoreCharacterRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        $name = trim($attributes['name']);
        $description = trim($attributes['description']);
        $character = $request->user()->characters()->create([
            'slug' => $this->uniqueSlug($request->user()->id, $name),
            'name' => $name,
            'description' => $description,
            'system_prompt' => filled($attributes['system_prompt'] ?? null)
                ? trim($attributes['system_prompt'])
                : "Sei {$name}, uno specialista di Life Assistant. Il tuo ambito è: {$description}. Rispondi soltanto nel tuo ambito. Usa la tua memoria e il contesto di questa chat per la continuità. Se l’utente nomina un altro assistente puoi consultarne i fatti per un confronto, senza parlare a nome loro.",
            'tone' => filled($attributes['tone'] ?? null)
                ? trim($attributes['tone'])
                : 'Chiaro, concreto e professionale.',
            'is_global' => false,
            'sort_order' => min(
                255,
                ((int) $request->user()->characters()->max('sort_order')) + 1,
            ),
        ]);

        return response()->json([
            'message' => 'Specialista creato.',
            'character' => $character,
            'url' => route('home', ['character' => $character->slug]),
        ], 201);
    }

    public function update(UpdateCharacterRequest $request, Character $character): JsonResponse
    {
        $character->update($request->validated());

        return response()->json([
            'message' => 'Impostazioni salvate.',
            'character' => $character->only(['id', 'name', 'description', 'system_prompt', 'tone']),
        ]);
    }

    public function destroy(Character $character): RedirectResponse
    {
        $this->authorize('delete', $character);
        $character->delete();

        return redirect()
            ->route('home')
            ->with('status', 'Specialista eliminato con tutte le sue chat e memorie.');
    }

    private function uniqueSlug(int $userId, string $name): string
    {
        $base = Str::limit(Str::slug($name), 240, '') ?: 'specialista';
        $slug = $base;
        $suffix = 2;

        while (Character::query()->where('user_id', $userId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}

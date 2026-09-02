<?php

namespace Tests\Feature;

use App\CharacterSlug;
use App\Models\Character;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_home_shows_chat(): void
    {
        Character::factory()->global()->create();
        Character::factory()->create([
            'slug' => CharacterSlug::Doctor,
            'name' => 'Dottore',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Life Assistant')
            ->assertSee('Globale')
            ->assertSee('Dottore')
            ->assertSee('Nuovo specialista')
            ->assertSee('Impostazioni')
            ->assertSee('Google')
            ->assertSee('Spotify')
            ->assertDontSee('Microsoft 365')
            ->assertDontSee('Notion')
            ->assertDontSee('Slack')
            ->assertDontSee('Dropbox')
            ->assertDontSee('GitHub');
    }

    public function test_home_shows_the_selected_conversation_from_database(): void
    {
        $doctor = Character::factory()->create([
            'slug' => CharacterSlug::Doctor,
            'name' => 'Dottore',
        ]);
        $conversation = Conversation::factory()->for($doctor)->create([
            'title' => 'Salute di agosto',
        ]);
        Message::factory()->for($conversation)->create([
            'content' => 'Messaggio persistente',
        ]);

        $this->get(route('home', [
            'character' => CharacterSlug::Doctor->value,
            'conversation' => $conversation->id,
        ]))
            ->assertOk()
            ->assertSee('Salute di agosto')
            ->assertSee('Messaggio persistente');
    }

    public function test_new_conversation_requires_a_character(): void
    {
        $this->postJson(route('conversations.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('character_id');
    }
}

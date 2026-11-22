<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneConversationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_removes_only_conversations_inactive_beyond_retention_with_their_messages(): void
    {
        $antiga = Conversation::create(['visitor_token' => 'a']);
        $antiga->messages()->create(['role' => Message::ROLE_USER, 'content' => 'pergunta antiga']);
        $antiga->forceFill(['updated_at' => now()->subDays(31)])->saveQuietly();

        $recente = Conversation::create(['visitor_token' => 'b']);
        $recente->messages()->create(['role' => Message::ROLE_USER, 'content' => 'pergunta recente']);

        $this->artisan('fiscal:prune-conversations', ['--days' => 30])->assertSuccessful();

        $this->assertDatabaseMissing('conversations', ['id' => $antiga->id]);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $antiga->id]);
        $this->assertDatabaseHas('conversations', ['id' => $recente->id]);
        $this->assertDatabaseHas('messages', ['conversation_id' => $recente->id]);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $antiga = Conversation::create(['visitor_token' => 'a']);
        $antiga->forceFill(['updated_at' => now()->subDays(90)])->saveQuietly();

        $this->artisan('fiscal:prune-conversations', ['--days' => 30, '--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('conversations', ['id' => $antiga->id]);
    }
}

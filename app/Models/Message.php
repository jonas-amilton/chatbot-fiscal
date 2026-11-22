<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    protected $fillable = ['conversation_id', 'role', 'content', 'tool_name', 'tool_payload', 'latency_ms'];

    protected function casts(): array
    {
        return ['tool_payload' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}

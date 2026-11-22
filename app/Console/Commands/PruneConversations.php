<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fiscal:prune-conversations {--days=30 : Apaga conversas sem atividade há mais de N dias} {--dry-run : Só mostra quantas seriam apagadas}')]
#[Description('Retenção LGPD: apaga conversas e mensagens inativas há mais de N dias')]
class PruneConversations extends Command
{
    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('--days precisa ser pelo menos 1.');

            return self::FAILURE;
        }

        $query = Conversation::query()->where('updated_at', '<', now()->subDays($days));
        $total = $query->count();

        if ($this->option('dry-run')) {
            $this->info("{$total} conversa(s) seriam apagadas (inativas há mais de {$days} dias).");

            return self::SUCCESS;
        }

        // Mensagens saem junto: a FK de messages.conversation_id tem cascadeOnDelete.
        $deleted = $query->delete();

        $this->info("{$deleted} conversa(s) apagada(s) (inativas há mais de {$days} dias).");

        return self::SUCCESS;
    }
}

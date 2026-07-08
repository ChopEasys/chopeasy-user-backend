<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'push:generate-vapid-keys';
    protected $description = 'Generate a new VAPID key pair for web push notifications';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->newLine();
        $this->info('VAPID keys generated successfully!');
        $this->newLine();
        $this->line('Add these to your .env file:');
        $this->newLine();
        $this->line("VAPID_PUBLIC_KEY={$keys['publicKey']}");
        $this->line("VAPID_PRIVATE_KEY={$keys['privateKey']}");
        $this->newLine();
        $this->warn('Keep the private key secure and never commit it to version control.');

        return self::SUCCESS;
    }
}

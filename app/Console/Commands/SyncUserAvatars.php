<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserMatrixToken;
use App\Services\MatrixService;
use Illuminate\Console\Command;

class SyncUserAvatars extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'matrix:sync-avatars';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync avatar_url for all users from Matrix profile';

    protected MatrixService $matrixService;

    public function __construct(MatrixService $matrixService)
    {
        parent::__construct();
        $this->matrixService = $matrixService;
    }

    public function handle()
    {
        $users = User::all();
        if ($users->isEmpty()) {
            $this->warn('No users found.');
            return;
        }

        $bar = $this->output->createProgressBar(count($users));
        $bar->start();

        foreach ($users as $user) {
            $matrixToken = UserMatrixToken::where('user_id', $user->id)->value('access_token');
            if (!$matrixToken) {
                $this->newLine();
                $this->warn("User {$user->name}: no matrix token, skipping");
                $bar->advance();
                continue;
            }

            $serverDomain = $this->matrixService->getServerDomain();
            $matrixUserId = '@' . $user->name . ':' . $serverDomain;
            $profile = $this->matrixService->getUserProfile($matrixUserId, $matrixToken);

            if ($profile && isset($profile['avatar_url'])) {
                $user->avatar_url = $profile['avatar_url'];
                $user->save();
                $this->line(" Updated {$user->name}: {$profile['avatar_url']}");
            } else {
                $this->line(" No avatar for {$user->name}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Avatar sync completed.');
    }
}
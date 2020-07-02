<?php

namespace Anomaly\Streams\Platform\Addon\Console;

use Illuminate\Console\Command;
use Anomaly\Streams\Platform\Addon\AddonManager;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Class AddonMigrate
 *
 * @link   http://pyrocms.com/
 * @author Ryan Thompson <ryan@pyrocms.com>
 */
class AddonMigrate extends Command
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'addon:migrate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate an addon.';

    /**
     * Execute the console command.
     *
     * @param AddonManager $manager
     */
    public function handle(AddonManager $manager)
    {
        $addon = app($this->argument('addon'));

        // php artisan migrate --path=TheAddonPath

        $this->info('Addon [' . $this->argument('addon') . '] was migrated.');
    }

    /**
     * Get the command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['addon', InputArgument::REQUIRED, 'The addon to migrate.'],
        ];
    }
}
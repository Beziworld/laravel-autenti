<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Console;

use Beziworld\Autenti\AutentiManager;
use Beziworld\Autenti\Exceptions\AutentiException;
use Illuminate\Console\Command;

/**
 * Lists, registers and deletes the callbacks of the organisation.
 *
 * Managing callbacks requires the notification_management scope, available
 * to the super administrator of the organisation.
 */
final class CallbacksCommand extends Command
{
    /** @var string */
    protected $signature = 'autenti:callbacks
        {--connection= : Connection name from config/autenti.php}
        {--register : Register a callback (the package route unless --url is given)}
        {--url= : Callback URL to register}
        {--delete= : Identifier of the callback to delete}';

    /** @var string */
    protected $description = 'List, register or delete Autenti callbacks of the organisation';

    public function handle(AutentiManager $manager): int
    {
        $connection = $this->input->getOption('connection');
        $url = $this->input->getOption('url');
        $delete = $this->input->getOption('delete');

        try {
            $client = $manager->connection(is_string($connection) && $connection !== '' ? $connection : null);

            if (is_string($delete) && $delete !== '') {
                $client->deleteCallback($delete);
                $this->info('Deleted callback ' . $delete . '.');
            } elseif ($this->input->getOption('register') === true || (is_string($url) && $url !== '')) {
                $callback = $client->registerCallback(is_string($url) && $url !== '' ? $url : null);
                $this->info(sprintf('Registered callback %s for %s.', $callback->id, $callback->url ?? '-'));
            }

            $rows = [];

            foreach ($client->callbacks() as $callback) {
                $rows[] = [$callback->id, $callback->adapterId, $callback->url ?? '-'];
            }

            $this->table(['Id', 'Adapter', 'URL'], $rows);
        } catch (AutentiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

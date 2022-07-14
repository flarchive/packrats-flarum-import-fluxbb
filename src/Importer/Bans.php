<?php

namespace Packrats\ImportFluxBB\Importer;

use Illuminate\Database\ConnectionInterface;
use PDO;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class Bans
{
    /**
     * @var ConnectionInterface
     */
    private $database;
    /**
     * @var PDO
     */
    private $fluxBBDatabase;
    /**
     * @var string
     */
    private $fluxBBPrefix;

    public function __construct(ConnectionInterface $database)
    {
        $this->database = $database;
    }

    public function execute(OutputInterface $output, PDO $fluxBBDatabase, string $fluxBBPrefix)
    {
        $this->fluxBBDatabase = $fluxBBDatabase;
        $this->fluxBBPrefix = $fluxBBPrefix;
        $output->writeln('Importing bans...');

        $fields = [
            'id',
            'username',
            'ip',
            'email',
            'message',
            'expire',
            'ban_creator'
        ];
        $sql = sprintf(
            "SELECT %s FROM %s WHERE `username` IS NOT NULL OR `email` IS NOT NULL ORDER BY`id`",
            implode(', ', $fields),
            $this->fluxBBPrefix .'bans'
        );
        $stmt = $this->fluxBBDatabase->query($sql);
        $bans = $stmt->fetchAll(PDO::FETCH_OBJ);

        $progressBar = new ProgressBar($output, count($bans));

        foreach ($bans as $ban) {
            $table = $this->database
                ->table('users');
            if ($ban->username) {
                $table = $table->where('nickname', '=', $ban->username, 'or');
            }
            if ($ban->email) {
                $table = $table->where('email', '=', $ban->email, 'or');
            }
            $table->update(
                [
                    'suspended_until' => (new \DateTime())->setTimestamp($ban->expire ?? strtotime('+1 years'))
                ]
            );
            $progressBar->advance();
        }
        $progressBar->finish();

        $output->writeln('');
    }
}
